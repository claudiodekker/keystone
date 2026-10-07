<?php

namespace ClaudioDekker\Keystone\Notifications;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\IpLocation;
use ClaudioDekker\Keystone\Notifications\Contracts\SecurityEventAlertContract;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\SessionInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * @api
 */
class SecurityAlert extends Notification implements SecurityEventAlertContract, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * How the time the event occurred is shown, always in UTC.
     */
    public const string TIME_FORMAT = 'Y-m-d H:i \U\T\C';

    /**
     * The types of security event the alert has a view and translation keys for.
     */
    protected const array TYPES = [
        SecurityEventType::ACCOUNT_SUSPENDED,
        SecurityEventType::ACCOUNT_UNSUSPENDED,
        SecurityEventType::CHALLENGE_ABANDONED,
        SecurityEventType::CREDENTIAL_ADDED,
        SecurityEventType::DEVICE_COOKIE_REUSED,
        SecurityEventType::LIMIT_TRIPPED,
        SecurityEventType::RECOVERY_CODE_USED,
        SecurityEventType::RECOVERY_CODES_GENERATED,
        SecurityEventType::SESSIONS_TERMINATED,
        SecurityEventType::SIGNED_IN,
    ];

    /**
     * The type of the events the alert is about.
     */
    public SecurityEventType $type;

    /**
     * How many events the alert is about.
     */
    public int $count;

    /**
     * When the earliest event occurred.
     */
    public CarbonImmutable $occurredAt;

    /**
     * The distinct IP addresses of the requests that caused the events, with null for an unknown one.
     *
     * @var list<string|null>
     */
    public array $ipAddresses;

    /**
     * The distinct labels of the devices that caused the events, never their raw user agents, with null for an unknown one.
     *
     * @var list<string|null>
     */
    public array $devices;

    /**
     * The type of the credential involved; never its label, which its owner typed.
     */
    public ?string $credentialType;

    /**
     * How many recovery codes the account had left once one was used, for an alert about a used recovery code.
     */
    public ?int $remainingRecoveryCodes;

    /**
     * Create a new notification instance.
     */
    public function __construct(SecurityEvent $event, SecurityEvent ...$others)
    {
        $events = [$event, ...array_values($others)];

        $this->type = $event->type;
        $this->count = count($events);
        $this->occurredAt = min(array_map(fn (SecurityEvent $event) => $event->occurred_at, $events));
        $this->ipAddresses = $this->distinct(array_map(fn (SecurityEvent $event) => $event->ip_address, $events));
        $this->devices = $this->distinct(array_map(fn (SecurityEvent $event) => $this->describe($event->user_agent), $events));
        $this->credentialType = $event->credential_type;
        $this->remainingRecoveryCodes = $this->countRemainingRecoveryCodes($event);
    }

    /**
     * Determine if the alert can say what an event of the type is, so a slot may name it for that type.
     */
    public static function handles(SecurityEventType $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification, saying what happened in the type's own view and translation keys.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = __("keystone::alerts.types.{$this->type->value}.subject");

        return (new MailMessage)
            ->subject($subject)
            ->view('keystone::mail.alert', [
                'subject' => $subject,
                'what' => "keystone::alerts.{$this->type->value}",
                'occurredAt' => $this->occurredAt->utc()->format(self::TIME_FORMAT),
                'count' => $this->count,
                'ipAddress' => $this->listed($this->ipAddresses, unknown: __('keystone::alerts.unknown')),
                'location' => $this->locate(),
                'device' => $this->listed($this->devices, unknown: __('keystone::alerts.unknown_device')),
                'credential' => $this->credentialType,
                'remainingRecoveryCodes' => $this->remainingRecoveryCodes,
            ]);
    }

    /**
     * Get the values without repeats, in the order they first appear.
     *
     * @param  list<string|null>  $values
     * @return list<string|null>
     */
    protected function distinct(array $values): array
    {
        return array_values(array_unique($values));
    }

    /**
     * List the values in one line, naming an unknown one.
     *
     * @param  list<string|null>  $values
     */
    protected function listed(array $values, string $unknown): string
    {
        return implode(', ', array_map(fn (?string $value) => $value ?? $unknown, $values));
    }

    /**
     * Name where the IP address is through the IP-location port, on the worker, so no request waits on the lookup.
     */
    protected function locate(): ?string
    {
        $ipAddress = $this->ipAddresses[0] ?? null;

        if ($ipAddress === null || count($this->ipAddresses) > 1) {
            return null;
        }

        try {
            return app(IpLocation::class)->locate($ipAddress);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Count the recovery codes the account has left when the event is about a used one, in the request, so the count is the one right after.
     */
    protected function countRemainingRecoveryCodes(SecurityEvent $event): ?int
    {
        if ($event->type !== SecurityEventType::RECOVERY_CODE_USED || $event->user_id === null) {
            return null;
        }

        return (new RecoveryCodes($event))->remaining($event->user_id);
    }

    /**
     * Label the device the user agent names through the session-info port.
     */
    protected function describe(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $device = app(SessionInfo::class)->describe($userAgent);

        return $device?->label();
    }
}
