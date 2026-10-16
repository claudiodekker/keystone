<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Token;

/**
 * Refuse a block that opens with a blank line.
 */
class NoBlankLineOpeningBlocks extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Remove the blank line that opens the block.';
    }

    /**
     * Get the blank lines that directly follow an opening brace.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        $tokens = $file->tokens();

        return collect($tokens)
            ->filter(fn (Token $token, int $index) => $token->text === '{' && $this->isBlank($tokens[$index + 1] ?? null))
            ->map(fn (Token $token) => $token->line + 1)
            ->values()
            ->all();
    }

    /**
     * Determine if the token is whitespace that holds a blank line.
     */
    protected function isBlank(?Token $token): bool
    {
        return $token?->id === T_WHITESPACE && substr_count($token->text, "\n") > 1;
    }
}
