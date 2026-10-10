<?php

namespace ClaudioDekker\Keystone\Notifications;

use ClaudioDekker\Keystone\Notifications\Contracts\SecurityEventAlertContract;
use ClaudioDekker\Keystone\SecurityEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * @api
 */
class Welcome extends Notification implements SecurityEventAlertContract, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Create a new welcome mail for the account the account.registered event is about.
     */
    public function __construct(SecurityEvent $event, SecurityEvent ...$others)
    {
        //
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
     * Get the mail representation of the notification, welcoming the user to the app.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) config('app.name');
        $subject = __('keystone::mail.welcome.subject', ['app' => $app]);

        return (new MailMessage)
            ->subject($subject)
            ->view('keystone::mail.welcome', [
                'subject' => $subject,
                'app' => $app,
            ]);
    }
}
