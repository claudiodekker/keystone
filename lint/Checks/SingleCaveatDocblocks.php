<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Token;

/**
 * Refuse a docblock with more than one paragraph between its summary and its tags.
 */
class SingleCaveatDocblocks extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Keep one caveat paragraph after the summary. Fold the rest into it, or move it into a name.';
    }

    /**
     * Get the first line of every docblock's second caveat paragraph.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($file->tokens())
            ->filter(fn (Token $token) => $token->id === T_DOC_COMMENT)
            ->map(fn (Token $token) => $this->secondCaveat($token))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the first line of the docblock's second paragraph after its summary, before its tags, if it has one.
     */
    protected function secondCaveat(Token $token): ?int
    {
        $paragraphs = [];
        $inParagraph = false;

        foreach (explode("\n", $token->text) as $offset => $line) {
            $text = preg_replace('/^\s*(\/\*\*|\*\/|\*)\s?/', '', $line);

            if (str_starts_with($text, '@')) {
                break;
            }

            if (trim($text) === '') {
                $inParagraph = false;

                continue;
            }

            if (! $inParagraph) {
                $paragraphs[] = $token->line + $offset;
                $inParagraph = true;
            }
        }

        return $paragraphs[2] ?? null;
    }
}
