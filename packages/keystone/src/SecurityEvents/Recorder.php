<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Record every security event, called inline from the code that made it happen.
 *
 * @internal
 */
class Recorder
{
    /**
     * Create the recorder.
     */
    public function __construct(protected SessionState $session)
    {
        //
    }

    /**
     * Record an event of the type for the account, or anonymously.
     */
    public function record(
        SecurityEventType $type,
        ?KeystoneUser $account,
        ?Surface $surface = null,
        ?CredentialType $credentialType = null,
        int|string|null $credential = null,
    ): void {
        $entry = $this->makeEntry($type, $account, $surface, $credentialType, $credential);

        $entry->saveQuietly();
    }

    /**
     * Build the entry with every fixed field.
     */
    protected function makeEntry(
        SecurityEventType $type,
        ?KeystoneUser $account,
        ?Surface $surface,
        ?CredentialType $credentialType,
        int|string|null $credential,
    ): UserSecurityEvent {
        return (new UserSecurityEvent)->forceFill([
            'id' => strtolower((string) Str::ulid()),
            'occurred_at' => Date::now()->toImmutable(),
            'type' => $type,
            'user_id' => $account?->getAuthIdentifier(),
            'actor' => Actor::USER,
            'flow' => $this->session->flow($surface),
            'credential_type' => $credentialType?->name(),
            'credential_id' => $credential,
            'credential_label' => null,
            'reason' => null,
            'ip_address' => null,
            'location' => null,
            'user_agent' => null,
            'known_device' => null,
            'request_id' => null,
        ]);
    }
}
