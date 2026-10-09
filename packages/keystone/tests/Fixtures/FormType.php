<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Initiation;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Str;

/**
 * A credential type of the form shape: the account holds a hashed secret and proves it by typing it, and enrolls one by typing back the code its ceremony shows.
 */
class FormType implements CredentialType
{
    /**
     * The hash the fake compares against when the subject holds no credential.
     */
    protected const string DUMMY_HASH = 'dummy';

    /**
     * Create a new form type instance.
     *
     * @param  list<value-of<Surface>>  $surfaces
     * @param  list<string>  $configFailures
     */
    public function __construct(
        protected string $name = 'form',
        protected array $surfaces = ['sign-in'],
        protected bool $multipleFactors = false,
        protected bool $sharesFailedAttempts = false,
        protected array $configFailures = [],
        protected bool $replacesExisting = false,
    ) {
        //
    }

    public function name(): string
    {
        return $this->name;
    }

    public function surfaces(): array
    {
        return array_fill_keys($this->surfaces, InitiateShape::FORM);
    }

    public function representsMultipleFactors(): bool
    {
        return $this->multipleFactors;
    }

    public function sharesFailedAttempts(): bool
    {
        return $this->sharesFailedAttempts;
    }

    public function configFailures(): array
    {
        return $this->configFailures;
    }

    public function rules(Surface $surface): array
    {
        return ['secret' => ['required', 'string']];
    }

    public function initiate(Surface $surface, string $accountName): ?Initiation
    {
        $code = Str::random(16);

        return new Initiation(ceremony: $code, page: ['code' => $code, 'account' => $accountName]);
    }

    public function verify(Surface $surface, array $input, array $credentials, mixed $ceremony = null): Proof
    {
        if ($surface === Surface::ENROLLMENT) {
            if (! is_string($ceremony) || ! hash_equals($ceremony, (string) $input['secret'])) {
                return Proof::rejected("{$this->name}.mismatch");
            }

            return Proof::enrolled($this->replacesExisting
                ? EnrolledCredential::replacing(identifier: null, secret: static::hash($ceremony))
                : new EnrolledCredential(identifier: null, secret: static::hash($ceremony)));
        }

        $typed = static::hash($input['secret']);

        foreach ($credentials as $credential) {
            if (hash_equals((string) $credential->secret, $typed)) {
                return Proof::proven($credential);
            }
        }

        hash_equals(self::DUMMY_HASH, $typed);

        return Proof::rejected("{$this->name}.mismatch", $credentials[0] ?? null);
    }

    /**
     * Hash a typed secret the way the type stores it.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
