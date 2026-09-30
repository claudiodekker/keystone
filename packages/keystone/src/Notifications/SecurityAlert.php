<?php

namespace ClaudioDekker\Keystone\Notifications;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\IpLocation;
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
class SecurityAlert extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * How the time the event occurred is shown, always in UTC.
     */
    public const string TIME_FORMAT = 'Y-m-d H:i \U\T\C';

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
     * Create a new notification instance.
     */
    public function __construct(SecurityEvent $event)
    {
        $this->type = $event->type;
        $this->occurredAt = $event->occurred_at;
        $this->ipAddress = $event->ip_address;
        $this->device = $this->describe($event->user_agent);
        $this->credentialType = $event->credential_type;
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
