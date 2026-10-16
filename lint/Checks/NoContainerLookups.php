<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\FunctionCalls;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Refuse a container lookup inside a method in src, so services are injected.
 */
class NoContainerLookups extends FunctionCalls
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/src/*.php'];

    /**
     * The refused functions, lowercase.
     *
     * @var list<string>
     */
    protected array $functions = ['app', 'resolve'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Inject the service through the constructor instead.';
    }

    /**
     * Get the lines that look up a service inside a method.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $calls = collect($file->find(fn (Node $node) => $node instanceof Stmt\ClassMethod))
            ->flatMap(fn (Stmt\ClassMethod $method) => (new NodeFinder)->find($method->stmts ?? [], fn (Node $node) => $this->isRefusedCall($node)))
            ->all();

        return $file->lines($calls);
    }
}
