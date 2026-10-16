<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use Illuminate\Support\Collection;
use PhpParser\Node;

/**
 * Require a blank line after a statement that spans several lines.
 */
class BlankLineAfterMultilineStatements extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Add a blank line after the statement that spans several lines.';
    }

    /**
     * Get the first lines of the statements that follow a multiline statement without a blank line.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $blocks = collect($file->find(fn (Node $node) => $this->hasStatements($node)))
            ->map(fn (Node $node) => $node->stmts)
            ->prepend($file->statements());

        return $blocks
            ->flatMap(fn (array $statements) => $this->crowded($statements))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Determine if the node holds a block of statements.
     */
    protected function hasStatements(Node $node): bool
    {
        return in_array('stmts', $node->getSubNodeNames(), true) && is_array($node->stmts);
    }

    /**
     * Get the first lines of the statements that directly follow a multiline statement.
     *
     * @param  array<Node>  $statements
     * @return list<int>
     */
    protected function crowded(array $statements): array
    {
        return collect($statements)
            ->sliding(2)
            ->filter(fn (Collection $pair) => $this->isCrowded($pair->first(), $pair->last()))
            ->map(fn (Collection $pair) => $this->firstLine($pair->last()))
            ->values()
            ->all();
    }

    /**
     * Determine if the statement spans lines and the next one starts on the line after it.
     */
    protected function isCrowded(Node $statement, Node $next): bool
    {
        return $statement->getEndLine() > $statement->getStartLine()
            && $this->firstLine($next) === $statement->getEndLine() + 1;
    }

    /**
     * Get the line where the statement starts, counting the comment above it.
     */
    protected function firstLine(Node $statement): int
    {
        $comments = $statement->getComments();

        return $comments === [] ? $statement->getStartLine() : $comments[0]->getStartLine();
    }
}
