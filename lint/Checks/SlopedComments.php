<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;
use Illuminate\Support\Collection;
use PhpParser\Token;

/**
 * Refuse a comment paragraph of several lines that isn't a slope of three lines, each 2 to 4 characters shorter.
 */
class SlopedComments extends Check
{
    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Reword the comment into a slope: three lines, each 2 to 4 characters shorter than the one above.';
    }

    /**
     * Get the first line of every paragraph that runs over several lines without being a slope.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return $this->paragraphs($file)
            ->reject(fn (array $paragraph) => count($paragraph) === 1 || $this->isSlope($paragraph))
            ->map(fn (array $paragraph) => array_key_first($paragraph))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get the prose paragraphs of the file's comments, each as its text by line number.
     *
     * @return Collection<int, array<int, string>>
     */
    protected function paragraphs(File $file): Collection
    {
        $tokens = collect($file->tokens());

        return $tokens
            ->filter(fn (Token $token) => $token->id === T_DOC_COMMENT)
            ->flatMap(fn (Token $token) => $this->docblockParagraphs($token))
            ->concat($this->inlineComments($tokens));
    }

    /**
     * Get the paragraphs of a docblock's description, after its summary line and before its tags.
     *
     * @return list<array<int, string>>
     */
    protected function docblockParagraphs(Token $token): array
    {
        $paragraphs = [[]];

        foreach (explode("\n", $token->text) as $offset => $line) {
            $text = preg_replace('/^\s*(\/\*\*|\*\/|\*)\s?/', '', $line);

            if (str_starts_with($text, '@')) {
                break;
            }

            if (trim($text) === '') {
                $paragraphs[] = [];

                continue;
            }

            $paragraphs[array_key_last($paragraphs)][$token->line + $offset] = $text;
        }

        return array_slice(array_values(array_filter($paragraphs)), 1);
    }

    /**
     * Get the runs of // comments on consecutive lines.
     *
     * @param  Collection<int, Token>  $tokens
     * @return list<array<int, string>>
     */
    protected function inlineComments(Collection $tokens): array
    {
        $runs = [];
        $previous = null;

        foreach ($tokens as $token) {
            if ($token->id !== T_COMMENT || ! str_starts_with($token->text, '//')) {
                continue;
            }

            if ($previous === null || $token->line !== $previous + 1) {
                $runs[] = [];
            }

            $runs[array_key_last($runs)][$token->line] = trim(substr($token->text, 2));
            $previous = $token->line;
        }

        return $runs;
    }

    /**
     * Determine if the paragraph has three lines, each 2 to 4 characters shorter than the one above.
     *
     * @param  array<int, string>  $paragraph
     */
    protected function isSlope(array $paragraph): bool
    {
        $lengths = array_map(mb_strlen(...), array_values($paragraph));

        return count($lengths) === 3
            && in_array($lengths[0] - $lengths[1], [2, 3, 4], true)
            && in_array($lengths[1] - $lengths[2], [2, 3, 4], true);
    }
}
