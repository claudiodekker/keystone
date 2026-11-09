<?php

namespace ClaudioDekker\Keystone\Notifications;

use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * @api
 */
class SecurityAlert extends Notification
{
    /**
     * The type of the event the alert is about.
     */
    public SecurityEventType $type;

    /**
     * Create the alert about the event.
     */
    public function __construct(UserSecurityEvent $event)
    {
        $this->type = $event->type;
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
            ->view(static::view($this->type));
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
