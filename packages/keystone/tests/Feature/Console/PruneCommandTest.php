<?php

// Unpublished command: what keystone:prune deletes, and the schedule core gives it, which no app changes.

use ClaudioDekker\Keystone\Models\UserKnownDevice;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('deletes the known devices no account signed in from for the retention, and keeps the rest', function () {
    config(['keystone.retention.known_devices_seconds' => 17 * 24 * 60 * 60]);
    $stale = UserKnownDevice::factory()->create(['last_seen_at' => now()->subSeconds(17 * 24 * 60 * 60)]);
    $recent = UserKnownDevice::factory()->create(['last_seen_at' => now()->subSeconds(17 * 24 * 60 * 60 - 1)]);

    $this->artisan('keystone:prune')->assertSuccessful();

    $this->assertModelMissing($stale);
    $this->assertModelExists($recent);
});

it('runs every night on one server, with nothing for the app to register', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command, 'keystone:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->onOneServer)->toBeTrue();
});
