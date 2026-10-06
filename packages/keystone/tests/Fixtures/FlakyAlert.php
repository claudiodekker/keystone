<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Notifications\Contracts\SecurityEventAlertContract;
use ClaudioDekker\Keystone\SecurityEvent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use RuntimeException;

class FlakyAlert extends Notification implements SecurityEventAlertContract
{
    public static array $delivered = [];

    public function __construct(public SecurityEvent $event, SecurityEvent ...$others)
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
