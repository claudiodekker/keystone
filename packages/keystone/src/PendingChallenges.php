<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use stdClass;

/**
 * @internal
 */
class PendingChallenges
{
    /**
     * How long a challenge may stay unanswered before it counts as abandoned.
     */
    protected const int ABANDONED_AFTER_SECONDS = 420;

    /**
     * The most swept rows one query deletes.
     */
    protected const int DELETE_CHUNK_ROWS = 1000;

    /**
     * Create a new pending challenges instance on the model's connection.
     */
    public function __construct(
        protected Model&KeystoneUser $model,
    ) {
        //
    }

    /**
     * Note that a sign-in of the account is held at the challenge, returning the id its pass forgets it by.
     */
    public function open(int|string $accountId, RequestContext $context): int
    {
        return $this->query()->insertGetId([
            'user_id' => $accountId,
            'user_agent' => $this->encrypt($context->userAgent),
            'ip_address' => $this->encrypt($context->ipAddress),
            'created_at' => Date::now(),
        ]);
    }

    /**
     * Determine if the challenge is still pending: neither passed nor swept.
     */
    public function has(int $id): bool
    {
        return $this->query()->where('id', $id)->exists();
    }

    /**
     * Forget the challenge, which its sign-in passed.
     */
    public function forget(int $id): void
    {
        $this->query()->where('id', $id)->delete();
    }

    /**
     * Record every challenge left unanswered for the threshold as abandoned, alert each account's owner once about all of theirs, then forget them.
     */
    public function sweep(): void
    {
        $abandoned = $this->query()
            ->where('created_at', '<=', Date::now()->subSeconds(self::ABANDONED_AFTER_SECONDS))
            ->orderBy('id')
            ->get(['id', 'user_id', 'ip_address', 'user_agent', 'created_at'])
            ->map(fn (stdClass $row) => new StoredChallenge(
                id: (int) $row->id,
                accountId: $row->user_id,
                ipAddress: $this->decrypt(is_null($row->ip_address) ? null : (string) $row->ip_address),
                userAgent: $this->decrypt(is_null($row->user_agent) ? null : (string) $row->user_agent),
                heldAt: CarbonImmutable::parse($row->created_at),
            ));

        if ($abandoned->isEmpty()) {
            return;
        }

        $accounts = $this->accounts($abandoned->pluck('accountId')->unique()->all());

        foreach ($abandoned->groupBy('accountId') as $accountId => $challenges) {
            if (isset($accounts[$accountId])) {
                (new SecurityEventRecorder)->recordEach(SecurityEventType::CHALLENGE_ABANDONED, $accounts[$accountId], $this->contextsOf($challenges), actor: Actor::SYSTEM);
            }

            foreach ($challenges->pluck('id')->chunk(self::DELETE_CHUNK_ROWS) as $ids) {
                $this->query()->whereIn('id', $ids->all())->delete();
            }
        }
    }

    /**
     * Get the context of the request that held each challenge, dated by the hold.
     *
     * @param  Collection<int, StoredChallenge>  $challenges
     * @return Collection<int, RequestContext>
     */
    protected function contextsOf(Collection $challenges): Collection
    {
        return $challenges->map(fn (StoredChallenge $challenge) => new RequestContext(
            ipAddress: $challenge->ipAddress,
            userAgent: $challenge->userAgent,
            occurredAt: $challenge->heldAt,
        ));
    }

    /**
     * Read the accounts with the ids in one query, keyed by id, bypassing every global scope.
     *
     * @param  array<array-key, mixed>  $ids
     * @return array<array-key, Model&KeystoneUser>
     */
    protected function accounts(array $ids): array
    {
        return $this->model->newQueryWithoutScopes()->whereKey($ids)->get()->getDictionary();
    }

    /**
     * Encrypt a user agent or an IP address, keeping a missing one missing.
     */
    protected function encrypt(?string $value): ?string
    {
        return $value === null ? null : Crypt::encryptString($value);
    }

    /**
     * Decrypt a user agent or an IP address, treating one that can't be read as missing.
     */
    protected function decrypt(?string $value): ?string
    {
        return $value === null ? null : rescue(fn () => Crypt::decryptString($value));
    }

    /**
     * Get a query for the pending challenges table on the model's connection.
     */
    protected function query(): Builder
    {
        return $this->model->getConnection()->table('user_pending_challenges');
    }
}
