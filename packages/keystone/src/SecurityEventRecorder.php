<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Notifications\Contracts\SecurityEventAlertContract;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @internal
 */
class SecurityEventRecorder
{
    /**
     * The message of every security event's log line.
     */
    public const string LOG_MESSAGE = 'keystone.security_event';

    /**
     * How long an anonymous event is logged only once for its type, IP address and path.
     */
    public const int ANONYMOUS_LOG_SECONDS = 60;

    /**
     * The most characters of a user agent kept.
     */
    public const int USER_AGENT_LENGTH = 512;

    /**
     * The most characters of a reason, a credential label or an operator kept.
     */
    public const int FIELD_LENGTH = 64;

    /**
     * The characters a reason may hold.
     */
    protected const string REASON_PATTERN = '/^[a-z0-9._]+$/';

    /**
     * Record the event: log it, append it to the account's trail, alert its owner and dispatch it, each step rescued on its own.
     *
     * @param  list<string>|null  $recipients  the addresses read before the change that caused the event; read now when null
     */
    public function record(
        SecurityEventType $type,
        (Model&KeystoneUser)|null $account = null,
        Actor $actor = Actor::USER,
        ?string $flow = null,
        ?string $credentialType = null,
        ?StoredCredential $credential = null,
        ?string $reason = null,
        ?string $operator = null,
        ?array $recipients = null,
        bool $alert = true,
        ?bool $knownDevice = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        try {
            $event = $this->entry(
                type: $type,
                account: $account,
                actor: $actor,
                flow: $flow,
                credentialType: $credentialType,
                credential: $credential,
                reason: $reason,
                operator: $operator,
                knownDevice: $knownDevice,
                context: $this->context(),
            );
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $this->publish([$event], $account, $recipients, $alert);
    }

    /**
     * Record one event of the type about the account for each request context, alerting its owner once about all of them.
     *
     * @param  list<RequestContext>  $contexts
     */
    public function recordEach(SecurityEventType $type, Model&KeystoneUser $account, array $contexts, Actor $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        $events = [];

        foreach ($contexts as $context) {
            $this->rescue(function () use (&$events, $type, $account, $actor, $context) {
                $events[] = $this->entry(
                    type: $type,
                    account: $account,
                    actor: $actor,
                    flow: null,
                    credentialType: null,
                    credential: null,
                    reason: null,
                    operator: null,
                    knownDevice: null,
                    context: $context,
                );
            });
        }

        if ($events === []) {
            return;
        }

        $this->publish($events, $account, recipients: null, alert: true);
    }

    /**
     * Log each event and append it to the account's trail, alert the owner once about them all, then dispatch each, every step rescued on its own.
     *
     * @param  non-empty-list<SecurityEvent>  $events
     * @param  list<string>|null  $recipients
     */
    protected function publish(array $events, (Model&KeystoneUser)|null $account, ?array $recipients, bool $alert): void
    {
        foreach ($events as $event) {
            $this->rescue(fn () => $this->log($event));
            $this->rescue(fn () => $this->append($event, $account));
        }

        $this->rescue(fn () => $this->alert($events, $account, $recipients, $alert));

        foreach ($events as $event) {
            $this->rescue(fn () => event(new SecurityEventRecorded($event)));
        }
    }

    /**
     * Determine if recording is on; only a literal false in keystone.events.enabled turns it off.
     */
    protected function enabled(): bool
    {
        return config('keystone.events.enabled') !== false;
    }

    /**
     * Build the entry from the facts and the context of the request that caused it.
     */
    protected function entry(
        SecurityEventType $type,
        (Model&KeystoneUser)|null $account,
        Actor $actor,
        ?string $flow,
        ?string $credentialType,
        ?StoredCredential $credential,
        ?string $reason,
        ?string $operator,
        ?bool $knownDevice,
        RequestContext $context,
    ): SecurityEvent {
        $userAgent = $this->clean($context->userAgent, self::USER_AGENT_LENGTH);
        $label = $this->clean($credential?->label, self::FIELD_LENGTH);
        $keptReason = $this->reason($reason, $credentialType);
        $keptOperator = $this->clean($operator, self::FIELD_LENGTH);

        return new SecurityEvent([
            'occurred_at' => Date::now(),
            'type' => $type,
            'user_id' => $account?->getKey(),
            'actor' => $actor,
            'operator' => $keptOperator,
            'flow' => $flow,
            'credential_type' => $credentialType,
            'credential_id' => $credential?->id,
            'credential_label' => $label,
            'reason' => $keptReason,
            'ip_address' => $context->ipAddress,
            'location' => null,
            'user_agent' => $userAgent,
            'known_device' => $knownDevice,
            'request_id' => $context->requestId,
        ]);
    }

    /**
     * Cut a value taken from input to its length, with control characters and line and paragraph separators replaced by spaces so it can't break a log line.
     */
    protected function clean(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $printable = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', $value);

        return Str::substr($printable, 0, $length);
    }

    /**
     * Keep a reason that is a short code, prefixed by its credential type or by keystone when it names a type.
     */
    protected function reason(?string $reason, ?string $credentialType): ?string
    {
        if ($reason === null) {
            return null;
        }

        $fallback = ($credentialType ?? 'keystone').'.invalid_reason';

        if (Str::length($reason) > self::FIELD_LENGTH) {
            return $fallback;
        }

        $prefixes = $credentialType === null ? [''] : ["{$credentialType}.", 'keystone.'];

        foreach ($prefixes as $prefix) {
            $code = substr($reason, strlen($prefix));

            if (str_starts_with($reason, $prefix) && preg_match(self::REASON_PATTERN, $code) === 1) {
                return $reason;
            }
        }

        return $fallback;
    }

    /**
     * Write the event's log line, logging an anonymous event only once per window.
     */
    protected function log(SecurityEvent $event): void
    {
        if ($event->user_id === null && $this->loggedRecently($event)) {
            return;
        }

        $configured = config('keystone.log_channel');
        $channel = is_string($configured) ? $configured : null;
        $logger = Log::channel($channel);

        $logger->info(self::LOG_MESSAGE, $this->logContext($event));
    }

    /**
     * Determine if an identical anonymous event was logged inside the window, counting it when it wasn't.
     */
    protected function loggedRecently(SecurityEvent $event): bool
    {
        $context = $this->context();
        $identity = implode('|', [$event->type->value, $context->ipAddress, $context->path]);
        $fingerprint = hash('sha256', $identity);

        try {
            return ! Cache::add("keystone:security-event:{$fingerprint}", true, self::ANONYMOUS_LOG_SECONDS);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Get the event's fields as the log line's context.
     *
     * @return array{occurred_at: string, type: string, user_id: int|string|null, actor: string, operator: ?string, flow: ?string, credential_type: ?string, credential_id: ?int, credential_label: ?string, reason: ?string, ip_address: ?string, location: ?string, user_agent: ?string, known_device: ?bool, request_id: ?string}
     */
    protected function logContext(SecurityEvent $event): array
    {
        return [
            'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
            'type' => $event->type->value,
            'user_id' => $event->user_id,
            'actor' => $event->actor->value,
            'operator' => $event->operator,
            'flow' => $event->flow,
            'credential_type' => $event->credential_type,
            'credential_id' => $event->credential_id,
            'credential_label' => $event->credential_label,
            'reason' => $event->reason,
            'ip_address' => $event->ip_address,
            'location' => $event->location,
            'user_agent' => $event->user_agent,
            'known_device' => $event->known_device,
            'request_id' => $event->request_id,
        ];
    }

    /**
     * Append the event to the account's trail; events about nobody are log lines only.
     */
    protected function append(SecurityEvent $event, (Model&KeystoneUser)|null $account): void
    {
        if ($account === null) {
            return;
        }

        $connection = $account->getConnection()->getName();

        $event->setConnection($connection);

        $event->saveQuietly();
    }

    /**
     * Queue the type's one alert about the events to each of the account's recipients, one mail each, unless suppressed, about a known device, or its slot is null.
     *
     * @param  non-empty-list<SecurityEvent>  $events
     * @param  list<string>|null  $recipients
     */
    protected function alert(array $events, (Model&KeystoneUser)|null $account, ?array $recipients, bool $alert): void
    {
        $slot = $this->slot($events[0]->type);

        if (! $alert || $events[0]->known_device === true || $account === null || $slot === null) {
            return;
        }

        $recipients ??= (new Addresses($account))->recipientsOf($account);
        $notification = new $slot(...$events);

        foreach ($recipients as $address) {
            $this->rescue(fn () => (new AnonymousNotifiable)->route('mail', $address)->notify($notification));
        }
    }

    /**
     * Get the notification class the type's slot names, or null when the type is silenced.
     *
     * @return class-string<SecurityEventAlertContract>|null
     */
    protected function slot(SecurityEventType $type): ?string
    {
        $slots = config('keystone.notifications');
        $slot = is_array($slots) ? ($slots[$type->value] ?? null) : null;

        return is_string($slot) && is_a($slot, Notification::class, true) && is_a($slot, SecurityEventAlertContract::class, true) ? $slot : null;
    }

    /**
     * Run the step, reporting its failure instead of letting it escape.
     *
     * @param  Closure(): mixed  $step
     */
    protected function rescue(Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Get the context the current request captured.
     */
    protected function context(): RequestContext
    {
        return app(RequestContext::class);
    }
}
