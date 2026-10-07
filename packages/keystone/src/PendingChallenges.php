<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use stdClass;

/**
 * @internal
 */
class PendingChallenges
{
    /**
     * How long a challenge may stay unanswered before it counts as abandoned.
     */
    public const int ABANDONED_AFTER_SECONDS = 420;

    /**
     * The most swept rows one query deletes.
     */
    protected const int DELETE_CHUNK = 1000;

    /**
     * Create a new pending challenges instance on the model's connection.
     */
    public function __construct(
        protected Model $model,
    ) {
        //
    }

    /**
     * Note that a sign-in of the account is held at the challenge, returning the id its pass forgets it by.
     */
    public function open(int|string $accountId, ?string $userAgent, ?string $ipAddress): int
    {
        return $this->query()->insertGetId([
            'user_id' => $accountId,
            'user_agent' => $this->encrypt($userAgent === null ? null : Str::substr($userAgent, 0, SecurityEventRecorder::USER_AGENT_LENGTH)),
            'ip_address' => $this->encrypt($ipAddress),
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
    public function sweep(SecurityEventRecorder $recorder = new SecurityEventRecorder): void
    {
        $abandoned = $this->query()
            ->where('created_at', '<=', Date::now()->subSeconds(self::ABANDONED_AFTER_SECONDS))
            ->orderBy('id')
            ->get();

        if ($abandoned->isEmpty()) {
            return;
        }

        $accounts = $this->accounts($abandoned->pluck('user_id')->unique()->all());

        foreach ($abandoned->groupBy('user_id') as $accountId => $challenges) {
            if (isset($accounts[$accountId])) {
                $recorder->recordEach(SecurityEventType::CHALLENGE_ABANDONED, $accounts[$accountId], $this->contextsOf($challenges), actor: Actor::SYSTEM);
            }

            foreach ($challenges->pluck('id')->chunk(self::DELETE_CHUNK) as $ids) {
                $this->query()->whereIn('id', $ids->all())->delete();
            }
        }
    }

    /**
     * Get the context of the request that held each challenge.
     *
     * @param  Collection<int, stdClass>  $challenges
     * @return list<RequestContext>
     */
    protected function contextsOf(Collection $challenges): array
    {
        return array_values($challenges->map(fn (stdClass $challenge) => new RequestContext(
            ipAddress: $this->decrypt($challenge->ip_address),
            userAgent: $this->decrypt($challenge->user_agent),
        ))->all());
    }

    /**
     * Read the accounts with the ids in one query, keyed by id, bypassing every global scope.
     *
     * @param  array<array-key, mixed>  $ids
     * @return array<array-key, Model&KeystoneUser>
     */
    protected function accounts(array $ids): array
    {
        /** @var array<array-key, Model&KeystoneUser> */
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
