<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\Addresses;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Initiation;
use ClaudioDekker\Keystone\Methods\NamesStatuses;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\RefusesEnrollment;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Status;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/**
 * @internal
 */
class PasswordType implements CredentialType, NamesStatuses, RefusesEnrollment
{
    /**
     * The input field holding the typed password.
     */
    public const string FIELD = 'password';

    /**
     * The input field holding the password the account holds, typed to change it.
     */
    public const string CURRENT_FIELD = 'current_password';

    /**
     * The most bytes of a password bcrypt hashes; it ignores the rest.
     */
    public const int BCRYPT_MAX_BYTES = 72;

    /**
     * The most characters of a typed password at sign-in, and of a new one under any other driver than bcrypt.
     */
    public const int MAX_CHARACTERS = 1024;

    /**
     * The fewest characters an app may ask a new password to have.
     */
    public const int MIN_LENGTH_FLOOR = 8;

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
     * Get what is wrong with the type's configuration: a minimum length under the floor, or context words that aren't a list of words.
     */
    public function configFailures(): array
    {
        $failures = [];

        foreach (['second_factor_required', 'second_factor_optional'] as $mandate) {
            $minLength = config("keystone-password.min_length.{$mandate}");

            if (! is_int($minLength) || $minLength < self::MIN_LENGTH_FLOOR) {
                $failures[] = "keystone-password.min_length.{$mandate} must be a whole number of at least ".self::MIN_LENGTH_FLOOR.'.';
            }
        }

        $words = config('keystone-password.context_words');

        if (! is_array($words) || ! array_is_list($words) || array_filter($words, is_string(...)) !== $words) {
            $failures[] = 'keystone-password.context_words must be a list of words.';
        }

        return $failures;
    }

    /**
     * Get the rules for the typed password, asking a new one to be confirmed, to fit what the hashing driver takes and to be strong enough, and keeping any typed one short enough to refuse before it is hashed.
     */
    public function rules(Surface $surface): array
    {
        return match ($surface) {
            Surface::SIGN_IN => [self::FIELD => ['required', 'string', 'max:'.self::MAX_CHARACTERS]],
            Surface::ENROLLMENT => [self::CURRENT_FIELD => ['nullable', 'string', 'max:'.self::MAX_CHARACTERS], ...$this->newPasswordRules()],
            default => $this->newPasswordRules(),
        };
    }

    /**
     * Start no ceremony: a password form shows nothing but its fields.
     */
    public function initiate(Surface $surface, string $accountName): ?Initiation
    {
        return null;
    }

    /**
     * Check the typed password against the subject's password at sign-in, or set the account's new password from the security settings.
     */
    public function verify(Surface $surface, array $input, array $credentials, mixed $ceremony = null): Proof
    {
        return match ($surface) {
            Surface::SIGN_IN => $this->signIn($input[self::FIELD], $credentials),
            Surface::ENROLLMENT => $this->enroll($input, $credentials),
            default => throw new LogicException("Verifying a password on {$surface->value} isn't built yet."),
        };
    }

    /**
     * Get the status shown once a new password replaced the account's password.
     */
    public function replacedStatus(): Status
    {
        return Status::PASSWORD_CHANGED;
    }

    /**
     * Get the status shown once the account's password was removed.
     */
    public function removedStatus(): Status
    {
        return Status::PASSWORD_REMOVED;
    }

    /**
     * Get why the app can't set a password: it doesn't list passwords on sign-in, so one would never be used.
     */
    public function enrollmentRefusal(CredentialTypes $types): ?string
    {
        if ($types->find($this->name(), Surface::SIGN_IN) !== null) {
            return null;
        }

        return __('keystone-password::messages.unsupported');
    }

    /**
     * Check the typed password against the subject's password, or against the dummy hash when there is none.
     *
     * @param  list<StoredCredential>  $credentials
     */
    protected function signIn(#[\SensitiveParameter] string $password, array $credentials): Proof
    {
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
     * Set the account's first password, or replace the one it holds once the current one is typed.
     *
     * @param  array<string, mixed>  $input
     * @param  list<StoredCredential>  $credentials
     */
    protected function enroll(#[\SensitiveParameter] array $input, array $credentials): Proof
    {
        if ($credentials !== [] && ! $this->checkCurrent($input[self::CURRENT_FIELD] ?? null, $credentials)) {
            return Proof::rejected('password.mismatch', $credentials[0]);
        }

        return Proof::enrolled(EnrolledCredential::replacing(identifier: null, secret: Hash::make($input[self::FIELD])));
    }

    /**
     * Determine if the typed current password is one of the account's passwords, taking as long as a check when nothing was typed.
     *
     * @param  list<StoredCredential>  $credentials
     */
    protected function checkCurrent(#[\SensitiveParameter] ?string $typed, array $credentials): bool
    {
        if ($typed === null) {
            $this->checkDummy('');

            return false;
        }

        return array_any($credentials, fn (StoredCredential $credential) => $this->check($typed, (string) $credential->secret));
    }

    /**
     * Get the rules for a new password: confirmed, fitting what the hashing driver takes, and strong enough.
     *
     * @return array<string, list<mixed>>
     */
    protected function newPasswordRules(): array
    {
        return [self::FIELD => ['bail', 'required', 'string', 'confirmed', $this->lengthCap(), ...$this->strengthRules()]];
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
     * Get the rules that decide whether a new password is strong enough: the app's own when it set them, else Keystone's minimum length and blocklist.
     *
     * @return list<mixed>
     */
    protected function strengthRules(): array
    {
        return PasswordRules::resolve() ?? ['min:'.$this->minLength(), new Blocklist($this->context(), app(BreachedPasswords::class))];
    }

    /**
     * Get the fewest characters a new password may have, which is more when a password may be an account's only factor.
     */
    protected function minLength(): int
    {
        $mandate = config('keystone.require_second_factor') ? 'second_factor_required' : 'second_factor_optional';

        return config()->integer("keystone-password.min_length.{$mandate}");
    }

    /**
     * Get the names a new password may not borrow a word from: the app's name and host, the configured context words, and every address the signed-in account holds.
     *
     * @return list<string>
     */
    protected function context(): array
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = Keystone::guard()->user();
        $url = config('app.url');

        $names = [
            config('app.name'),
            is_string($url) ? parse_url($url, PHP_URL_HOST) : null,
            ...config()->array('keystone-password.context_words'),
            ...($account === null ? [] : $this->localParts($account)),
        ];

        return array_values(array_filter($names, is_string(...)));
    }

    /**
     * Get the part before the @ of every address the account holds, verified or not.
     *
     * @return list<string>
     */
    protected function localParts(Model&KeystoneUser $account): array
    {
        $addresses = (new Addresses($account))->heldBy($account);

        return array_map(fn (string $address) => Str::beforeLast($address, '@'), $addresses);
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
