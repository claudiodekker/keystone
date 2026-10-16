<?php

namespace ClaudioDekker\Keystone\SignIn;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Hold a step's answer until a fixed time has passed since it started, so every refusal takes as long.
 *
 * It measures with the app's clock and waits with Sleep, so under a frozen test clock the work takes
 * no time and the wait moves the clock by exactly the floor. Laravel's Timebox measures with
 * microtime(), which a faked sleep doesn't move.
 *
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
     * The floor holds when the work throws, too, before the exception goes on.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @param  Closure(TResult): bool  $answersEarly
     * @return TResult
     */
    public function hold(Closure $work, Closure $answersEarly): mixed
    {
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
