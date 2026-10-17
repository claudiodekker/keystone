<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\Credential;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Models\UserCredential;

/**
 * A credential type of the form shape that compares a stored secret, and deliberately not a password.
 */
class FakeFormType implements CredentialType, CredentialTypeSupport
{
    /**
     * The secret every arranged credential holds.
     */
    protected const string SECRET = 'correct horse';

    /**
     * The secret compared in place of a credential when there is none.
     */
    protected const string DECOY = 'decoy horse';

    /**
     * The verifications the type was given, in order.
     *
     * @var list<Verification>
     */
    protected array $verifications = [];

    /**
     * The number of secrets the type compared, real or decoy.
     */
    protected int $comparisons = 0;

    /**
     * The credential every verify claims to prove, whatever it was given.
     */
    protected ?Credential $forged = null;

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'form';
    }

    /**
     * Get the form shape on sign-in, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if ($surface !== Surface::SIGN_IN) {
            return null;
        }

        return InitiateShape::FORM;
    }

    /**
     * Determine if a proof proves two factors on its own, which it doesn't.
     */
    public function isMultiFactorOnItsOwn(): bool
    {
        return false;
    }

    /**
     * Get the rules for the secret.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array
    {
        return [
            'secret' => ['required', 'string'],
        ];
    }

    /**
     * Prove the credential whose secret matches.
     */
    public function verify(Verification $verification): Proof
    {
        $this->verifications[] = $verification;

        if ($this->forged !== null) {
            return Proof::proven($this->forged);
        }

        $submitted = (string) $verification->input['secret'];

        if ($verification->credentials === []) {
            $this->compare(static::DECOY, $submitted);

            return Proof::rejected();
        }

        foreach ($verification->credentials as $credential) {
            if ($this->compare((string) $credential->secret, $submitted)) {
                return Proof::proven($credential);
            }
        }

        return Proof::rejected();
    }

    /**
     * Get the number of secrets the type compared, real or decoy.
     */
    public function comparisons(): int
    {
        return $this->comparisons;
    }

    /**
     * Make every verify claim to prove the given credential, as a broken or hostile type would.
     */
    public function forgeProofOf(UserCredential $credential): void
    {
        $this->forged = $credential->toCredential();
    }

    /**
     * Get the verifications the type was given, in order.
     *
     * @return list<Verification>
     */
    public function verifications(): array
    {
        return $this->verifications;
    }

    /**
     * Get the type this supports, which is itself.
     */
    public function type(): CredentialType
    {
        return $this;
    }

    /**
     * Give the user a credential holding the fixture secret.
     */
    public function arrangeCredential(Surface $surface, KeystoneUser $user): void
    {
        UserCredential::factory()->for($user, 'user')->create([
            'type' => $this->name(),
            'secret' => static::SECRET,
        ]);
    }

    /**
     * Get the fields that prove the arranged credential.
     *
     * @return array<string, mixed>
     */
    public function validProof(Surface $surface, KeystoneUser $user): array
    {
        return ['secret' => static::SECRET];
    }

    /**
     * Get a secret no credential holds.
     *
     * @return array<string, mixed>
     */
    public function rejectedProof(Surface $surface): array
    {
        return ['secret' => 'wrong horse'];
    }

    /**
     * Determine if the two secrets match, counting the comparison.
     */
    protected function compare(string $known, string $submitted): bool
    {
        $this->comparisons++;

        return hash_equals($known, $submitted);
    }
}
