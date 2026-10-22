<?php

use ClaudioDekker\Keystone\Lint\Checks\NoTableAccess;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Testing\TestFile;

it('fails table access that skips the models', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\DB;

        DB::table('users')->get();
        $builder->table('users');
        $user->getConnection();
        $user?->getConnection();
        PHP);

    expect($file->lines(new NoTableAccess))->toBe([5, 6, 7, 8]);
});

it('fails the facade under an alias and the global alias', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\DB as Database;

        Database::table('users')->get();
        \DB::table('users')->get();
        PHP);

    expect($file->lines(new NoTableAccess))->toBe([5, 6]);
});

it('fails table access in the tests of a package', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nDB::table('users')->insert([]);\n");

    expect($file->lines(new NoTableAccess))->toBe([3]);
});

it('fails table access in the AppTests and database files of a package', function (string $path) {
    $file = TestFile::of(Kind::TESTS, $path, "<?php\n\nDB::table('user_emails')->insert([]);\n");

    expect($file->lines(new NoTableAccess))->toBe([3]);
})->with([
    'packages/keystone/app-tests/Flows/ExampleTest.php',
    'packages/keystone/database/factories/ExampleFactory.php',
]);

it('passes the other uses of the facade and queries through models', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Schema;

        DB::transaction(fn () => User::query()->where('id', 1)->first());
        DB::getDriverName();
        Schema::table('users', fn ($table) => $table->index('email'));
        $user->getConnectionName();
        PHP);

    expect($file->lines(new NoTableAccess))->toBe([]);
});
