<?php

namespace ClaudioDekker\Keystone\SecurityEvents;

/**
 * @api
 */
enum Actor: string
{
    case OPERATOR = 'operator';
    case SYSTEM = 'system';
    case USER = 'user';
}
