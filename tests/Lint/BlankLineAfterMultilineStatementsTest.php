<?php

use ClaudioDekker\Keystone\Lint\BlankLineAfterMultilineStatements;
use ClaudioDekker\Keystone\Lint\File;

it('fails a statement that follows a multiline statement directly', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        function example()
        {
            $names = [
                'a',
                'b',
            ];
            $count = count($names);

            $total = sum(
                $count,
                1,
            );
            return $total;
        }
        PHP);

    expect((new BlankLineAfterMultilineStatements)->check($file))->toBe([9, 15]);
});

it('fails the comment that follows a multiline statement directly', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        $names = [
            'a',
        ];
        // Count them.
        $count = count($names);
        PHP);

    expect((new BlankLineAfterMultilineStatements)->check($file))->toBe([6]);
});

it('fails a member of a class and a statement of a closure alike', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function first(): void
            {
                //
            }
            public function second(): callable
            {
                return function () {
                    $names = [
                        'a',
                    ];
                    return $names;
                };
            }
        }
        PHP);

    expect((new BlankLineAfterMultilineStatements)->check($file))->toBe([9, 15]);
});

it('passes a multiline statement followed by a blank line, and one-line statements together', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        function example()
        {
            $count = 1;
            $names = [
                'a',
            ];

            $total = sum(
                $count,
                1,
            );

            return [
                $names,
                $total,
            ];
        }
        PHP);

    expect((new BlankLineAfterMultilineStatements)->check($file))->toBe([]);
});
