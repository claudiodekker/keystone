<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Token;

/**
 * Refuse a docblock in a test case, because the test's name says what it does.
 */
class NoDocblocksInTests extends Check
{
    /**
     * The paths the check reads, as fnmatch patterns from the repository root.
     *
     * @var list<string>
     */
    protected array $paths = ['*Test.php'];

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return "Remove the docblock; the test's name says what it does.";
    }

    /**
     * Get the lines that open a docblock.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($file->tokens())
            ->filter(fn (Token $token) => $token->id === T_DOC_COMMENT)
            ->map(fn (Token $token) => $token->line)
            ->values()
            ->all();
    }
}
