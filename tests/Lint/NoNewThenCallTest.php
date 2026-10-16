<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoNewThenCall;

it('fails a new object with several arguments called at once with several arguments', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        (new Thing(1, 2))->go(3, 4);
        new Thing(1, 2)->go(3, 4);
        (new Thing(1, 2))?->go(3, 4);
        PHP);

    expect((new NoNewThenCall)->check($file))->toBe([3, 4, 5]);
});

it('passes when the constructor or the call takes one argument or none', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        (new Thing(1))->go(3, 4);
        (new Thing(1, 2))->go(3);
        (new Thing)->go();
        (new Thing(1, 2))->go();
        PHP);

    expect((new NoNewThenCall)->check($file))->toBe([]);
});

it('passes a new object that is named first', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        $thing = new Thing(1, 2);

        $thing->go(3, 4);
        PHP);

    expect((new NoNewThenCall)->check($file))->toBe([]);
});

it('reads every php file', function () {
    $file = new File('tests/ExampleTest.php', "<?php\n\n(new Thing(1, 2))->go(3, 4);\n");

    expect((new NoNewThenCall)->check($file))->toBe([3]);
});
