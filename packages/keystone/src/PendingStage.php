<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum PendingStage: string
{
    case CHALLENGE = 'challenge';
    case ENROLLMENT = 'enrollment';
}
