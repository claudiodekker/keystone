<?php

use ClaudioDekker\Keystone\Demand;
use ClaudioDekker\Keystone\SignInDecision;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithArchivedAt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function readAccount(User $user, string $model = User::class): User
{
    return $model::query()->withoutGlobalScopes()->findOrFail($user->getKey());
}

it('signs in an active account', function () {
    expect((new SignInDecision)->demand(readAccount(User::factory()->create()), new FormType))->toBe(Demand::SIGN_IN);
});

it('refuses a disabled or suspended account', function (string $column) {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update([$column => now()]);

    expect((new SignInDecision)->demand(readAccount($user), new FormType))->toBe(Demand::REFUSE);
})->with(['deleted_at', 'invalidated_at', 'suspended_at']);

it('refuses an account soft deleted under its own column name', function () {
    Schema::table('users', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update(['archived_at' => now()]);

    expect((new SignInDecision)->demand(readAccount($user, UserWithArchivedAt::class), new FormType))->toBe(Demand::REFUSE);
});
