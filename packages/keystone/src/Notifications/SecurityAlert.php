<?php

namespace ClaudioDekker\Keystone\Notifications;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\IpLocation;
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
class SecurityAlert extends Notification implements SecurityEventAlert, ShouldBeEncrypted, ShouldQueue
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
        SecurityEventType::CREDENTIAL_ADDED,
        SecurityEventType::RECOVERY_CODE_USED,
        SecurityEventType::RECOVERY_CODES_GENERATED,
        SecurityEventType::SESSIONS_TERMINATED,
    ];

    /**
     * The type of the event the alert is about.
     */
    public SecurityEventType $type;

    /**
     * When the event occurred.
     */
    public CarbonImmutable $occurredAt;

    /**
     * The IP address of the request that caused the event.
     */
    public ?string $ipAddress;

    /**
     * The label of the device that caused the event, never its raw user agent.
     */
    public ?string $device;

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
    public function __construct(SecurityEvent $event)
    {
        $this->type = $event->type;
        $this->occurredAt = $event->occurred_at;
        $this->ipAddress = $event->ip_address;
        $this->device = $this->describe($event->user_agent);
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
                'ipAddress' => $this->ipAddress ?? __('keystone::alerts.unknown'),
                'location' => $this->locate(),
                'device' => $this->device ?? __('keystone::alerts.unknown_device'),
                'credential' => $this->credentialType,
                'remainingRecoveryCodes' => $this->remainingRecoveryCodes,
            ]);
    }

    /**
     * Name where the IP address is through the IP-location port, on the worker, so no request waits on the lookup.
     */
    protected function locate(): ?string
    {
        if ($this->ipAddress === null) {
            return null;
        }

        try {
            return app(IpLocation::class)->locate($this->ipAddress);
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
