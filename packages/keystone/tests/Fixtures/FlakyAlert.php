<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\SecurityEvent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use RuntimeException;

class FlakyAlert extends Notification
{
    public static array $delivered = [];

    public function __construct(public SecurityEvent $event)
    {
        static::$delivered = [];
    }

    public function via(AnonymousNotifiable $notifiable): array
    {
        $address = $notifiable->routes['mail'];

        if ($address === 'broken@example.com') {
            throw new RuntimeException('Mailer down.');
        }

        static::$delivered[] = $address;

        return [];
    }
}
