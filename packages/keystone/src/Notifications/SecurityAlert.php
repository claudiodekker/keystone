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
        'challenge.abandoned' => 'keystone::alerts.challenge.abandoned',
        'credential.added' => 'keystone::alerts.credential.added',
        'device_cookie.reused' => 'keystone::alerts.device_cookie.reused',
        'limit.tripped' => 'keystone::alerts.limit.tripped',
        'recovery_code.used' => 'keystone::alerts.recovery_code.used',
        'sessions.terminated' => 'keystone::alerts.sessions.terminated',
        'signed_in' => 'keystone::alerts.signed_in',
    ];

    /**
     * The most IP addresses and devices an alert names, counting the rest.
     */
    protected const int MOST_NAMED = 5;

    /**
     * The type of the events the alert is about.
     */
    public SecurityEventType $type;

    /**
     * The time the first event occurred at.
     */
    public CarbonImmutable $occurredAt;

    /**
     * The IP addresses of the requests that caused the events, each once, up to the most an alert names.
     *
     * @var array<int, string>
     */
    public array $ipAddresses;

    /**
     * The number of other IP addresses the events came from.
     */
    public int $otherIpAddresses;

    /**
     * The devices the requests' user agents name, each once and null for a user agent that names none, up to the most an alert names.
     *
     * @var array<int, Device|null>
     */
    public array $devices;

    /**
     * The number of other devices the events came from.
     */
    public int $otherDevices;

    /**
     * The number of recovery codes the account holds after spending one, for an alert about a spent code.
     */
    public ?int $recoveryCodesLeft;

    /**
     * The number of events the alert is about, more than one when it sums up several of one type.
     */
    public int $count;

    /**
     * Create the alert about the event and any later ones of its type it sums up, parsing the user agents so the queue never carries them.
     */
    public function __construct(UserSecurityEvent $event, UserSecurityEvent ...$others)
    {
        $events = collect([$event, ...$others]);

        $ipAddresses = $events
            ->map(fn (UserSecurityEvent $event): ?string => $event->ip_address)
            ->filter()
            ->unique()
            ->values();

        $devices = $events
            ->map(fn (UserSecurityEvent $event) => $this->device($event->user_agent))
            ->unique(fn (?Device $device) => $device === null ? null : $device->platform.'|'.$device->browser)
            ->values();

        $this->count = $events->count();
        $this->type = $event->type;
        $this->occurredAt = $event->occurred_at;
        $this->ipAddresses = $ipAddresses->take(static::MOST_NAMED)->all();
        $this->otherIpAddresses = $ipAddresses->count() - count($this->ipAddresses);
        $this->devices = $devices->take(static::MOST_NAMED)->all();
        $this->otherDevices = $devices->count() - count($this->devices);
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
                'count' => $this->count,
                'devices' => $this->deviceLines(),
                'ipAddresses' => $this->ipAddressLines(),
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
     * Get the line that names each device, then the one that counts the others, if any.
     *
     * @return array<int, string>
     */
    protected function deviceLines(): array
    {
        $lines = array_map($this->deviceLine(...), $this->devices);

        if ($this->otherDevices > 0) {
            $lines[] = trans_choice('keystone::alerts.more-devices', $this->otherDevices);
        }

        return $lines;
    }

    /**
     * Get the line that names each IP address, then the one that counts the others, if any.
     *
     * @return array<int, string>
     */
    protected function ipAddressLines(): array
    {
        $lines = array_map($this->ipAddressLine(...), $this->ipAddresses);

        if ($this->otherIpAddresses > 0) {
            $lines[] = trans_choice('keystone::alerts.more-ip-addresses', $this->otherIpAddresses);
        }

        return $lines;
    }

    /**
     * Get the line that says when the first event happened, in UTC.
     */
    protected function whenLine(): string
    {
        return Lang::get('keystone::alerts.when', ['time' => $this->occurredAt->utc()->format('Y-m-d H:i')]);
    }

    /**
     * Get the line that names the device, or says it isn't known.
     */
    protected function deviceLine(?Device $device): string
    {
        $name = $device === null
            ? Lang::get('keystone::alerts.unknown-device')
            : Lang::get('keystone::alerts.device-name', ['browser' => $device->browser, 'platform' => $device->platform]);

        return Lang::get('keystone::alerts.device', ['device' => $name]);
    }

    /**
     * Get the line that names the IP address and where it is.
     */
    protected function ipAddressLine(string $ipAddress): string
    {
        $location = app(IpLocation::class)->locate($ipAddress);

        if ($location === null) {
            return Lang::get('keystone::alerts.ip-address', ['ip_address' => $ipAddress]);
        }

        return Lang::get('keystone::alerts.ip-address-at', ['ip_address' => $ipAddress, 'location' => $location]);
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
