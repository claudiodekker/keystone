<?php

namespace ClaudioDekker\Keystone\RecoveryCodes;

use ClaudioDekker\Keystone\Methods\Credential;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StartsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use SensitiveParameter;

/**
 * @internal
 */
class RecoveryCodeType implements CredentialType, StartsCeremony
{
    /**
     * The name core reserves for its recovery codes.
     */
    public const string NAME = 'recovery-code';

    /**
     * The shape of a code Laravel Fortify generated, which matches only as typed.
     */
    protected const string FORTIFY_CODE = '/\A[A-Za-z0-9]{10}-[A-Za-z0-9]{10}\z/';

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return static::NAME;
    }

    /**
     * Get the form shape on the challenge, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if ($surface !== Surface::CHALLENGE) {
            return null;
        }

        return InitiateShape::FORM;
    }

    /**
     * Determine if a proof proves two factors on its own, which a code never does.
     */
    public function isMultiFactor(): bool
    {
        return false;
    }

    /**
     * Get the rules for the code.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array
    {
        return [
            'recovery_code' => ['required', 'string'],
        ];
    }

    /**
     * Prove the code whose digest the typed code matches.
     */
    public function verify(Verification $verification): Proof
    {
        $digests = $this->digests((string) $verification->input['recovery_code']);

        foreach ($verification->credentials as $credential) {
            if ($this->matches($credential, $digests)) {
                return Proof::proven($credential);
            }
        }

        return Proof::rejected();
    }

    /**
     * Stage a new set of codes, kept as they are shown until one is typed back.
     *
     * @return array{codes: list<string>}
     */
    public function start(Surface $surface): array
    {
        $codes = array_map(fn (RecoveryCode $code) => $code->format(), RecoveryCode::generateSet());

        return ['codes' => $codes];
    }

    /**
     * Show the staged codes.
     *
     * @param  array<string, mixed>  $ceremony
     * @return array{codes: list<string>}
     */
    public function page(Surface $surface, array $ceremony, string $account): array
    {
        $codes = array_map(fn (RecoveryCode $code) => $code->format(), $this->staged($ceremony));

        return ['codes' => $codes];
    }

    /**
     * Get the codes the staging kept.
     *
     * @param  array<string, mixed>  $ceremony
     * @return list<RecoveryCode>
     */
    public function staged(array $ceremony): array
    {
        $codes = is_array($ceremony['codes'] ?? null) ? $ceremony['codes'] : [];

        return array_values(array_map(
            fn (string $code) => RecoveryCode::fromInput($code),
            array_filter($codes, is_string(...)),
        ));
    }

    /**
     * Determine if the typed code is one of the staged codes.
     *
     * @param  array<string, mixed>  $ceremony
     */
    public function isStaged(array $ceremony, #[SensitiveParameter] string $input): bool
    {
        $typed = RecoveryCode::fromInput($input)->digest();

        return array_any($this->staged($ceremony), fn (RecoveryCode $code) => hash_equals($code->digest(), $typed));
    }

    /**
     * Get every digest the typed code may be stored under: the code it normalizes to, and a Fortify code exactly as typed.
     *
     * @return list<string>
     */
    protected function digests(#[SensitiveParameter] string $input): array
    {
        $digests = [RecoveryCode::fromInput($input)->digest()];

        if (preg_match(static::FORTIFY_CODE, $input) === 1) {
            $digests[] = hash('sha256', $input);
        }

        return $digests;
    }

    /**
     * Determine if the stored code's digest is one of the given digests.
     *
     * @param  list<string>  $digests
     */
    protected function matches(Credential $credential, array $digests): bool
    {
        return array_any($digests, fn (string $digest) => hash_equals((string) $credential->secret, $digest));
    }
}
