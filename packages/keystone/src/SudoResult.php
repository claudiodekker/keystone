<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum SudoResult
{
    case REFUSED;
    case CHALLENGE_OWED;
    case GRANTED;
}
