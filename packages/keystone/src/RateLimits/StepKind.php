<?php

namespace ClaudioDekker\Keystone\RateLimits;

/**
 * @internal
 */
enum StepKind: string
{
    case CHANGE = 'change';
    case SUBMIT = 'submit';
    case VIEW = 'view';
}
