<?php

use ClaudioDekker\Keystone\Lint\Checks\SlopedComments;
use ClaudioDekker\Keystone\Lint\File;

it('fails a docblock paragraph of several lines that is not a slope', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        /**
         * Hold the answer.
         *
         * The first line of this paragraph is long,
         * and the second line is just as long, too.
         *
         * A paragraph that runs over three lines
         * gets shorter on every line it has, but
         * then it keeps on going for a fourth.
         * line.
         */
        class Example
        {
            //
        }
        PHP);

    expect((new SlopedComments)->check($file))->toBe([6, 9]);
});

it('fails an inline comment of several lines that is not a slope', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        // We will check the first line here, which is long,
        // and the second line is about as long as the first.
        $a = 1;
        PHP);

    expect((new SlopedComments)->check($file))->toBe([3]);
});

it('passes single lines, slopes and tags', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        /**
         * Hold the answer until the floor has passed, however long the summary line runs on.
         *
         * A slope paragraph has three lines,
         * each a bit shorter than the one
         * above it, down to the last.
         *
         * @template TResult
         * @param  Closure(): TResult  $work  The work to run, and a description that wraps
         *                                     onto a second line.
         */
        function hold() {
            // We will check the first line here.
            $a = 1;

            // We will run the slope comment now,
            // which shrinks on each next line,
            // until it reaches the last one.
            $b = 2;
        }
        PHP);

    expect((new SlopedComments)->check($file))->toBe([]);
});
