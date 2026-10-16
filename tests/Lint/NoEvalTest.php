<?php

use ClaudioDekker\Keystone\Lint\Checks\NoEval;
use ClaudioDekker\Keystone\Lint\File;

it('fails eval in src', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        eval($code);
        $result = eval('return 1;');
        PHP);

    expect((new NoEval)->check($file))->toBe([3, 4]);
});

it('passes a method and a function that only share the name', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        $parser->eval($code);
        evaluate($code);
        PHP);

    expect((new NoEval)->check($file))->toBe([]);
});

it('reads only src', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\neval(\$code);\n");

    expect((new NoEval)->check($file))->toBe([]);
});
