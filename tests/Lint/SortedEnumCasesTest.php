<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\SortedEnumCases;

it('fails an enum case that sorts before the case above it', function () {
    $file = new File('packages/keystone/src/Mode.php', <<<'PHP'
        <?php

        enum Mode: string
        {
            case BETA = 'b';
            case ALPHA = 'a';
            case GAMMA = 'g';
            case DELTA = 'd';
        }
        PHP);

    expect((new SortedEnumCases)->check($file))->toBe([6, 8]);
});

it('passes enum cases in alphabetical order', function () {
    $file = new File('packages/keystone/src/Mode.php', <<<'PHP'
        <?php

        enum Mode: string
        {
            case ALPHA = 'z';
            case BETA = 'y';
            case DELTA = 'x';
            case GAMMA = 'w';
        }
        PHP);

    expect((new SortedEnumCases)->check($file))->toBe([]);
});
