<?php

use ClaudioDekker\Keystone\Lint\Checks\SlopedComments;
use ClaudioDekker\Keystone\Lint\File;

it('fails an inline comment of several lines that is not a slope', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        // We will check the first line here, which is long,
        // and the second line is about as long as the first.
        $a = 1;

        // A comment that runs over three lines
        // gets shorter on every line it has,
        // but then it keeps on going for a
        // fourth line.
        $b = 2;
        PHP);

    expect((new SlopedComments)->check($file))->toBe([3, 7]);
});

it('passes single lines and slopes, and leaves docblocks alone', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        /**
         * Hold the answer until the floor has passed.
         *
         * It measures with the app's clock and waits with Sleep, so a faked sleep moves
         * a faked clock by the whole wait.
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
