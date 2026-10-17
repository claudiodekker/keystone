<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Refuse a package file that points at the repository's decision records or tags a test with a spec id.
 */
class NoInternalReferences extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Say what the code does in its own words; packages ship without the decision records and their IDs.';
    }

    /**
     * Get the lines that name a decision record or tag a test with a spec id.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $records = collect(explode("\n", $file->contents))
            ->filter(fn (string $line) => preg_match('/\bADR \d+/', $line) === 1)
            ->keys()
            ->map(fn (int $index) => $index + 1);

        $groups = $file->lines($file->find(fn (Node $node) => $this->isTestGroup($node)));

        return $records
            ->concat($groups)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Determine if the node tags a test with a spec id, such as it(...)->group('SI-3').
     */
    protected function isTestGroup(Node $node): bool
    {
        if (
            ! $node instanceof Expr\MethodCall
            || ! $node->name instanceof Node\Identifier
            || $node->name->toLowerString() !== 'group'
        ) {
            return false;
        }

        $receiver = $node->var;

        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Expr\FuncCall
            && $receiver->name instanceof Node\Name
            && in_array($receiver->name->toLowerString(), ['describe', 'it', 'test'], true)
            && collect($node->getArgs())->contains(fn (Node\Arg $arg) => $this->isSpecId($arg->value));
    }

    /**
     * Determine if the value is a string shaped like a spec id, such as 'SI-3' or 'H2'.
     */
    protected function isSpecId(Expr $value): bool
    {
        return $value instanceof Node\Scalar\String_
            && preg_match('/^[A-Z]+-?\d+$/', $value->value) === 1;
    }
}
