<?php

namespace ClaudioDekker\Keystone\Notifications;

use ClaudioDekker\Keystone\EmailedLinks;
use ClaudioDekker\Keystone\IssuedLink;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * @internal
 */
class EmailedLinkMail extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Create a new emailed link mail instance.
     */
    public function __construct(
        public string $purpose,
        public IssuedLink $link,
    ) {
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
     * Stop retrying once the link the mail carries has expired.
     */
    public function retryUntil(): DateTimeInterface
    {
        return $this->link->expiresAt;
    }

    /**
     * Get the mail representation of the notification, worded by the purpose's translation keys.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = __("keystone::mail.links.{$this->purpose}.subject");

        return (new MailMessage)
            ->subject($subject)
            ->view('keystone::mail.link', [
                'subject' => $subject,
                'purpose' => $this->purpose,
                'url' => $this->link->url,
                'minutes' => intdiv(EmailedLinks::LIFETIME_SECONDS, 60),
            ]);
    }
}
