<?php

namespace ClaudioDekker\Keystone\Lint;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * A rule the repository holds to.
 */
abstract class Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['*.php'];

    /**
     * The paths the check skips.
     *
     * @var list<string>
     */
    protected array $except = [];

    /**
     * Get the failure message, naming the fix.
     */
    abstract public function message(): string;

    /**
     * Get the lines of the file that break the rule.
     *
     * @return list<int>
     */
    abstract protected function lines(File $file): array;

    /**
     * Get the lines of the file that break the rule, if the check reads the file.
     *
     * @return list<int>
     */
    public function check(File $file): array
    {
        if (! $this->reads($file->path)) {
            return [];
        }

        return $this->lines($file);
    }

    /**
     * Get every break of the rule in the repository, as "path:line message".
     *
     * @return list<string>
     */
    public function run(): array
    {
        return collect(File::paths())
            ->flatMap(fn (string $path) => $this->report($path))
            ->values()
            ->all();
    }

    /**
     * Get the breaks of the rule in the file at the given path, as "path:line message".
     *
     * @return list<string>
     */
    protected function report(string $path): array
    {
        if (! $this->reads($path)) {
            return [];
        }

        return collect($this->lines(File::read($path)))
            ->map(fn (int $line) => "{$path}:{$line} {$this->message()}")
            ->all();
    }

    /**
     * Determine if the check reads the file at the given path.
     */
    protected function reads(string $path): bool
    {
        return $this->matches($this->paths, $path) && ! $this->matches($this->except, $path);
    }

    /**
     * Determine if the node is the $this variable.
     */
    protected function isThis(Node $node): bool
    {
        return $node instanceof Expr\Variable && $node->name === 'this';
    }

    /**
     * Determine if the path matches any of the patterns.
     *
     * @param  list<string>  $patterns
     */
    protected function matches(array $patterns, string $path): bool
    {
        return collect($patterns)->contains(fn (string $pattern) => fnmatch($pattern, $path));
    }
}
