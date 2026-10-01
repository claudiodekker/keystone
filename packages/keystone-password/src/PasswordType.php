<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Initiation;
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
     * The fewest characters a new password may hold, whatever the app sets.
     */
    public const int MIN_LENGTH_FLOOR = 8;

    /**
     * The fewest characters a new password holds by default while the app requires a second factor.
     */
    public const int MIN_LENGTH_WITH_SECOND_FACTOR = 8;

    /**
     * The fewest characters a new password holds by default while a password alone may sign an account in.
     */
    public const int MIN_LENGTH_ALONE = 15;

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'password';
    }

    /**
     * Get the form each surface the type serves shows.
     */
    public function surfaces(): array
    {
        return [
            Surface::SIGN_IN->value => InitiateShape::FORM,
            Surface::REGISTRATION->value => InitiateShape::FORM,
            Surface::ENROLLMENT->value => InitiateShape::FORM,
        ];
    }

    /**
     * Determine if a password proof counts as multiple factors, which it never does.
     */
    public function representsMultipleFactors(): bool
    {
        return false;
    }

    /**
     * Determine if password failures share one count across flows, which they don't: a password is too long to guess.
     */
    public function sharesFailedAttempts(): bool
    {
        return false;
    }

    /**
     * Get what is wrong with the minimum length, which must be null or a whole number of at least the floor.
     */
    public function configFailures(): array
    {
        $minLength = config('keystone-password.min_length');

        if ($minLength === null || (is_int($minLength) && $minLength >= self::MIN_LENGTH_FLOOR)) {
            return [];
        }

        return ['keystone-password.min_length must be null or a whole number of at least '.self::MIN_LENGTH_FLOOR.'.'];
    }

    /**
     * Get the rules for the typed password, asking a new one to be confirmed and to hold between the minimum length and what the hashing driver takes.
     */
    public function rules(Surface $surface): array
    {
        return match ($surface) {
            Surface::SIGN_IN => [self::FIELD => ['required', 'string']],
            default => [self::FIELD => ['required', 'string', 'confirmed', 'min:'.$this->minLength(), $this->lengthCap()]],
        };
    }

    /**
     * Get the fewest characters a new password may hold: the app's own minimum, else the one that follows the second-factor mandate.
     */
    protected function minLength(): int
    {
        $minLength = config('keystone-password.min_length');

        if (is_int($minLength)) {
            return $minLength;
        }

        return config('keystone.require_second_factor') === true ? self::MIN_LENGTH_WITH_SECOND_FACTOR : self::MIN_LENGTH_ALONE;
    }

    /**
     * Start no ceremony: a password form shows nothing but its fields.
     */
    public function initiate(Surface $surface, string $accountName): Initiation
    {
        return new Initiation;
    }

    /**
     * Check the typed password against the subject's password, or against the dummy hash when there is none.
     */
    public function verify(Surface $surface, array $input, array $credentials, mixed $ceremony = null): Proof
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
        $algorithm = HashAlgorithm::of($hash);

        if ($algorithm === null) {
            $this->checkDummy($password);

            return false;
        }

        return Hash::driver($algorithm->driver())->check($password, $hash);
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
