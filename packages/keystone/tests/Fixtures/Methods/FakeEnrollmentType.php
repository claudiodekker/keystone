<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StartsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;

class FakeEnrollmentType extends FakeChallengeType implements StartsCeremony
{
    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'enrollable';
    }

    /**
     * Get the form shape on the challenge and on enrollment, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        return match ($surface) {
            Surface::CHALLENGE, Surface::ENROLLMENT => InitiateShape::FORM,
            default => null,
        };
    }

    /**
     * Start setting up a credential whose secret is the one every arranged credential holds.
     *
     * @return array<string, mixed>
     */
    public function start(Surface $surface): array
    {
        return ['secret' => static::SECRET];
    }

    /**
     * Show nothing of the ceremony.
     *
     * @param  array<string, mixed>  $ceremony
     * @return array<string, mixed>
     */
    public function page(Surface $surface, array $ceremony, string $account): array
    {
        return [];
    }

    /**
     * Enroll the ceremony's secret when it was typed back, and prove a held credential on every other surface.
     */
    public function verify(Verification $verification): Proof
    {
        if ($verification->surface !== Surface::ENROLLMENT) {
            return parent::verify($verification);
        }

        $this->verifications[] = $verification;

        $this->runDuringVerify();

        $secret = (string) ($verification->ceremony['secret'] ?? '');

        if (! $this->compare($secret, (string) $verification->input['secret'])) {
            return Proof::rejected();
        }

        return Proof::enrolled($secret);
    }
}
