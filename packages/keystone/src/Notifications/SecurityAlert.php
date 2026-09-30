<?php

namespace ClaudioDekker\Keystone\Notifications;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\SessionInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
     * Where the IP address is.
     */
    public ?string $location;

    /**
     * The label of the device that caused the event, never its raw user agent.
     */
    public ?string $device;

    /**
     * The credential involved, by its label at the time or else its type.
     */
    public ?string $credential;

    /**
     * Create a new notification instance.
     */
    public function __construct(SecurityEvent $event)
    {
        $this->type = $event->type;
        $this->occurredAt = $event->occurred_at;
        $this->ipAddress = $event->ip_address;
        $this->location = $event->location;
        $this->device = $this->describe($event->user_agent);
        $this->credential = $event->credential_label ?? $event->credential_type;
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
                'location' => $this->location,
                'device' => $this->device ?? __('keystone::alerts.unknown_device'),
                'credential' => $this->credential,
            ]);
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
