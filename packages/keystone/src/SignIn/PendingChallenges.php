<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Devices\KnownDevices;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserPendingChallenge;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Database\Eloquent\Collection;
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
        protected KnownDevices $devices,
        protected Recorder $recorder,
        protected Request $request,
        protected SessionState $session,
    ) {
        //
    }

    /**
     * Keep the account's sign-in held at the challenge by the browser, until the challenge is passed, unless the browser is a known device.
     *
     * A browser that held the account's sign-in before keeps the pending challenge of that first hold, so holding again never puts its alert off.
     */
    public function hold(KeystoneUser $account): void
    {
        if ($this->devices->isKnown($account) || $this->hasPendingChallenge($account)) {
            return;
        }

        $context = RequestContext::of($this->request);

        $challenge = UserPendingChallenge::query()->forceCreate([
            'user_id' => $account->getAuthIdentifier(),
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
        ]);

        $this->session->keepPendingChallenge($challenge->getKey());
    }

    /**
     * Forget the pending challenge the browser left for the account once it passed the account's challenge.
     */
    public function pass(KeystoneUser $account): void
    {
        UserPendingChallenge::query()
            ->whereKey($this->session->pendingChallenge())
            ->where('user_id', $account->getAuthIdentifier())
            ->delete();

        $this->session->forgetPendingChallenge();
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
            $this->alert($challenges);

            UserPendingChallenge::query()->whereKey($challenges->modelKeys())->delete();
        }

        return $abandoned->count();
    }

    /**
     * Record each of one account's abandoned challenges and alert its owner once about all of them, unless the account is gone.
     *
     * @param  Collection<int, UserPendingChallenge>  $challenges
     */
    protected function alert(Collection $challenges): void
    {
        $account = $challenges->first()?->user;

        if ($account === null) {
            return;
        }

        $occurrences = $challenges->map(fn (UserPendingChallenge $challenge) => [
            'ip_address' => $challenge->ip_address,
            'occurred_at' => $challenge->created_at,
            'user_agent' => $challenge->user_agent,
        ]);

        $this->recorder->recordEarlier(SecurityEventType::CHALLENGE_ABANDONED, $account, Surface::CHALLENGE, $occurrences->all());
    }

    /**
     * Determine if the browser has left a pending challenge for the account that is still kept.
     */
    protected function hasPendingChallenge(KeystoneUser $account): bool
    {
        return UserPendingChallenge::query()
            ->whereKey($this->session->pendingChallenge())
            ->where('user_id', $account->getAuthIdentifier())
            ->exists();
    }
}
