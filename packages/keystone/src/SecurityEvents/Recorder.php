<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

use ClaudioDekker\Keystone\Accounts\AlertRecipients;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Events\SecurityEventRecorded;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Notifications\Dispatcher as Notifications;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
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
     * The most characters an operator's name may hold.
     */
    protected const int OPERATOR_LENGTH = 64;

    /**
     * Create the recorder.
     */
    public function __construct(
        protected AlertRecipients $alertRecipients,
        protected Config $config,
        protected Dispatcher $events,
        protected Notifications $notifications,
        protected Request $request,
        protected SessionState $session,
    ) {
        //
    }

    /**
     * Record the security event, as a log line alone when it is anonymous.
     *
     * Naming an operator records the event as theirs rather than the user's.
     * The alert goes to the given recipients, read before the change that caused the event, or to the account's addresses as they are now.
     *
     * @param  list<string>|null  $recipients
     */
    public function record(
        SecurityEventType $type,
        ?KeystoneUser $account,
        ?Surface $surface = null,
        ?CredentialType $credentialType = null,
        int|string|null $credential = null,
        ?string $reason = null,
        ?string $operator = null,
        ?array $recipients = null,
    ): void {
        if ($this->config->get('keystone.events.enabled') === false) {
            return;
        }

        $entry = rescue(fn () => $this->makeEntry($type, $account, $surface, $credentialType, $credential, $reason, $operator));

        if ($entry === null) {
            return;
        }

        if ($account === null) {
            $this->logAnonymous($entry);

            return;
        }

        rescue(fn () => $this->log($entry));
        rescue(fn () => $entry->saveQuietly());
        rescue(fn () => $this->alert($entry, $account, $recipients));
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
        ?string $operator,
    ): UserSecurityEvent {
        $context = RequestContext::of($this->request);

        return (new UserSecurityEvent)->forceFill([
            'id' => strtolower((string) Str::ulid()),
            'occurred_at' => Date::now()->toImmutable(),
            'type' => $type,
            'user_id' => $account?->getAuthIdentifier(),
            'actor' => $operator === null ? Actor::USER : Actor::OPERATOR,
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
            'operator' => $operator === null ? null : $this->operator($operator),
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
     * Get the operator's name as stored, with control characters replaced and bounded.
     */
    protected function operator(string $operator): string
    {
        $valid = mb_scrub($operator, 'UTF-8');
        $printable = preg_replace('/\p{Cc}/u', ' ', $valid) ?? '';

        return mb_substr($printable, 0, static::OPERATOR_LENGTH);
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
     * Mail the entry's alert to each recipient, unless its type's slot is null.
     *
     * @param  list<string>|null  $recipients
     */
    protected function alert(UserSecurityEvent $entry, KeystoneUser $account, ?array $recipients): void
    {
        $slot = $this->config->get('keystone.notifications')[$entry->type->value] ?? null;

        if ($slot === null) {
            return;
        }

        $addresses = $recipients ?? $this->alertRecipients->of($account);
        $notifiables = array_map($this->recipient(...), $addresses);

        $this->notifications->send($notifiables, new $slot($entry));
    }

    /**
     * Get the address as one recipient of an alert.
     */
    protected function recipient(string $address): AnonymousNotifiable
    {
        $notifiable = new AnonymousNotifiable;

        return $notifiable->route('mail', $address);
    }

    /**
     * Log an anonymous event once per cooldown, or every time an operator made it.
     */
    protected function logAnonymous(UserSecurityEvent $entry): void
    {
        if ($entry->actor === Actor::OPERATOR) {
            rescue(fn () => $this->log($entry));

            return;
        }

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
