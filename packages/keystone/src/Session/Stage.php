<?php

namespace ClaudioDekker\Keystone\Session;

/**
 * @internal
 */
enum Stage: string
{
    case CHALLENGE = 'challenge';
    case ENROLLMENT = 'enrollment';
}
