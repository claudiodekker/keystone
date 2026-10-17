<?php

namespace ClaudioDekker\Keystone\AppTests;

use Closure;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake as BaseExceptionHandlerFake;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Fake the exception handler and remember which reports the test asserted.
 *
 * @internal
 */
class ExceptionHandlerFake extends BaseExceptionHandlerFake
{
    /**
     * The reported exceptions the test asserted.
     *
     * @var list<Throwable>
     */
    protected array $asserted = [];

    /**
     * Assert an exception of the given type was reported, and count every match as asserted.
     *
     * @param  (Closure(Throwable): bool)|class-string<Throwable>  $exception
     */
    public function assertReported(Closure|string $exception)
    {
        parent::assertReported($exception);

        foreach ($this->reported as $reported) {
            if ($this->matches($exception, $reported)) {
                $this->asserted[] = $reported;
            }
        }
    }

    /**
     * Assert the test asserted every exception it reported.
     */
    public function assertEveryReportAsserted(): void
    {
        $unasserted = array_filter(
            $this->reported,
            fn (Throwable $reported) => ! in_array($reported, $this->asserted, true),
        );

        $names = implode(', ', array_map(get_class(...), $unasserted));

        Assert::assertEmpty($unasserted, "Reported [{$names}] without asserting it. Assert it with Exceptions::assertReported(), or stop reporting it.");
    }

    /**
     * Determine if the given assertion accepts the reported exception.
     *
     * @param  (Closure(Throwable): bool)|class-string<Throwable>  $exception
     */
    protected function matches(Closure|string $exception, Throwable $reported): bool
    {
        if (is_string($exception)) {
            return $reported::class === $exception;
        }

        return $this->firstClosureParameterType($exception) === $reported::class
            && $exception($reported) === true;
    }
}
