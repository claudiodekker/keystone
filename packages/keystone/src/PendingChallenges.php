<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

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
     * Forget the challenge, which its sign-in passed.
     */
    public function forget(int $id): void
    {
        $this->query()->where('id', $id)->delete();
    }

    /**
     * Encrypt a display label, keeping a missing one missing.
     */
    protected function encrypt(?string $value): ?string
    {
        return $value === null ? null : Crypt::encryptString($value);
    }

    /**
     * Get a query for the pending challenges table on the model's connection.
     */
    protected function query(): Builder
    {
        return $this->model->getConnection()->table('user_pending_challenges');
    }
}
