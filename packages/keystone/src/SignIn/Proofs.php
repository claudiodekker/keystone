<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Accounts\Suspension;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Devices\KnownDevices;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\RateLimits\FailedAttempt;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\PendingSignIn;
use ClaudioDekker\Keystone\Session\RememberTokens;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * @internal
 */
class Proofs
{
    /**
     * Create the proofs.
     */
    public function __construct(
        protected AccountChanges $accountChanges,
        protected KnownDevices $devices,
        protected Limiter $limiter,
        protected PendingChallenges $pendingChallenges,
        protected Recorder $recorder,
        protected RememberTokens $rememberTokens,
        protected SessionState $session,
    ) {
        //
    }

    /**
     * Let the type verify the input against the account's credentials of the type, giving the attempt back when the type throws.
     *
     * @param  array<string, mixed>  $input
     */
    public function verify(CredentialType $type, Surface $surface, ?KeystoneUser $account, array $input, FailedAttempt $attempt): Answer
    {
        $credentials = $this->credentials($type, $account);

        $verification = new Verification(
            surface: $surface,
            input: $input,
            credentials: $credentials->map->toCredential()->values()->all(),
            ceremony: $this->session->ceremony($type, $surface),
        );

        try {
            $proof = $type->verify($verification);
        } catch (Throwable $exception) {
            $this->limiter->giveBack($attempt);

            throw $exception;
        }

        return new Answer($type, $surface, $proof, $credentials, $attempt);
    }

    /**
     * Sign in the account the answer proved, once it is admitted, remembering the browser when asked, then write the proof's rehash.
     */
    public function signIn(KeystoneUser $account, Answer $answer, ?PendingSignIn $writer = null, bool $remember = false): SignedIn|Refused
    {
        if (! $this->admit($account, $answer, $writer)) {
            return $this->refuse($account, $answer);
        }

        $knownDevice = $this->devices->signIn($account);
        $redirectTo = $this->session->signIn($account);

        if ($remember) {
            $this->session->keepRememberToken($this->rememberTokens->issue($account));
        }

        $this->pendingChallenges->forget($account);

        $this->recorder->record(
            SecurityEventType::SIGNED_IN,
            account: $account,
            surface: $answer->surface,
            credentialType: $answer->type,
            credential: $answer->credential()?->getKey(),
            knownDevice: $knownDevice,
        );

        $this->rehash($account, $answer);

        return new SignedIn($redirectTo);
    }

    /**
     * Hold the sign-in of the account the answer proved at the stage it owes, once it is admitted, keeping whether to remember the browser, then write the proof's rehash.
     */
    public function hold(KeystoneUser $account, Answer $answer, Stage $stage, bool $remember): Held|Refused
    {
        if (! $this->admit($account, $answer, writer: null)) {
            return $this->refuse($account, $answer);
        }

        $this->session->hold($account, $answer->type, $stage, $remember);

        if ($stage === Stage::CHALLENGE) {
            $this->pendingChallenges->hold($account);
        }

        $this->recorder->record(
            SecurityEventType::SIGN_IN_HELD,
            account: $account,
            surface: $answer->surface,
            credentialType: $answer->type,
            credential: $answer->proof->credential,
            reason: 'keystone.'.$stage->value,
        );

        $this->rehash($account, $answer);

        return new Held($stage);
    }

    /**
     * Move the pending sign-in on to enrollment once the answer proved its second factor and it is admitted, then write the proof's rehash.
     */
    public function passSecondFactor(KeystoneUser $account, Answer $answer, PendingSignIn $pending): Held|Refused
    {
        if (! $this->admit($account, $answer, $pending)) {
            return $this->refuse($account, $answer);
        }

        $this->session->passSecondFactor($pending, $answer->type);

        $this->pendingChallenges->forget($account);

        $this->recorder->record(
            SecurityEventType::SIGN_IN_HELD,
            account: $account,
            surface: $answer->surface,
            credentialType: $answer->type,
            credential: $answer->proof->credential,
            reason: 'keystone.'.Stage::ENROLLMENT->value,
        );

        $this->rehash($account, $answer);

        return new Held(Stage::ENROLLMENT);
    }

    /**
     * Refuse the answer, recording the rejected proof for the account it was given for.
     */
    public function refuse(?KeystoneUser $account, Answer $answer): Refused
    {
        $this->recorder->record(
            SecurityEventType::PROOF_REJECTED,
            account: $account,
            surface: $answer->surface,
            credentialType: $answer->type,
            reason: $answer->proof->reason,
        );

        return new Refused;
    }

    /**
     * Determine if the account the answer proved may go on: it isn't barred and its credential advanced, so the attempt is given back.
     *
     * A barred account's attempt stays counted, so the limit never tells that its credential was right.
     */
    protected function admit(KeystoneUser $account, Answer $answer, ?PendingSignIn $writer): bool
    {
        if (Suspension::isSuspended($account->getAuthIdentifier())) {
            return false;
        }

        if (! $this->advance($account, $answer, $writer)) {
            return false;
        }

        $this->limiter->giveBack($answer->attempt);

        return true;
    }

    /**
     * Advance the proven credential to the secret the proof carries, and determine if the proof still holds.
     */
    protected function advance(KeystoneUser $account, Answer $answer, ?PendingSignIn $writer): bool
    {
        $secret = $answer->proof->advancedSecret();

        if ($secret === null) {
            return true;
        }

        $credential = $answer->credential();

        if ($credential === null) {
            return false;
        }

        try {
            $this->accountChanges->change($account, fn (AccountChange $change) => $change->advance($credential, $secret), $writer);
        } catch (AccountChangeRefusedException) {
            return false;
        }

        return true;
    }

    /**
     * Write the proof's rehash over the credential it proved, reporting a failure without undoing the sign-in.
     */
    protected function rehash(KeystoneUser $account, Answer $answer): void
    {
        $credential = $answer->credential();

        if ($credential === null) {
            return;
        }

        rescue(fn () => $this->writeRehash($account, $answer, $credential));
    }

    /**
     * Make the proof's rehash and write it over the proven credential, unless its secret changed meanwhile.
     */
    protected function writeRehash(KeystoneUser $account, Answer $answer, UserCredential $credential): void
    {
        $secret = $answer->proof->rehashed();

        if ($secret === null) {
            return;
        }

        try {
            $this->accountChanges->change($account, fn (AccountChange $change) => $change->rehash($credential, $secret));
        } catch (AccountChangeRefusedException) {
            //
        }
    }

    /**
     * Get the account's credentials of the type, none when there is no account.
     *
     * @return Collection<int, UserCredential>
     */
    protected function credentials(CredentialType $type, ?KeystoneUser $account): Collection
    {
        if ($account === null) {
            return new Collection;
        }

        return UserCredential::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->where('type', $type->name())
            ->get();
    }
}
