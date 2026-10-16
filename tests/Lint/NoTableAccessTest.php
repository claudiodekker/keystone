<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoTableAccess;

it('fails table access that skips the models', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\DB;

        DB::table('users')->get();
        $builder->table('users');
        $user->getConnection();
        $user?->getConnection();
        PHP);

    expect((new NoTableAccess)->check($file))->toBe([5, 6, 7, 8]);
});

it('fails the facade under an alias and the global alias', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\DB as Database;

        Database::table('users')->get();
        \DB::table('users')->get();
        PHP);

    expect((new NoTableAccess)->check($file))->toBe([5, 6]);
});

it('fails table access in the tests of a package', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nDB::table('users')->insert([]);\n");

    expect((new NoTableAccess)->check($file))->toBe([3]);
});

it('passes the other uses of the facade and queries through models', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Schema;

        DB::transaction(fn () => User::query()->where('id', 1)->first());
        DB::getDriverName();
        Schema::table('users', fn ($table) => $table->index('email'));
        $user->getConnectionName();
        PHP);

    expect((new NoTableAccess)->check($file))->toBe([]);
});

it('reads the source and tests of packages only', function () {
    $file = new File('tests/DatabaseTest.php', "<?php\n\nDB::table('users')->get();\n");

    expect((new NoTableAccess)->check($file))->toBe([]);
});
