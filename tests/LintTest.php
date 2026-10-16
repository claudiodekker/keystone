<?php

use ClaudioDekker\Keystone\Lint\File;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;

dataset('checks', function () {
    $classes = File::read('lint.php')->find(
        fn (Node $node) => $node instanceof Class_
            && $node->extends !== null
            && ! $node->isAbstract(),
    );

    return collect($classes)
        ->mapWithKeys(fn (Class_ $class) => [(string) $class->name => [(string) $class->name]])
        ->all();
});

it('holds over the repository', function (string $check) {
    $check = "ClaudioDekker\\Keystone\\Lint\\{$check}";

    expect((new $check)->run())->toBe([]);
})->with('checks');

it('has a sample test for every check', function (string $check) {
    expect(__DIR__."/Lint/{$check}Test.php")->toBeFile();
})->with('checks');
