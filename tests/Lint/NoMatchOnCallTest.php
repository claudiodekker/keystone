<?php

use ClaudioDekker\Keystone\Lint\Checks\NoMatchOnCall;
use ClaudioDekker\Keystone\Lint\File;

it('fails a match whose subject is a call', function (string $subject) {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\n\$name = match ({$subject}) {\n    default => 'other',\n};\n");

    expect((new NoMatchOnCall)->check($file))->toBe([3]);
})->with([
    'lookup()',
    '$user->role()',
    'Role::for($user)',
    '(new Roles)->for($user)',
    '$user?->role()',
]);

it('passes a match whose subject is already named', function (string $subject) {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\n\$name = match ({$subject}) {\n    default => 'other',\n};\n");

    expect((new NoMatchOnCall)->check($file))->toBe([]);
})->with([
    '$role',
    'true',
    '$user->role',
    '$user::class',
    'Role::ADMIN',
]);
