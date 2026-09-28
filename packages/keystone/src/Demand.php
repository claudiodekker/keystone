<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum Demand: string
{
    case SIGN_IN = 'sign-in';
    case REFUSE = 'refuse';
}
