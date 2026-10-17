<?php

use ClaudioDekker\Keystone\Lint\Checks\SingleCaveatDocblocks;
use ClaudioDekker\Keystone\Lint\File;

it('fails a docblock with more than one paragraph after its summary', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        /**
         * Count a failed attempt, and refuse it once the count is spent.
         *
         * An identifier that names no account is counted by its address.
         *
         * A store failure is reported and refuses the attempt.
         *
         * @internal
         */
        class Example
        {
            //
        }
        PHP);

    expect((new SingleCaveatDocblocks)->check($file))->toBe([8]);
});

it('passes a summary with one caveat paragraph of any length, and tags', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        /**
         * Hold the answer until the floor has passed.
         *
         * It measures with the app's clock and waits with Sleep, so a faked
         * sleep moves a faked clock by the whole wait, however the lines run.
         *
         * @template TResult
         * @param  Closure(): TResult  $work  The work to run, and a description that wraps
         *                                     onto a second line.
         */
        function hold() {
            //
        }
        PHP);

    expect((new SingleCaveatDocblocks)->check($file))->toBe([]);
});
