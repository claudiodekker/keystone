<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\Checks\Php\Tests\NoHttpInUnitTests;

/**
 * Refuse an HTTP request in a Feature test, because AppTests prove endpoint behaviour.
 */
class NoHttpInFeatureTests extends NoHttpInUnitTests
{
    /**
     * The name of the test folder the rule holds in, at any depth.
     */
    protected string $folder = 'Feature';

    /**
     * Get the one-line description of the rule, shown when the checks are chosen.
     */
    public function description(): string
    {
        return 'No HTTP request in a Feature test, through the test case or Pest\'s Laravel functions.';
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Write it as an AppTest, or allow the file in guardrails.php with the reason it cannot be one.';
    }
}
