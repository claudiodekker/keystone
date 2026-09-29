<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/**
 * @internal
 */
class PasswordType implements CredentialType
{
    /**
     * The input field holding the typed password.
     */
    public const string FIELD = 'password';

    /**
     * The most bytes of a password bcrypt hashes; it ignores the rest.
     */
    public const int BCRYPT_MAX_BYTES = 72;

    /**
     * The most characters of a new password under any other driver.
     */
    public const int MAX_CHARACTERS = 1024;

    /**
     * The hashing driver that verifies each algorithm a stored hash may use.
     */
    protected const array DRIVERS = [
        'bcrypt' => 'bcrypt',
        'argon2i' => 'argon',
        'argon2id' => 'argon2id',
    ];

    public function name(): string
    {
        return 'password';
    }

    public function surfaces(): array
    {
        return [
            Surface::SIGN_IN->value => InitiateShape::FORM,
            Surface::REGISTRATION->value => InitiateShape::FORM,
            Surface::ENROLLMENT->value => InitiateShape::FORM,
        ];
    }

    public function representsMultipleFactors(): bool
    {
        return false;
    }

    public function rules(Surface $surface): array
    {
        return match ($surface) {
            Surface::SIGN_IN => [self::FIELD => ['required', 'string']],
            default => [self::FIELD => ['required', 'string', $this->lengthCap()]],
        };
    }

    public function verify(Surface $surface, array $input, array $credentials): Proof
    {
        if ($surface !== Surface::SIGN_IN) {
            throw new LogicException("Verifying a password on {$surface->value} isn't built yet.");
        }

        $password = $input[self::FIELD];

        foreach ($credentials as $credential) {
            if ($this->check($password, (string) $credential->secret)) {
                return Proof::proven($credential, updatedSecret: $this->rehash($password, (string) $credential->secret));
            }
        }

        if ($credentials === []) {
            $this->checkDummy($password);
        }

        return Proof::rejected('password.mismatch', $credentials[0] ?? null);
    }

    /**
     * Get the rule capping a new password at what the app's hashing driver takes.
     */
    protected function lengthCap(): Closure|string
    {
        if (config('hashing.driver') !== 'bcrypt') {
            return 'max:'.self::MAX_CHARACTERS;
        }

        $maxBytes = min(self::BCRYPT_MAX_BYTES, config('hashing.bcrypt.limit') ?? self::BCRYPT_MAX_BYTES);

        return function (string $attribute, mixed $value, Closure $fail) use ($maxBytes) {
            if (is_string($value) && strlen($value) > $maxBytes) {
                $fail('validation.max.string')->translate(['max' => $maxBytes]);
            }
        };
    }

    /**
     * Check the password against the hash with the driver of the hash's own algorithm.
     */
    protected function check(#[\SensitiveParameter] string $password, string $hash): bool
    {
        $driver = self::DRIVERS[password_get_info($hash)['algoName']] ?? null;

        if ($driver === null) {
            $this->checkDummy($password);

            return false;
        }

        return Hash::driver($driver)->check($password, $hash);
    }

    /**
     * Check the password against the dummy hash, taking as long as a real check.
     */
    protected function checkDummy(#[\SensitiveParameter] string $password): void
    {
        Hash::check($password, $this->dummyHash());
    }

    /**
     * Get what makes a new hash with the app's driver and cost, when the hash no longer matches them.
     *
     * @return (Closure(): string)|null
     */
    protected function rehash(#[\SensitiveParameter] string $password, string $hash): ?Closure
    {
        if (! config('hashing.rehash_on_login') || ! Hash::needsRehash($hash)) {
            return null;
        }

        return fn () => Hash::make($password);
    }

    /**
     * Get a hash of no one's password, made with the app's driver and cost once per hashing config.
     */
    protected function dummyHash(): string
    {
        $fingerprint = hash('sha256', serialize(config('hashing')));

        return Cache::rememberForever("keystone-password:dummy-hash:{$fingerprint}", fn () => Hash::make(Str::random(40)));
    }
}
