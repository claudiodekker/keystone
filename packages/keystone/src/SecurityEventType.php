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
}
