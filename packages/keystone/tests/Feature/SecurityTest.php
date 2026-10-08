<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\DB;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['form', 'code', 'totp']]);
});

describe('credentials', function () {
    it('lists the account\'s credentials under each listed type, held or not, with their names and when they were added and last used', function () {
        $this->freezeSecond();
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->travel(2)->days();
        $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport)->validProof(Surface::SIGN_IN)]);
        $codeId = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'code', 'label' => 'Phone', 'created_at' => now()->subDay()]);
        $formId = DB::table('user_credentials')->where('type', 'form')->value('id');

        $response = $this->get(route('security'));

        $response->assertOk()->assertJsonPath('types', [
            ['type' => 'form', 'enrollable' => false, 'credentials' => [['id' => $formId, 'label' => null, 'addedAt' => now()->subDays(2)->toIso8601String(), 'lastUsedAt' => now()->toIso8601String(), 'disabled' => false]]],
            ['type' => 'code', 'enrollable' => true, 'credentials' => [['id' => $codeId, 'label' => 'Phone', 'addedAt' => now()->subDay()->toIso8601String(), 'lastUsedAt' => null, 'disabled' => false]]],
            ['type' => 'totp', 'enrollable' => true, 'credentials' => []],
        ])->assertJsonPath('leftovers', []);
    });

    it('lists credentials of types keystone.methods no longer lists, or no package registers, as leftovers', function () {
        $this->freezeSecond();
        config(['keystone.methods' => ['form']]);
        $account = $this->signInAccount(new FormTypeSupport);
        $codeId = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'code', 'label' => 'Phone', 'created_at' => now()]);
        $goneId = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'uninstalled', 'created_at' => now(), 'last_used_at' => now()]);

        $response = $this->get(route('security'));

        $response->assertOk()
            ->assertJsonPath('types.*.type', ['form'])
            ->assertJsonPath('leftovers', [
                ['id' => $codeId, 'type' => 'code', 'label' => 'Phone', 'addedAt' => now()->toIso8601String(), 'lastUsedAt' => null, 'disabled' => false],
                ['id' => $goneId, 'type' => 'uninstalled', 'label' => null, 'addedAt' => now()->toIso8601String(), 'lastUsedAt' => now()->toIso8601String(), 'disabled' => false],
            ]);
    });

    it('lists none of another account\'s credentials', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $other = $this->createAccount('john@example.com');
        DB::table('user_credentials')->insert([
            ['user_id' => $other->getKey(), 'type' => 'code', 'label' => 'John\'s phone'],
            ['user_id' => $other->getKey(), 'type' => 'uninstalled', 'label' => 'John\'s leftover'],
        ]);

        $response = $this->get(route('security'));

        $response->assertOk()
            ->assertJsonPath('types.0.credentials.*.id', [DB::table('user_credentials')->where('user_id', $account->getKey())->value('id')])
            ->assertJsonPath('types.1.credentials', [])
            ->assertJsonPath('leftovers', [])
            ->assertDontSee('John');
    });

    it('marks disabled credentials, listed or not', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        DB::table('user_credentials')->insert([
            ['user_id' => $account->getKey(), 'type' => 'code', 'label' => 'Cloned key', 'disabled_at' => now()],
            ['user_id' => $account->getKey(), 'type' => 'uninstalled', 'label' => 'Cloned leftover', 'disabled_at' => now()],
        ]);

        $response = $this->get(route('security'));

        $response->assertOk()
            ->assertJsonPath('types.0.credentials.0.disabled', false)
            ->assertJsonPath('types.1.credentials.0.label', 'Cloned key')
            ->assertJsonPath('types.1.credentials.0.disabled', true)
            ->assertJsonPath('leftovers.0.label', 'Cloned leftover')
            ->assertJsonPath('leftovers.0.disabled', true);
    });
});

describe('recovery codes', function () {
    it('counts the account\'s unspent codes, running low at three or fewer', function (int $held, bool $low) {
        $account = $this->signInAccount(new FormTypeSupport);
        (new RecoveryCodes($account))->replace($account->getKey(), array_slice((new RecoveryCodes($account))->generate(), 0, $held));
        (new RecoveryCodes($account))->replace($this->createAccount('john@example.com')->getKey(), (new RecoveryCodes($account))->generate());

        $response = $this->get(route('security'));

        $response->assertOk()->assertJsonPath('recoveryCodes', $held)->assertJsonPath('recoveryCodesLow', $low);
    })->with([
        'none' => [0, true],
        'three' => [3, true],
        'four' => [4, false],
        'a full set' => [8, false],
    ]);
});

describe('sudo', function () {
    it('shows when the session\'s sudo ends', function () {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        $this->travel(5)->minutes();

        $response = $this->get(route('security'));

        $response->assertOk()->assertJsonPath('sudoEndsAt', now()->addMinutes(10)->toIso8601String());
    });

    it('shows no sudo once it ended, ran out or is used from another subnet, and needs none to show', function (Closure $lose) {
        $this->freezeSecond();
        $this->signInAccount(new FormTypeSupport);
        $lose($this);

        $response = $this->get(route('security'));

        $response->assertOk()->assertJsonPath('sudoEndsAt', null);
    })->with([
        'ended' => [fn (AppTestCase $test) => $test->delete(route('sudo.end'))],
        'run out' => [fn (AppTestCase $test) => $test->travel(900)->seconds()],
        'another subnet' => [fn (AppTestCase $test) => $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])],
    ]);
});

describe('the page', function () {
    it('lands the user whose sudo ended on it, with the translated status', function () {
        $this->signInAccount(new FormTypeSupport);

        $this->delete(route('sudo.end'))->assertRedirectToRoute('security');

        $this->get(route('security'))->assertOk()->assertJsonPath('status', __('keystone::messages.status.sudo-revoked'));
    });

    it('carries no status otherwise', function () {
        $this->signInAccount(new FormTypeSupport);

        $this->get(route('security'))->assertOk()->assertJsonPath('status', null);
    });

    it('sends a guest to sign in', function () {
        $this->get(route('security'))->assertRedirectToRoute('login');
    });

    it('limits requests to the page', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.view')) as $ignored) {
            $this->get(route('security'))->assertOk();
        }

        $this->get(route('security'))->assertTooManyRequests();
    });
});
