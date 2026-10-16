<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;

/**
 * Refuse an em dash in any tracked file.
 */
class NoEmDashes extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['*'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Replace the em dash with a comma, a colon, parentheses or a new sentence.';
    }

    /**
     * Get the lines that hold an em dash.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect(explode("\n", $file->contents))
            ->filter(fn (string $line) => str_contains($line, "\u{2014}"))
            ->keys()
            ->map(fn (int $index) => $index + 1)
            ->all();
    }
}
