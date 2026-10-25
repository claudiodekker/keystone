<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PlainExceptionHandler implements ExceptionHandler
{
    /**
     * Report nothing.
     */
    public function report(Throwable $e): void
    {
        //
    }

    /**
     * Determine if the exception should be reported, which none is.
     */
    public function shouldReport(Throwable $e): bool
    {
        return false;
    }

    /**
     * Answer every exception with the same teapot.
     *
     * @param  Request  $request
     */
    public function render($request, Throwable $e): Response
    {
        return response('plain handler', 418);
    }

    /**
     * Render nothing to the console.
     *
     * @param  OutputInterface  $output
     */
    public function renderForConsole($output, Throwable $e): void
    {
        //
    }
}
