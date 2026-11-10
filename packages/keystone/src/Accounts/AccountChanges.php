<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\PendingSignIn;
use Closure;
use Illuminate\Database\Connection;

/**
 * @internal
 */
class AccountChanges
{
    /**
     * Create the account changes.
     */
    public function __construct(
        protected AlertRecipients $alertRecipients,
        protected Connection $connection,
        protected Recorder $recorder,
    ) {
        //
    }

    /**
     * Apply a change to the account while its row is locked, moving its credential epoch when the change ends its sessions, and get what the change returned.
     *
     * The change records its events only once the outermost transaction commits, so one that rolls back records nothing. Their alerts go to the addresses the account held before the change.
     * A change a pending sign-in writes is refused, writing nothing, once the account left the credential epoch it was held on or was barred.
     *
     * @template TResult
     *
     * @param  Closure(AccountChange): TResult  $apply
     * @return TResult
     *
     * @throws AccountChangeRefusedException
     */
    public function change(KeystoneUser $account, Closure $apply, ?PendingSignIn $writer = null): mixed
    {
        [$change, $result] = $this->connection->transaction(function () use ($account, $apply, $writer) {
            $epoch = CredentialEpoch::lock($account->getAuthIdentifier());

            if ($writer !== null && $epoch !== $writer->epoch) {
                throw AccountChangeRefusedException::writerEnded();
            }

            $recipients = $this->alertRecipients->of($account);

            $change = new AccountChange($account, $recipients);

            $result = $apply($change);

            if ($change->movesEpoch()) {
                CredentialEpoch::move($account->getAuthIdentifier());
            }

            return [$change, $result];
        });

        $this->connection->afterCommit(fn () => $change->record($this->recorder));

        return $result;
    }

    /**
     * End every account's sessions at once, for the operator who asked, recording one event without an account once the outermost transaction commits.
     */
    public function endAllSessions(string $operator): void
    {
        CredentialEpoch::moveAll();

        $this->connection->afterCommit(fn () => $this->recorder->record(
            SecurityEventType::SESSIONS_TERMINATED,
            account: null,
            reason: 'keystone.every_account',
            operator: $operator,
        ));
    }
}
