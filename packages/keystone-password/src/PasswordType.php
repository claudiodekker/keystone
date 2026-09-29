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
            Hash::check($password, $this->dummyHash());
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

        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && strlen($value) > self::BCRYPT_MAX_BYTES) {
                $fail('validation.max.string')->translate(['max' => self::BCRYPT_MAX_BYTES]);
            }
        };
    }

    /**
     * Check the password against the hash with the driver of the hash's own algorithm.
     *
     * A hash of no known algorithm is checked against the dummy hash instead, so it takes as long as a real one.
     */
    protected function check(#[\SensitiveParameter] string $password, string $hash): bool
    {
        $driver = self::DRIVERS[password_get_info($hash)['algoName']] ?? null;

        if ($driver === null) {
            Hash::check($password, $this->dummyHash());

            return false;
        }

        return Hash::driver($driver)->check($password, $hash);
    }

    /**
     * Hash the password again with the app's driver and cost when the hash no longer matches them.
     */
    protected function rehash(#[\SensitiveParameter] string $password, string $hash): ?string
    {
        return Hash::needsRehash($hash) ? Hash::make($password) : null;
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
