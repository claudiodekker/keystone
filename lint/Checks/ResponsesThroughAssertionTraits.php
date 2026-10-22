<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\Check;
use ClaudioDekker\LaravelGuardrails\File;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;

/**
 * Refuse an AppTest that checks a response itself instead of through an assertion the app can override.
 */
class ResponsesThroughAssertionTraits extends Check
{
    /**
     * Get the one-line description of the rule, shown when the checks are chosen.
     */
    public function description(): string
    {
        return 'No assert* method called on anything but the test case in an AppTest.';
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
        return 'Check the response through an assert* method of an assertion trait, so the app can override it.';
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
     * Get a violation at each assert* call on a receiver other than $this, such as $response->assertRedirect().
     *
     * @return list<Violation>
     */
    protected function violations(File $file): array
    {
        $calls = $file->find(fn (Node $node) => $this->isForeignAssertion($node));

        return array_map(fn (MethodCall|NullsafeMethodCall $call) => Violation::at($call->name), $calls);
    }

    /**
     * Determine if the node calls assert* on a receiver other than $this.
     */
    private function isForeignAssertion(Node $node): bool
    {
        return ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->name instanceof Identifier
            && str_starts_with($node->name->toLowerString(), 'assert')
            && ! ($node->var instanceof Variable && $node->var->name === 'this');
    }
}
