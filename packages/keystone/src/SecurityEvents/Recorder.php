<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Events\SecurityEventRecorded;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @internal
 */
class Recorder
{
    /**
     * The message of every log line.
     */
    protected const string MESSAGE = 'keystone.security_event';

    /**
     * The seconds during which identical anonymous events are logged once.
     */
    protected const int COOLDOWN_SECONDS = 60;

    /**
     * The label of the app key's subkey for cooldown keys.
     */
    protected const string COOLDOWN_PURPOSE = 'keystone.security_event.cooldown';

    /**
     * The most characters a reason may hold.
     */
    protected const int REASON_LENGTH = 64;

    /**
     * Create the recorder.
     */
    public function __construct(
        protected Config $config,
        protected Dispatcher $events,
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
        ?string $reason = null,
    ): void {
        if ($this->config->get('keystone.events.enabled') === false) {
            return;
        }

        $entry = rescue(fn () => $this->makeEntry($type, $account, $surface, $credentialType, $credential, $reason));

        if ($entry === null) {
            return;
        }

        if ($entry->isAnonymous()) {
            $this->logAnonymous($entry);

            return;
        }

        rescue(fn () => $this->log($entry));
        rescue(fn () => $entry->saveQuietly());
        rescue(fn () => $this->events->dispatch(new SecurityEventRecorded($entry)));
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
        ?string $reason,
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
            'reason' => $reason === null ? null : $this->reason($reason, $credentialType),
            'ip_address' => $context?->ipAddress,
            'location' => null,
            'user_agent' => $context?->userAgent,
            'known_device' => null,
            'request_id' => $context?->requestId,
        ]);
    }

    /**
     * Get the reason as stored, bounded to a short prefixed code.
     */
    protected function reason(string $reason, ?CredentialType $credentialType): string
    {
        $prefix = $credentialType?->name() ?? 'keystone';
        $code = Str::chopStart($reason, [$prefix.'.', 'keystone.']);

        if (
            $code !== $reason
            && strlen($reason) <= static::REASON_LENGTH
            && preg_match('/^[a-z0-9._]+\z/', $code) === 1
        ) {
            return $reason;
        }

        return $prefix.'.invalid_reason';
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

    /**
     * Log an anonymous event once per cooldown.
     */
    protected function logAnonymous(UserSecurityEvent $entry): void
    {
        $add = fn () => Cache::add($this->cooldownKey($entry), true, static::COOLDOWN_SECONDS);
        $first = rescue($add, rescue: true);

        if ($first) {
            rescue(fn () => $this->log($entry));
        }
    }

    /**
     * Get the cache key that identical anonymous events share.
     */
    protected function cooldownKey(UserSecurityEvent $entry): string
    {
        $path = RequestContext::of($this->request)?->path;

        $identity = $entry->type->value.'|'.$entry->ip_address.'|'.$path;

        $appKey = (string) $this->config->get('app.key');
        $subkey = hash_hmac('sha256', static::COOLDOWN_PURPOSE, $appKey);

        return 'keystone:security_event:'.hash_hmac('sha256', $identity, $subkey);
    }
}
