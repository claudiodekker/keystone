<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse an HTTP request in a package's tests/Feature, unless the allowlist names the file and why it can't be an AppTest.
 *
 * Endpoint behaviour is proven by AppTests, which run through the app's own controllers.
 */
class NoHttpInFeatureTests extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/tests/Feature/*.php'];

    /**
     * The Feature tests that may make an HTTP request, each with the reason it can't be an AppTest.
     *
     * @var array<string, string>
     */
    protected array $allowed = [
        //
    ];

    /**
     * The request methods of Laravel's test case, lowercase.
     *
     * @var list<string>
     */
    protected array $requests = [
        'call',
        'delete',
        'deletejson',
        'get',
        'getjson',
        'json',
        'options',
        'optionsjson',
        'patch',
        'patchjson',
        'post',
        'postjson',
        'put',
        'putjson',
    ];

    /**
     * Create the check, skipping every file the allowlist names.
     *
     * @param  array<string, string>|null  $allowed
     */
    public function __construct(?array $allowed = null)
    {
        $this->allowed = $allowed ?? $this->allowed;
        $this->except = array_keys($this->allowed);
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Write it as an AppTest, or allowlist the file in NoHttpInFeatureTests with the reason it cannot be one.';
    }

    /**
     * Get the lines that make a request through the test case or Pest's Laravel functions.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $file->lines($file->find(fn (Node $node) => $this->isRequest($node)));
    }

    /**
     * Determine if the node sends a request, as $this->post() or Pest\Laravel\post() does.
     */
    protected function isRequest(Node $node): bool
    {
        if ($node instanceof Expr\FuncCall) {
            return $node->name instanceof Node\Name
                && str_starts_with($node->name->toLowerString(), 'pest\\laravel\\')
                && in_array(strtolower($node->name->getLast()), $this->requests, true);
        }

        return $node instanceof Expr\MethodCall
            && $this->isThis($node->var)
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toLowerString(), $this->requests, true);
    }
}
