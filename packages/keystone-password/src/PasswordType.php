<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\AppTests\Support\UseTestSupport;
use ClaudioDekker\Keystone\Methods\Credential;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * A password, hashed with the app's own hasher and checked by the driver of the algorithm that made the hash.
 *
 * @internal
 */
#[UseTestSupport(PasswordSupport::class)]
class PasswordType implements CredentialType
{
    /**
     * The most characters a password may have at sign-in.
     */
    protected const int MAX_LENGTH = 1024;

    /**
     * The Hash driver that checks each algorithm password_get_info() names.
     *
     * @var array<string, string>
     */
    protected const array DRIVERS = [
        'argon2i' => 'argon',
        'argon2id' => 'argon2id',
        'bcrypt' => 'bcrypt',
    ];

    /**
     * Create the type.
     */
    public function __construct(protected Repository $config, protected DummyHash $dummyHash)
    {
        //
    }

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'password';
    }

    /**
     * Get the form shape on every surface but the challenge.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if ($surface === Surface::CHALLENGE) {
            return null;
        }

        return InitiateShape::FORM;
    }

    /**
     * Determine if a proof proves two factors on its own, which a password never does.
     */
    public function isMultiFactor(): bool
    {
        return false;
    }

    /**
     * Get the rules for the password.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array
    {
        return [
            'password' => ['required', 'string', 'max:'.static::MAX_LENGTH],
        ];
    }

    /**
     * Prove the credential whose hash the password matches.
     */
    public function verify(Verification $verification): Proof
    {
        $password = (string) $verification->input['password'];
        $usable = array_filter($verification->credentials, fn (Credential $credential) => $this->driverFor($credential) !== null);

        if ($usable === []) {
            $this->checkDummy($password);

            return Proof::rejected();
        }

        foreach ($usable as $credential) {
            if ($this->matches($password, $credential)) {
                return $this->proven($credential, $password);
            }
        }

        return Proof::rejected();
    }

    /**
     * Get the Hash driver of the algorithm that made the credential's hash, or null when no driver can check it.
     *
     * With its verify option on, a Laravel driver throws on a hash of another algorithm rather than refusing it.
     */
    protected function driverFor(Credential $credential): ?string
    {
        $algorithm = Hash::info((string) $credential->secret)['algoName'];

        return static::DRIVERS[$algorithm] ?? null;
    }

    /**
     * Determine if the password matches the credential's hash, checked by the driver of the hash's own algorithm.
     */
    protected function matches(#[SensitiveParameter] string $password, Credential $credential): bool
    {
        $driver = (string) $this->driverFor($credential);

        return Hash::driver($driver)->check($password, (string) $credential->secret);
    }

    /**
     * Check the password against the dummy hash, so a subject without a usable password costs what a wrong password does.
     */
    protected function checkDummy(#[SensitiveParameter] string $password): void
    {
        Hash::check($password, $this->dummyHash->get());
    }

    /**
     * Prove the credential, carrying a rehash when the app wants one and its hash is not of the app's driver and cost.
     */
    protected function proven(Credential $credential, #[SensitiveParameter] string $password): Proof
    {
        if (! $this->config->boolean('hashing.rehash_on_login', true) || ! Hash::needsRehash((string) $credential->secret)) {
            return Proof::proven($credential);
        }

        return Proof::proven($credential, fn () => Hash::make($password));
    }
}
