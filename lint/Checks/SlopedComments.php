<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\Keystone\Lint\Check;
use ClaudioDekker\Keystone\Lint\File;

/**
 * Refuse an inline comment of several lines that isn't a slope of three lines, each 2 to 4 characters shorter.
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
     * Get the first line of every run of // comments that spans several lines without being a slope.
     *
     * @return list<int>
     */
    protected function lines(File $file): array
    {
        return collect($this->inlineComments($file))
            ->reject(fn (array $run) => count($run) === 1 || $this->isSlope($run))
            ->map(fn (array $run) => array_key_first($run))
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
     * Determine if the run has three lines, each 2 to 4 characters shorter than the one above.
     *
     * @param  array<int, string>  $run
     */
    protected function isSlope(array $run): bool
    {
        $lengths = array_map(mb_strlen(...), array_values($run));

        return count($lengths) === 3
            && in_array($lengths[0] - $lengths[1], [2, 3, 4], true)
            && in_array($lengths[1] - $lengths[2], [2, 3, 4], true);
    }
}
