<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
enum Actor: string
{
    case USER = 'user';
    case OPERATOR = 'operator';
    case SYSTEM = 'system';
}
