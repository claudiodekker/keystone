<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;

/**
 * A credential type of the form shape: the account holds a hashed secret and proves it by typing it.
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
     */
    public function __construct(
        protected string $name = 'form',
        protected array $surfaces = ['sign-in'],
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

    public function isMultiFactorOnItsOwn(): bool
    {
        return false;
    }

    public function rules(Surface $surface): array
    {
        return ['secret' => ['required', 'string']];
    }

    public function verify(Surface $surface, array $input, array $credentials): Proof
    {
        $typed = static::hash($input['secret']);

        foreach ($credentials as $credential) {
            if (hash_equals((string) $credential->secret, $typed)) {
                return Proof::proven($credential);
            }
        }

        hash_equals(self::DUMMY_HASH, $typed);

        return Proof::rejected('form.mismatch', $credentials[0] ?? null);
    }

    /**
     * Hash a typed secret the way the type stores it.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
