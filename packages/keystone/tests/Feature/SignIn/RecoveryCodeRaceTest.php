<?php

// SQL race: another answer spends the code between this answer's read and its delete, which no AppTest can arrange through the app's pages.

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCodeType;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\SignIn\Challenge;
use ClaudioDekker\Keystone\SignIn\Refused;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('refuses a code another answer spent after this one read it, spending it once', function () {
    $ada = User::factory()->create();
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'challenge-only', 'secret' => 'second horse']);
    $code = UserRecoveryCode::factory()->for($ada, 'user')->code('7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8')->create();
    $other = UserRecoveryCode::factory()->for($ada, 'user')->create();
    app(SessionState::class)->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::CHALLENGE);
    $raced = false;

    DB::listen(function (QueryExecuted $query) use ($ada, $code, &$raced) {
        if ($raced
            || ! str_starts_with($query->sql, 'select * from')
            || ! str_contains($query->sql, $code->getTable())
        ) {
            return;
        }

        $raced = true;

        app(AccountChanges::class)->change($ada, fn (AccountChange $change) => $change->spendRecoveryCode($code));
    });

    $outcome = $this->app->make(Challenge::class)->answer(app(RecoveryCodeType::class), ['recovery_code' => '7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8']);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(Keystone::guard()->check())->toBeFalse();

    $this->assertModelMissing($code);
    $this->assertModelExists($other);
});
