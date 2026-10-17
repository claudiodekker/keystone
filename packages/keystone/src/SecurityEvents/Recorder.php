<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Record every security event, called inline from the code that made it happen.
 *
 * @internal
 */
class Recorder
{
    /**
     * The message of every log line.
     */
    protected const string MESSAGE = 'keystone.security_event';

    /**
     * Create the recorder.
     */
    public function __construct(
        protected Config $config,
        protected Request $request,
        protected SessionState $session,
    ) {
        //
    }

    /**
     * Record the security event, as a log line alone when it is anonymous.
     */
    public function record(
        SecurityEventType $type,
        ?KeystoneUser $account,
        ?Surface $surface = null,
        ?CredentialType $credentialType = null,
        int|string|null $credential = null,
    ): void {
        $entry = $this->makeEntry($type, $account, $surface, $credentialType, $credential);

        $this->log($entry);

        if ($entry->isAnonymous()) {
            return;
        }

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
        $context = RequestContext::of($this->request);

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
            'ip_address' => $context?->ipAddress,
            'location' => null,
            'user_agent' => $context?->userAgent,
            'known_device' => null,
            'request_id' => $context?->requestId,
        ]);
    }

    /**
     * Write the entry's log line.
     */
    protected function log(UserSecurityEvent $entry): void
    {
        $channel = $this->config->get('keystone.log_channel');
        $logger = Log::channel($channel);
        $context = $entry->toLogContext();

        $logger->info(static::MESSAGE, $context);
    }
}
