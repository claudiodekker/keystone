<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\OneClausePerLine;

it('fails a condition of three clauses on one line', function (string $condition) {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nif ({$condition}) {\n    go();\n}\n");

    expect((new OneClausePerLine)->check($file))->toBe([3]);
})->with([
    '$a && $b && $c' => '$a && $b && $c',
    '$a || $b || $c' => '$a || $b || $c',
    '$a and $b and $c' => '$a and $b and $c',
    '$a && $b || $c' => '$a && $b || $c',
]);

it('fails a group of three clauses on one line inside a condition', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nif (\$a && (\$b || \$c || \$d)) {\n    go();\n}\n");

    expect((new OneClausePerLine)->check($file))->toBe([3]);
});

it('fails a first clause that shares a line with the opening parenthesis', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        if ($a
            && $b
            && $c) {
            go();
        }
        PHP);

    expect((new OneClausePerLine)->check($file))->toBe([3]);
});

it('fails an operator at the end of a line', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        if (
            $a &&
            $b &&
            $c
        ) {
            go();
        }
        PHP);

    expect((new OneClausePerLine)->check($file))->toBe([5, 6]);
});

it('passes each clause on its own line with the operator first', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        if (
            $a
            && ($b || $c)
            && $d
        ) {
            go();
        }

        return $a
            && $b
            && $c;
        PHP);

    expect((new OneClausePerLine)->check($file))->toBe([]);
});

it('passes a condition of two clauses on one line, however long each clause is', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nif (\$a && \$b) {\n    go();\n}\n\nif ((\$a || \$b) && ! \$c) {\n    go();\n}\n");

    expect((new OneClausePerLine)->check($file))->toBe([]);
});
