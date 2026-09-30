<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum StepKind: string
{
    case VIEW = 'view';
    case START = 'start';
    case SUBMIT = 'submit';
    case CHANGE = 'change';

    /**
     * Get how many requests to a step of this kind one address or account may make a minute.
     */
    public function allowance(): int
    {
        return config()->integer("keystone.rate_limits.requests_per_minute.{$this->value}");
    }
}
