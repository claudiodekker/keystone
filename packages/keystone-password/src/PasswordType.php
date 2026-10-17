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
     * The most characters a password may have at sign-in, which bounds the cost of an argon2 check.
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
     * Create the type, reading the app's hashing config when it verifies, so a change at runtime takes effect.
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
    public function isMultiFactorOnItsOwn(): bool
    {
        return false;
    }

    /**
     * Get the rules for the password: a string of up to 1024 characters.
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

        if ($verification->credentials === []) {
            $this->checkDummy($password);

            return Proof::rejected();
        }

        foreach ($verification->credentials as $credential) {
            if ($this->matches($password, $credential)) {
                return $this->proven($credential, $password);
            }
        }

        return Proof::rejected();
    }

    /**
     * Determine if the password matches the credential's hash, checked by the driver of the hash's own algorithm.
     *
     *
     * The driver's verify option stays on: a bcrypt hash goes to the bcrypt driver, so HASH_VERIFY never has to be off.
     */
    protected function matches(#[SensitiveParameter] string $password, Credential $credential): bool
    {
        $hash = (string) $credential->secret;
        $driver = static::DRIVERS[Hash::info($hash)['algoName']] ?? null;

        if ($driver === null) {
            $this->checkDummy($password);

            return false;
        }

        return Hash::driver($driver)->check($password, $hash);
    }

    /**
     * Check the password against the dummy hash, so a subject without a usable password costs what a wrong password does.
     */
    protected function checkDummy(#[SensitiveParameter] string $password): void
    {
        Hash::check($password, $this->dummyHash->get());
    }

    /**
     * Prove the credential, carrying a rehash when its hash is not of the app's driver and cost.
     *
     * Only the cheap needsRehash() runs here. Hash::make() waits in the closure until core has signed the account in.
     */
    protected function proven(Credential $credential, #[SensitiveParameter] string $password): Proof
    {
        if (! Hash::needsRehash((string) $credential->secret)) {
            return Proof::proven($credential);
        }

        return Proof::proven($credential, fn () => Hash::make($password));
    }
}
