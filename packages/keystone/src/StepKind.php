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
        return match ($this) {
            self::VIEW => 60,
            self::START, self::SUBMIT, self::CHANGE => 10,
        };
    }
}
