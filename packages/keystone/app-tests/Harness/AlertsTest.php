<?php

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use PHPUnit\Framework\AssertionFailedError;

it('reads each sent alert with the address it went to', function () {
    NotificationFacade::fake();
    $alert = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }
    };

    NotificationFacade::route('mail', 'ada@example.com')->notify($alert);
    NotificationFacade::route('mail', 'grace@example.com')->notify($alert);

    $alerts = $this->sentAlerts();

    expect($alerts->all())->toBe([
        ['address' => 'ada@example.com', 'alert' => $alert],
        ['address' => 'grace@example.com', 'alert' => $alert],
    ]);
});

it('names the fix when notifications are not faked', function () {
    expect(fn () => $this->sentAlerts())->toThrow(AssertionFailedError::class, 'Notifications are not faked. Call Notification::fake() before the alert is sent.');
});
