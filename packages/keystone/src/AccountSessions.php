<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Date;
use stdClass;

/**
 * @internal
 */
class AccountSessions
{
    /**
     * The most sessions listed, this one included.
     */
    public const int LISTED = 50;

    /**
     * The purpose a session handle is hashed for.
     */
    public const string HANDLE_PURPOSE = 'keystone.session-handle';

    /**
     * Create a new account sessions instance.
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected int $lifetimeMinutes,
        protected Store $session,
        protected RequestContext $context,
        protected Model&KeystoneUser $account,
        protected string $loginKey,
        protected string $epochKey,
        protected string $rememberKey,
    ) {
        //
    }

    /**
     * Get the account's live sessions, this one first, then the others by the most recently active.
     *
     * @return list<ListedSession>
     */
    public function live(): array
    {
        $others = $this->candidates()
            ->orderByDesc('last_activity')
            ->limit(self::LISTED - 1)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (stdClass $row) => $this->listed($row))
            ->filter()
            ->values()
            ->all();

        return [$this->current(), ...$others];
    }

    /**
     * Find the live session the handle names.
     */
    public function find(string $handle): ?ListedSession
    {
        return collect($this->live())->first(fn (ListedSession $session) => hash_equals($session->handle, $handle));
    }

    /**
     * Get the context of the request that last wrote the session, under this request's path and id.
     */
    public function contextOf(ListedSession $session): RequestContext
    {
        return new RequestContext(
            ipAddress: $session->ipAddress,
            userAgent: $session->userAgent,
            path: $this->context->path,
            requestId: $this->context->requestId,
        );
    }

    /**
     * Determine if the account may be signed in on a session other than this one, active within the session lifetime and since the last epoch move.
     */
    public function mayHaveOthers(): bool
    {
        return $this->candidates()->exists();
    }

    /**
     * Delete the row of the listed session.
     */
    public function delete(ListedSession $session): void
    {
        $id = $this->others()->pluck('id')->first(fn (string $id) => hash_equals($this->handleOf($id), $session->handle));

        if ($id === null) {
            return;
        }

        $this->others()->where('id', $id)->delete();
    }

    /**
     * Delete the row of every session of the account but this one.
     */
    public function deleteOthers(): void
    {
        $this->others()->delete();
    }

    /**
     * Get this session as the request sees it now, never as its row last stored it.
     */
    protected function current(): ListedSession
    {
        return new ListedSession(
            handle: $this->handleOf($this->session->getId()),
            ipAddress: $this->context->ipAddress,
            userAgent: $this->context->userAgent,
            lastActiveAt: Date::now(),
            current: true,
            rememberTokenId: null,
        );
    }

    /**
     * Get the other session the row stores, or null when it is not signed in as the account on the account's credential epoch.
     */
    protected function listed(stdClass $row): ?ListedSession
    {
        $stored = $this->read($row->id);
        $signedInAs = $stored->get($this->loginKey);

        if (is_null($signedInAs) || (string) $signedInAs !== (string) $this->account->getAuthIdentifier()) {
            return null;
        }

        if ($stored->get($this->epochKey) !== (int) $this->account->getRawOriginal('credential_epoch')) {
            return null;
        }

        $rememberTokenId = $stored->get($this->rememberKey);

        return new ListedSession(
            handle: $this->handleOf($row->id),
            ipAddress: $row->ip_address,
            userAgent: $row->user_agent,
            lastActiveAt: Date::createFromTimestamp($row->last_activity),
            current: false,
            rememberTokenId: is_int($rememberTokenId) ? $rememberTokenId : null,
        );
    }

    /**
     * Read the session with the id through a copy of this session's store, which decrypts and unserializes it as the app's driver does.
     */
    protected function read(string $id): Store
    {
        return tap(clone $this->session, function (Store $other) use ($id) {
            // A read through this session's own handler would mark
            // its row as stored, and once its id rotated the new
            // row would never be stored, so we will clone it.
            $other->setHandler(clone $this->session->getHandler());
            $other->flush();
            $other->setId($id);
            $other->start();
        });
    }

    /**
     * Get the handle of the session id.
     */
    protected function handleOf(string $id): string
    {
        return Hmac::make(self::HANDLE_PURPOSE, $id);
    }

    /**
     * Get a query for the rows of the account's other sessions that may be live: active within the session lifetime and since the last epoch move.
     */
    protected function candidates(): Builder
    {
        $idleSince = Date::now()->subMinutes($this->lifetimeMinutes)->getTimestamp();
        $movedAt = $this->account->getRawOriginal('credential_epoch_moved_at');

        return $this->others()
            ->where('last_activity', '>=', $idleSince)
            ->when($movedAt !== null, fn (Builder $query) => $query->where('last_activity', '>=', Date::parse($movedAt)->getTimestamp()));
    }

    /**
     * Get a query for the rows of the account's sessions other than this one.
     */
    protected function others(): Builder
    {
        return $this->connection->table($this->table)
            ->where('user_id', $this->account->getKey())
            ->where('id', '!=', $this->session->getId());
    }
}
