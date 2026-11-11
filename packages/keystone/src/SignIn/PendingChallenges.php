<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserPendingChallenge;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class PendingChallenges
{
    /**
     * How long a challenge stays unanswered before it counts as abandoned, in seconds.
     */
    protected const int ABANDONED_AFTER_SECONDS = 7 * 60;

    /**
     * Create the pending challenges.
     */
    public function __construct(
        protected Recorder $recorder,
        protected Request $request,
    ) {
        //
    }

    /**
     * Keep the account's sign-in held at the challenge by the browser, until the challenge is passed.
     */
    public function hold(KeystoneUser $account): void
    {
        $context = RequestContext::of($this->request);

        $challenge = new UserPendingChallenge;

        $challenge->forceFill([
            'user_id' => $account->getAuthIdentifier(),
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
        ])->save();
    }

    /**
     * Record every challenge left unanswered for long enough as abandoned, alert each account's owner once about all of its own, and get how many there were.
     */
    public function alertAbandoned(): int
    {
        $abandoned = UserPendingChallenge::query()
            ->with('user')
            ->where('created_at', '<=', Date::now()->subSeconds(static::ABANDONED_AFTER_SECONDS))
            ->oldest()
            ->oldest('id')
            ->get();

        foreach ($abandoned->groupBy('user_id') as $challenges) {
            $account = $challenges->first()->user;

            if ($account !== null) {
                $this->recorder->recordEarlier(
                    SecurityEventType::CHALLENGE_ABANDONED,
                    account: $account,
                    surface: Surface::CHALLENGE,
                    occurrences: $challenges->map(fn (UserPendingChallenge $challenge) => [
                        'ip_address' => $challenge->ip_address,
                        'occurred_at' => $challenge->created_at,
                        'user_agent' => $challenge->user_agent,
                    ])->all(),
                );
            }

            UserPendingChallenge::query()->whereKey($challenges->modelKeys())->delete();
        }

        return $abandoned->count();
    }
}
