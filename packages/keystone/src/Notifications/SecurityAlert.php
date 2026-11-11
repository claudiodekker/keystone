<?php

namespace ClaudioDekker\Keystone\Notifications;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Contracts\IpLocation;
use ClaudioDekker\Keystone\Contracts\SessionInfo;
use ClaudioDekker\Keystone\Devices\Device;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
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
     * The view of each type's mail, which is every type the alert can be sent about.
     *
     * @var array<string, view-string>
     */
    protected const array VIEWS = [
        'account.suspended' => 'keystone::alerts.account.suspended',
        'account.unsuspended' => 'keystone::alerts.account.unsuspended',
        'credential.added' => 'keystone::alerts.credential.added',
        'recovery_code.used' => 'keystone::alerts.recovery_code.used',
        'sessions.terminated' => 'keystone::alerts.sessions.terminated',
        'signed_in' => 'keystone::alerts.signed_in',
    ];

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
     * The number of recovery codes the account holds after spending one, for an alert about a spent code.
     */
    public ?int $recoveryCodesLeft;

    /**
     * Create the alert about the event, parsing its user agent so the queue never carries it.
     */
    public function __construct(UserSecurityEvent $event)
    {
        $this->type = $event->type;
        $this->occurredAt = $event->occurred_at;
        $this->ipAddress = $event->ip_address;
        $this->device = $this->device($event->user_agent);
        $this->recoveryCodesLeft = $this->recoveryCodesLeft($event);
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
                'device' => $this->deviceLine(),
                'ipAddress' => $this->ipAddressLine(),
                'recoveryCodesLeft' => $this->recoveryCodesLeft,
                'when' => $this->whenLine(),
            ]);
    }

    /**
     * Determine if the alert has a mail for the type, so it can be sent about it.
     */
    public static function hasMail(SecurityEventType $type): bool
    {
        return array_key_exists($type->value, static::VIEWS);
    }

    /**
     * Get the line that says when the event happened, in UTC.
     */
    protected function whenLine(): string
    {
        return Lang::get('keystone::alerts.when', ['time' => $this->occurredAt->utc()->format('Y-m-d H:i')]);
    }

    /**
     * Get the line that names the device, or says it isn't known.
     */
    protected function deviceLine(): string
    {
        $name = $this->device === null
            ? Lang::get('keystone::alerts.unknown-device')
            : Lang::get('keystone::alerts.device-name', ['browser' => $this->device->browser, 'platform' => $this->device->platform]);

        return Lang::get('keystone::alerts.device', ['device' => $name]);
    }

    /**
     * Get the line that names the IP address and where it is.
     */
    protected function ipAddressLine(): ?string
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
     * Get how many recovery codes the event's account holds now, when the event spent one.
     */
    protected function recoveryCodesLeft(UserSecurityEvent $event): ?int
    {
        if ($event->type !== SecurityEventType::RECOVERY_CODE_USED) {
            return null;
        }

        return UserRecoveryCode::query()
            ->where('user_id', $event->user_id)
            ->count();
    }

    /**
     * Get the view of the type's mail.
     *
     * @return view-string
     */
    protected static function view(SecurityEventType $type): string
    {
        return static::VIEWS[$type->value] ?? throw KeystoneMisconfiguredException::noAlertMail($type->value);
    }
}
