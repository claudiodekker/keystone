<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

/**
 * @api
 */
enum SecurityEventType: string
{
    case ACCOUNT_SUSPENDED = 'account.suspended';
    case ACCOUNT_UNSUSPENDED = 'account.unsuspended';
    case CHALLENGE_ABANDONED = 'challenge.abandoned';
    case CREDENTIAL_ADDED = 'credential.added';
    case DEVICE_COOKIE_REUSED = 'device_cookie.reused';
    case LIMIT_TRIPPED = 'limit.tripped';
    case PROOF_REJECTED = 'proof.rejected';
    case RECOVERY_CODES_GENERATED = 'recovery_codes.generated';
    case RECOVERY_CODE_USED = 'recovery_code.used';
    case REQUEST_REJECTED = 'request.rejected';
    case SESSIONS_TERMINATED = 'sessions.terminated';
    case SESSION_ENDED = 'session.ended';
    case SIGNED_IN = 'signed_in';
    case SIGNED_OUT = 'signed_out';
    case SIGN_IN_HELD = 'sign_in.held';
    case SIGN_IN_VOIDED = 'sign_in.voided';
    case SUDO_FAILED = 'sudo.failed';
    case SUDO_GRANTED = 'sudo.granted';
    case SUDO_NETWORK_CHANGED = 'sudo.network_changed';
    case SUDO_REVOKED = 'sudo.revoked';
}
