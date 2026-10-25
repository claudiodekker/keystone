<?php

namespace ClaudioDekker\Keystone\SignIn;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * @internal
 */
class TimingFloor
{
    /**
     * The least time a held answer takes, in milliseconds.
     */
    protected const int MILLISECONDS = 300;

    /**
     * Run the work, then wait out the rest of the floor unless its result may answer early.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @param  Closure(TResult): bool  $answersEarly
     * @return TResult
     */
    public function hold(Closure $work, Closure $answersEarly): mixed
    {
        // Laravel's Timebox reads microtime(), which a faked sleep never moves. The app's clock and Sleep move together.
        $deadline = Date::now()->addMilliseconds(static::MILLISECONDS);

        try {
            $result = $work();
        } catch (Throwable $exception) {
            Sleep::until($deadline);

            throw $exception;
        }

        if (! $answersEarly($result)) {
            Sleep::until($deadline);
        }

        return $result;
    }
}
