<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

/**
 * @api
 */
enum SecurityEventType: string
{
    case ACCOUNT_SUSPENDED = 'account.suspended';
    case ACCOUNT_UNSUSPENDED = 'account.unsuspended';
    case CREDENTIAL_ADDED = 'credential.added';
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
}
