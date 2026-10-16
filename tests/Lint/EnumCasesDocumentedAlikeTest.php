<?php

use ClaudioDekker\Keystone\Lint\Checks\EnumCasesDocumentedAlike;
use ClaudioDekker\Keystone\Lint\File;

it('fails the cases without a docblock in an enum that documents some of its cases', function () {
    $file = new File('packages/keystone/src/Mode.php', <<<'PHP'
        <?php

        enum Mode
        {
            /**
             * The first mode.
             */
            case ALPHA;

            case BETA;

            /**
             * The third mode.
             */
            case GAMMA;

            case DELTA;
        }
        PHP);

    expect((new EnumCasesDocumentedAlike)->check($file))->toBe([10, 17]);
});

it('passes an enum that documents every case', function () {
    $file = new File('packages/keystone/src/Mode.php', <<<'PHP'
        <?php

        enum Mode
        {
            /**
             * The first mode.
             */
            case ALPHA;

            /**
             * The second mode.
             */
            case BETA;
        }
        PHP);

    expect((new EnumCasesDocumentedAlike)->check($file))->toBe([]);
});

it('passes an enum that documents none of its cases', function () {
    $file = new File('packages/keystone/src/Mode.php', <<<'PHP'
        <?php

        enum Mode
        {
            case ALPHA;
            case BETA;
        }
        PHP);

    expect((new EnumCasesDocumentedAlike)->check($file))->toBe([]);
});

it('counts the cases of each enum on their own', function () {
    $file = new File('packages/keystone/src/Modes.php', <<<'PHP'
        <?php

        enum Mode
        {
            /**
             * The first mode.
             */
            case ALPHA;
        }

        enum Level
        {
            case LOW;
        }
        PHP);

    expect((new EnumCasesDocumentedAlike)->check($file))->toBe([]);
});
