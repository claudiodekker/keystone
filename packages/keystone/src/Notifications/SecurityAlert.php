<?php

namespace ClaudioDekker\Keystone\Notifications;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Contracts\IpLocation;
use ClaudioDekker\Keystone\Contracts\SessionInfo;
use ClaudioDekker\Keystone\Devices\Device;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * @api
 */
class SecurityAlert extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * The type of the event the alert is about.
     */
    public SecurityEventType $type;

    /**
     * The time the event occurred at.
     */
    public CarbonImmutable $occurredAt;

    /**
     * The IP address of the request that caused the event, if any.
     */
    public ?string $ipAddress;

    /**
     * The device the request's user agent names, if it is known.
     */
    public ?Device $device;

    /**
     * Create the alert about the event, parsing its user agent so the queue never carries it.
     */
    public function __construct(UserSecurityEvent $event)
    {
        $this->type = $event->type;
        $this->occurredAt = $event->occurred_at;
        $this->ipAddress = $event->ip_address;
        $this->device = $this->device($event->user_agent);
    }

    /**
     * Get the channels the alert is sent on.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail that tells the owner what happened.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = new MailMessage;

        return $message
            ->subject(Lang::get("keystone::alerts.{$this->type->value}.subject"))
            ->view(static::view($this->type), [
                'device' => $this->deviceLabel(),
                'ipAddress' => $this->ipAddressLabel(),
                'time' => $this->occurredAt->utc()->format('Y-m-d H:i'),
            ]);
    }

    /**
     * Get the line that names the device, or says it isn't known.
     */
    protected function deviceLabel(): string
    {
        $name = $this->device === null
            ? Lang::get('keystone::alerts.unknown-device')
            : Lang::get('keystone::alerts.device-name', ['browser' => $this->device->browser, 'platform' => $this->device->platform]);

        return Lang::get('keystone::alerts.device', ['device' => $name]);
    }

    /**
     * Get the line that names the IP address and where it is, looked up now, as the mail is built on the queue worker.
     */
    protected function ipAddressLabel(): ?string
    {
        if ($this->ipAddress === null) {
            return null;
        }

        $location = app(IpLocation::class)->locate($this->ipAddress);

        if ($location === null) {
            return Lang::get('keystone::alerts.ip-address', ['ip_address' => $this->ipAddress]);
        }

        return Lang::get('keystone::alerts.ip-address-at', ['ip_address' => $this->ipAddress, 'location' => $location]);
    }

    /**
     * Get the device the user agent names, if it is known.
     */
    protected function device(?string $userAgent): ?Device
    {
        if ($userAgent === null) {
            return null;
        }

        return app(SessionInfo::class)->device($userAgent);
    }

    /**
     * Get the view of the type's mail.
     *
     * @return view-string
     */
    protected static function view(SecurityEventType $type): string
    {
        return match ($type) {
            SecurityEventType::SESSIONS_TERMINATED => 'keystone::alerts.sessions.terminated',
            default => throw KeystoneMisconfiguredException::noAlertMail($type->value),
        };
    }
}
