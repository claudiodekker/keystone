<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;

/**
 * Refuse an inline comment or config banner paragraph of several lines that isn't a slope of three lines, each 2 to 4 characters shorter.
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
     * Get the first line of every comment paragraph that spans several lines without being a slope.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($this->inlineComments($file))
            ->concat($this->bannerParagraphs($file))
            ->reject(fn (array $paragraph) => count($paragraph) === 1 || $this->isSlope($paragraph))
            ->map(fn (array $paragraph) => array_key_first($paragraph))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get the runs of // comments on consecutive lines, each as its text by line number.
     *
     * @return list<array<int, string>>
     */
    protected function inlineComments(File $file): array
    {
        $runs = [];
        $previous = null;

        foreach ($file->tokens() as $token) {
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
     * Get the prose paragraphs of the file's banner comments, the lines of a block comment that start with a pipe.
     *
     * @return list<array<int, string>>
     */
    protected function bannerParagraphs(File $file): array
    {
        $paragraphs = [];

        foreach ($file->tokens() as $token) {
            if ($token->id !== T_COMMENT || ! str_starts_with($token->text, '/*')) {
                continue;
            }

            $paragraphs[] = [];

            foreach (explode("\n", $token->text) as $offset => $line) {
                if (preg_match('/^\s*\|(.*)$/', $line, $match) !== 1) {
                    continue;
                }

                $text = trim($match[1]);

                if ($text === '' || preg_match('/^-+$/', $text) === 1) {
                    $paragraphs[] = [];

                    continue;
                }

                $paragraphs[array_key_last($paragraphs)][$token->line + $offset] = $text;
            }
        }

        return array_values(array_filter($paragraphs));
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
