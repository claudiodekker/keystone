<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

use ClaudioDekker\Keystone\Methods\Surface;

/**
 * @api
 */
interface CredentialTypeSupport
{
    /**
     * Get the name of the credential type this supports.
     */
    public function type(): string;

    /**
     * Arrange a credential usable on the surface, in the form core stores it.
     *
     * @return array{identifier: ?string, secret: ?string, label: ?string}
     */
    public function arrange(Surface $surface): array;

    /**
     * Get input that proves the arranged credential on the surface.
     *
     * @return array<string, mixed>
     */
    public function validProof(Surface $surface): array;

    /**
     * Get input that the arranged credential rejects on the surface.
     *
     * @return array<string, mixed>
     */
    public function rejectedProof(Surface $surface): array;

    /**
     * Get input that completes the type's enrollment ceremony, given what core kept of it in the ceremony slot.
     *
     * @return array<string, mixed>
     */
    public function validEnrollment(mixed $ceremony): array;

    /**
     * Get input that the type's enrollment ceremony rejects, given what core kept of it in the ceremony slot.
     *
     * @return array<string, mixed>
     */
    public function rejectedEnrollment(mixed $ceremony): array;
}
