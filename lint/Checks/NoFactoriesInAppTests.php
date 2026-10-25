<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\Check;
use ClaudioDekker\LaravelGuardrails\File;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Violation;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;

class NoFactoriesInAppTests extends Check
{
    /**
     * Get the one-line description of the rule, shown when the checks are chosen.
     */
    public function description(): string
    {
        return 'No model factory called in an AppTest.';
    }

    /**
     * Get the kinds of code the check reads.
     *
     * @return list<Kind>
     */
    public function kinds(): array
    {
        return [Kind::TESTS];
    }

    /**
     * Get the one-line description of the files the check reads.
     */
    public function scope(): string
    {
        return 'tests in an app-tests folder';
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Create the record through a harness helper, such as createUser() or addVerifiedAddress().';
    }

    /**
     * Get the file name patterns the check reads.
     *
     * @return list<string>
     */
    protected function files(): array
    {
        return ['*Test.php'];
    }

    /**
     * Determine if the file is an AppTest.
     */
    protected function reads(File $file): bool
    {
        return parent::reads($file) && $file->isIn('app-tests');
    }

    /**
     * Get a violation at each call to factory() on a class, such as UserEmail::factory().
     *
     * @return list<Violation>
     */
    protected function violations(File $file): array
    {
        $calls = array_filter($file->findInstanceOf(StaticCall::class), fn (StaticCall $call) => $call->name instanceof Identifier && $call->name->toLowerString() === 'factory');

        return array_map(fn (StaticCall $call) => Violation::at($call->name), array_values($calls));
    }
}
