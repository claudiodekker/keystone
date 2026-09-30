<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
enum SecurityEventType: string
{
    case SIGNED_IN = 'signed_in';
    case PROOF_REJECTED = 'proof.rejected';
    case SIGNED_OUT = 'signed_out';
    case SESSION_ENDED = 'session.ended';
    case LIMIT_TRIPPED = 'limit.tripped';
    case REQUEST_REJECTED = 'request.rejected';
}
