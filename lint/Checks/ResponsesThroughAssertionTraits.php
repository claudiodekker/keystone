<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use Illuminate\Testing\TestResponse;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse an AppTest that checks a response itself instead of through an assertion the app can override.
 */
class ResponsesThroughAssertionTraits extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/app-tests/*Test.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Check the response through an assert* method of an assertion trait, so the app can override it.';
    }

    /**
     * Get the lines that call a response assertion on anything but the test case.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isForeignAssertion($node)));
    }

    /**
     * Determine if the node calls an assertion of Laravel's test response on a receiver other than $this, such as $response->assertRedirect().
     */
    protected function isForeignAssertion(Node $node): bool
    {
        return ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
            && str_starts_with($node->name->toLowerString(), 'assert')
            && method_exists(TestResponse::class, $node->name->toString())
            && ! $this->isThis($node->var);
    }
}
