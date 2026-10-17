<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

/**
 * Who made a security event happen.
 *
 * @api
 */
enum Actor: string
{
    case OPERATOR = 'operator';
    case SYSTEM = 'system';
    case USER = 'user';
}
