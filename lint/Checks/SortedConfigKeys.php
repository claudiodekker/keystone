<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use Illuminate\Support\Collection;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Require the keys of every array in a shipped config file in alphabetical order.
 */
class SortedConfigKeys extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['packages/*/config/*.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Sort the array's keys alphabetically.";
    }

    /**
     * Get the lines of the keys that sort before the key above them.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($file->find(fn (Node $node) => $node instanceof Expr\Array_ && $this->hasOnlyNamedKeys($node)))
            ->flatMap(fn (Expr\Array_ $array) => $this->unsorted($array))
            ->map(fn (Expr\ArrayItem $item) => $item->getStartLine())
            ->values()
            ->all();
    }

    /**
     * Determine if every item of the array has a string key.
     */
    protected function hasOnlyNamedKeys(Expr\Array_ $array): bool
    {
        return $array->items !== []
            && collect($array->items)->every(fn (?Expr\ArrayItem $item) => $item?->key instanceof Scalar\String_);
    }

    /**
     * Get the items whose key sorts before the key of the item above them.
     *
     * @return list<Expr\ArrayItem>
     */
    protected function unsorted(Expr\Array_ $array): array
    {
        return collect($array->items)
            ->sliding(2)
            ->filter(fn (Collection $pair) => strcmp($pair->last()->key->value, $pair->first()->key->value) < 0)
            ->map(fn (Collection $pair) => $pair->last())
            ->values()
            ->all();
    }
}
