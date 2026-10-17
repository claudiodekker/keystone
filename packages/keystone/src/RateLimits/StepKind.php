<?php

namespace ClaudioDekker\Keystone\RateLimits;

/**
 * The kind of step a request limit counts, each with its own allowance a minute under the config key its value names.
 *
 * @internal
 */
enum StepKind: string
{
    case CHANGE = 'change';
    case SUBMIT = 'submit';
    case VIEW = 'view';
}
