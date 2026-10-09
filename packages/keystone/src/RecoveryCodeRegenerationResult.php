<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum RecoveryCodeRegenerationResult
{
    /**
     * The staged set is the account's set now, stored by this request or by an earlier submit of the same set.
     */
    case REGENERATED;

    /**
     * The typed code isn't one of the staged set, or the account is barred; the staged set stays for another try.
     */
    case REFUSED;

    /**
     * The staged set was made on an epoch the account has since moved off, so it was discarded for a fresh one.
     */
    case EXPIRED;

    /**
     * The session can no longer finish: its sudo ended while the code was checked, or it is no longer signed in.
     */
    case SUDO_ENDED;
}
