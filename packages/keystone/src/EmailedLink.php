<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * @internal
 */
interface EmailedLink
{
    /**
     * Get the purpose naming the kind's own subkeys and the mail kind the delivery limit counts it under.
     */
    public static function purpose(): string;

    /**
     * Get the name of the route that opens the kind's show step, whose POST twin spends the link at the same URL.
     */
    public static function route(): string;

    /**
     * Rebuild the link from its decrypted payload, or get null for a payload the kind didn't write.
     *
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): ?static;

    /**
     * Get what the link carries, which is encrypted and never shown in clear.
     *
     * @return array<string, string|int>
     */
    public function payload(): array;

    /**
     * Get the account the link names, which a refused link is recorded against.
     *
     * @return (Model&KeystoneUser)|null
     */
    public function account(KeystoneGuard $guard): ?Model;

    /**
     * Determine if the link's destination still qualifies, before it is mailed and again once it is spent.
     */
    public function destinationHolds(KeystoneGuard $guard): bool;

    /**
     * Act on a spent link whose destination no longer qualifies, such as by alerting whoever holds it now.
     */
    public function destinationLost(KeystoneGuard $guard): void;

    /**
     * Get the mail that delivers the issued link.
     */
    public function mail(IssuedLink $issued): Notification;
}
