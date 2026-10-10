<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
enum SecurityEventType: string
{
    case SIGNED_IN = 'signed_in';
    case SIGN_IN_HELD = 'sign_in.held';
    case SIGN_IN_VOIDED = 'sign_in.voided';
    case ENROLLMENT_COMPLETED = 'enrollment.completed';
    case PROOF_REJECTED = 'proof.rejected';
    case CHALLENGE_ABANDONED = 'challenge.abandoned';
    case SIGNED_OUT = 'signed_out';
    case SESSION_ENDED = 'session.ended';
    case SESSIONS_TERMINATED = 'sessions.terminated';
    case SESSION_REVOKED = 'session.revoked';
    case SESSIONS_REVOKED_OTHERS = 'sessions.revoked_others';
    case LIMIT_TRIPPED = 'limit.tripped';
    case DEVICE_COOKIE_REUSED = 'device_cookie.reused';
    case REQUEST_REJECTED = 'request.rejected';
    case ACCOUNT_SUSPENDED = 'account.suspended';
    case ACCOUNT_UNSUSPENDED = 'account.unsuspended';
    case ACCOUNT_REGISTERED = 'account.registered';
    case ADDRESS_CLAIM_ATTEMPTED = 'address.claim_attempted';
    case ADDRESS_LOST = 'address.lost';
    case RECOVERY_CODE_USED = 'recovery_code.used';
    case RECOVERY_CODES_GENERATED = 'recovery_codes.generated';
    case CREDENTIAL_ADDED = 'credential.added';
    case CREDENTIAL_REMOVED = 'credential.removed';
    case CREDENTIAL_REPLACED = 'credential.replaced';
    case SUDO_GRANTED = 'sudo.granted';
    case SUDO_FAILED = 'sudo.failed';
    case SUDO_REVOKED = 'sudo.revoked';
    case SUDO_NETWORK_CHANGED = 'sudo.network_changed';
}
