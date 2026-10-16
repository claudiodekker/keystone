<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoReflection;

it('fails reflection under whatever name the file imports it', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace App;

        use ReflectionClass as R;
        use Illuminate\Support\Reflector;

        new R($thing);
        Reflector::isParameterBackedEnumWithStringBackingType($parameter);
        PHP);

    expect((new NoReflection)->check($file))->toBe([5, 6, 8, 9]);
});

it('fails reflection written with a leading backslash or as a type', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace App;

        function describe(\ReflectionNamedType $type): \ReflectionMethod
        {
            return new \ReflectionMethod($type, 'name');
        }
        PHP);

    expect((new NoReflection)->check($file))->toBe([5, 7]);
});

it('passes names that only look like reflection', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace App;

        use App\Support\ReflectionCache;
        use Illuminate\Support\Str;

        $thing->reflectionClass;
        $thing->reflect();
        PHP);

    expect((new NoReflection)->check($file))->toBe([]);
});

it('reads only src', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nnew ReflectionClass(\$thing);\n");

    expect((new NoReflection)->check($file))->toBe([]);
});
