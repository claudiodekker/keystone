<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

/**
 * The security event types.
 *
 * @api
 */
enum SecurityEventType: string
{
    case LIMIT_TRIPPED = 'limit.tripped';
    case PROOF_REJECTED = 'proof.rejected';
    case REQUEST_REJECTED = 'request.rejected';
    case SIGNED_IN = 'signed_in';
    case SIGNED_OUT = 'signed_out';
}
