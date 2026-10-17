<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use Illuminate\Database\Eloquent\Collection;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/**
 * Read what Keystone recorded during a test: the stored events and the security event log lines.
 *
 * @api
 */
class RecordedSecurityEvents
{
    /**
     * The message of every security event log line.
     */
    protected const string MESSAGE = 'keystone.security_event';

    /**
     * Create the reader over the handler that kept the security log channel's lines.
     */
    public function __construct(protected TestHandler $handler)
    {
        //
    }

    /**
     * Get every stored event, oldest first.
     *
     * @return Collection<int, UserSecurityEvent>
     */
    public function stored(): Collection
    {
        return UserSecurityEvent::query()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the events stored for the user, oldest first.
     *
     * @return Collection<int, UserSecurityEvent>
     */
    public function for(KeystoneUser $user): Collection
    {
        return UserSecurityEvent::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the context of every security event log line written during the test, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function logged(): array
    {
        return collect($this->handler->getRecords())
            ->filter(fn (LogRecord $record) => $record->message === static::MESSAGE)
            ->map(fn (LogRecord $record) => $record->context)
            ->values()
            ->all();
    }
}
