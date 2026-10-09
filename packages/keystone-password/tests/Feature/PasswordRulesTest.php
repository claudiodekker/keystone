<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\BreachedPasswords;
use ClaudioDekker\Keystone\Password\FakeBreachedPasswords;
use ClaudioDekker\Keystone\Password\HibpBreachedPasswords;
use ClaudioDekker\Keystone\Password\PasswordRules;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Password;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    Exceptions::fake();
    config(['keystone.require_recovery_codes' => false]);
});

function signInRequiringSecondFactor(AppTestCase $test, string $address = 'jane.doe@example.com'): Model&KeystoneUser
{
    config(['keystone.require_second_factor' => true]);
    $challenge = new FormTypeSupport('code');
    $account = $test->createChallengedAccount($challenge, $address);
    $test->passFirstFactor($address);
    $test->post(route('login.challenge.submit', ['type' => 'code']), $challenge->validProof(Surface::CHALLENGE));

    return $account;
}

function signInWithoutSecondFactor(AppTestCase $test, string $address = 'jane.doe@example.com'): Model&KeystoneUser
{
    config(['keystone.require_second_factor' => false]);
    $account = $test->createFirstFactorAccount($address);
    $test->passFirstFactor($address);

    return $account;
}

function submitNewPassword(AppTestCase $test, string $password, ?string $confirmation = null): TestResponse
{
    $test->get(route('security.enroll', ['type' => 'password']));

    return $test->post(route('security.enroll.submit', ['type' => 'password']), ['password' => $password, 'password_confirmation' => $confirmation ?? $password]);
}

function fakeBreaches(array $breached = []): FakeBreachedPasswords
{
    return app()->instance(BreachedPasswords::class, new FakeBreachedPasswords($breached));
}

function captureWarnings(): void
{
    config([
        'logging.channels.keystone-test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'keystone.log_channel' => 'keystone-test',
    ]);
}

function rangeUrl(string $password): string
{
    return 'https://api.pwnedpasswords.com/range/'.substr(strtoupper(hash('sha1', $password)), 0, 5);
}

function rangeSuffix(string $password): string
{
    return substr(strtoupper(hash('sha1', $password)), 5);
}

function loggedWarnings(): array
{
    $records = Log::channel('keystone-test')->getLogger()->getHandlers()[0]->getRecords();

    return array_values(array_filter($records, fn (LogRecord $record) => $record->level === Level::Warning));
}

function assertReachedVerification(TestResponse $response): void
{
    $response->assertSessionHasErrors(['password' => __('keystone::messages.invalid_credential')]);
}

describe('the minimum length', function () {
    it('asks for at least 8 characters while a second factor is required', function (string $password, bool $passes) {
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, $password);

        $passes
            ? assertReachedVerification($response)
            : $response->assertSessionHasErrors(['password' => __('validation.min.string', ['attribute' => 'password', 'min' => 8])]);
    })->with([
        '7 characters' => ['tq8#vbn', false],
        '8 characters' => ['tq8#vbnz', true],
    ]);

    it('asks for at least 15 characters when a password may be the only factor', function (string $password, bool $passes) {
        signInWithoutSecondFactor($this);

        $response = submitNewPassword($this, $password);

        $passes
            ? assertReachedVerification($response)
            : $response->assertSessionHasErrors(['password' => __('validation.min.string', ['attribute' => 'password', 'min' => 15])]);
    })->with([
        '14 characters' => ['tq8#vbnz-wx4!k', false],
        '15 characters' => ['tq8#vbnz-wx4!kp', true],
    ]);

    it('asks for the app\'s own minimum for the mandate in force', function (bool $requireSecondFactor, string $key, string $password, bool $passes) {
        config(["keystone-password.min_length.{$key}" => 12]);
        $requireSecondFactor ? signInRequiringSecondFactor($this) : signInWithoutSecondFactor($this);

        $response = submitNewPassword($this, $password);

        $passes
            ? assertReachedVerification($response)
            : $response->assertSessionHasErrors(['password' => __('validation.min.string', ['attribute' => 'password', 'min' => 12])]);
    })->with([
        'required, 11 characters' => [true, 'second_factor_required', 'tq8#vbnz-wx', false],
        'required, 12 characters' => [true, 'second_factor_required', 'tq8#vbnz-wx4', true],
        'optional, 11 characters' => [false, 'second_factor_optional', 'tq8#vbnz-wx', false],
        'optional, 12 characters' => [false, 'second_factor_optional', 'tq8#vbnz-wx4', true],
    ]);

    it('counts characters, not bytes', function () {
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'ééééçççç');

        assertReachedVerification($response);
    });

    it('ignores the app\'s own password defaults', function () {
        Password::defaults(fn () => Password::min(20)->symbols());
        $this->beforeApplicationDestroyed(fn () => Password::$defaultCallback = null);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'tq8vbnzw');

        assertReachedVerification($response);
    });
});

describe('context words', function () {
    it('refuses a password containing a context word, whatever its case', function (string $password) {
        config(['app.name' => 'Acme Payroll', 'app.url' => 'https://portal.example.test', 'keystone-password.context_words' => ['Keystone']]);
        $account = signInRequiringSecondFactor($this, 'jane.doe@example.com');
        $this->holdAddress($account, 'jdoe.work@example.org');
        $this->holdAddress($account, 'mallory@example.org', verified: false);

        $response = submitNewPassword($this, $password);

        $response->assertSessionHasErrors(['password' => __('keystone-password::messages.context_word', ['attribute' => 'password'])]);
    })->with([
        'a word of the app\'s name' => ['my PAYROLL 2026!'],
        'a word of the app\'s host' => ['portal-is-mine-2026'],
        'a word of the email\'s local part' => ['Jane rules 2026!'],
        'a word of another address the account holds' => ['jdoe-2026-rocks'],
        'a word of an unverified address' => ['MALLORY-2026!'],
        'a configured context word' => ['ilovekeystone99'],
    ]);

    it('lets a password contain a context word shorter than 4 characters', function () {
        config(['app.name' => 'Hub']);
        signInRequiringSecondFactor($this, 'al@example.com');

        $response = submitNewPassword($this, 'hub al rocks 2026');

        assertReachedVerification($response);
    });

    it('never counts the email\'s domain as a context word', function () {
        signInRequiringSecondFactor($this, 'jane@gmail.com');

        $response = submitNewPassword($this, 'gmailgmail99');

        assertReachedVerification($response);
    });
});

describe('the common-password list', function () {
    it('refuses a password on the bundled list, whatever its case', function (string $password) {
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, $password);

        $response->assertSessionHasErrors(['password' => __('keystone-password::messages.common', ['attribute' => 'password'])]);
    })->with([
        'password' => ['password'],
        'PassWord' => ['PassWord'],
        '12345678' => ['12345678'],
        'ILOVEYOU' => ['ILOVEYOU'],
    ]);
});

describe('the breach check', function () {
    it('refuses a password the breach check reports, once the local rules pass', function () {
        $breaches = fakeBreaches(['Tr0ub4dor&3xyz']);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'Tr0ub4dor&3xyz');

        $response->assertSessionHasErrors(['password' => __('validation.password.uncompromised', ['attribute' => 'password'])]);
        expect($breaches->asked)->toBe(['Tr0ub4dor&3xyz']);
    });

    it('never asks the breach check about a password an earlier rule refused', function (string $password, ?string $confirmation) {
        $breaches = fakeBreaches();
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, $password, $confirmation);

        $response->assertSessionHasErrors('password');
        expect($breaches->asked)->toBe([]);
    })->with([
        'unconfirmed' => ['tq8#vbnz', 'tq8#vbnx'],
        'too short' => ['tq8#vbn', null],
        'a context word' => ['jane-2026-rocks', null],
        'common' => ['iloveyou', null],
    ]);

    it('asks Pwned Passwords by the first 5 characters of the SHA-1 hash, with padding, and refuses a password it lists', function () {
        captureWarnings();
        Http::fake([rangeUrl('Tr0ub4dor&3xyz') => Http::response('0018A45C4D1DEF81644B54AB7F969B88D65:0'."\r\n".rangeSuffix('Tr0ub4dor&3xyz').':3'."\r\n")]);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'Tr0ub4dor&3xyz');

        $response->assertSessionHasErrors(['password' => __('validation.password.uncompromised', ['attribute' => 'password'])]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === rangeUrl('Tr0ub4dor&3xyz') && $request->header('Add-Padding') === ['true']);
    });

    it('counts a suffix listed only as padding as not breached', function () {
        captureWarnings();
        Http::fake([rangeUrl('Tr0ub4dor&3xyz') => Http::response(rangeSuffix('Tr0ub4dor&3xyz').":0\r\n")]);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'Tr0ub4dor&3xyz');

        assertReachedVerification($response);
    });

    it('gives Pwned Passwords 5 seconds and follows no redirect', function () {
        captureWarnings();
        $sent = [];
        Http::fake(function (Request $request, array $options) use (&$sent) {
            $sent = $options;

            return Http::response('');
        });
        signInRequiringSecondFactor($this);

        submitNewPassword($this, 'Tr0ub4dor&3xyz');

        expect($sent['timeout'])->toBe(5)
            ->and($sent['connect_timeout'])->toBe(5)
            ->and($sent['allow_redirects'])->toBeFalse();
    });

    it('accepts the password and logs a warning when Pwned Passwords can\'t answer', function (Closure $answer) {
        captureWarnings();
        Http::fake([rangeUrl('Tr0ub4dor&3xyz') => $answer]);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'Tr0ub4dor&3xyz');

        assertReachedVerification($response);
        Http::assertSentCount(1);
        $warnings = loggedWarnings();
        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->message)->toBe(HibpBreachedPasswords::UNAVAILABLE_MESSAGE)
            ->and(json_encode($warnings[0]->context))->not->toContain('Tr0ub4dor')->not->toContain(substr(strtoupper(hash('sha1', 'Tr0ub4dor&3xyz')), 0, 5));
    })->with([
        'a failed connection' => [fn () => fn () => Http::failedConnection()],
        'a server error' => [fn () => fn () => Http::response('', 503)],
        'a redirect' => [fn () => fn () => Http::response('', 301, ['Location' => 'https://example.com/range'])],
    ]);

    it('still refuses a common password while Pwned Passwords is down', function () {
        captureWarnings();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::failedConnection()]);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'iloveyou');

        $response->assertSessionHasErrors(['password' => __('keystone-password::messages.common', ['attribute' => 'password'])]);
        Http::assertNothingSent();
    });

    it('accepts the password with a warning when a test prevents stray requests and fakes nothing, and still refuses a common one', function () {
        captureWarnings();
        signInRequiringSecondFactor($this);

        submitNewPassword($this, 'iloveyou')->assertSessionHasErrors(['password' => __('keystone-password::messages.common', ['attribute' => 'password'])]);
        $response = submitNewPassword($this, 'Tr0ub4dor&3xyz');

        assertReachedVerification($response);
        $warnings = loggedWarnings();
        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->message)->toBe(HibpBreachedPasswords::UNAVAILABLE_MESSAGE)
            ->and($warnings[0]->context)->toBe(['reason' => StrayRequestException::class]);
    });
});

describe('the app\'s own rules', function () {
    beforeEach(function () {
        $this->beforeApplicationDestroyed(fn () => PasswordRules::defaults(null));
    });

    it('takes the app\'s rules in place of Keystone\'s length and blocklist', function (Closure $rules) {
        PasswordRules::defaults($rules());
        $breaches = fakeBreaches(['password']);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'password');

        assertReachedVerification($response);
        expect($breaches->asked)->toBe([]);
    })->with([
        'a callback returning a rule' => [fn () => fn () => fn () => Password::min(1)],
        'a rule' => [fn () => fn () => Password::min(1)],
        'a list of rules' => [fn () => fn () => ['min:1']],
    ]);

    it('keeps Keystone\'s rules when the app\'s callback returns null', function () {
        PasswordRules::defaults(fn () => null);
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'password');

        $response->assertSessionHasErrors(['password' => __('keystone-password::messages.common', ['attribute' => 'password'])]);
    });

    it('asks for more when the app\'s rules are stricter', function () {
        PasswordRules::defaults(fn () => Password::min(20));
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, 'tq8#vbnz-wx4!kp');

        $response->assertSessionHasErrors(['password' => __('validation.min.string', ['attribute' => 'password', 'min' => 20])]);
    });

    it('still asks for a confirmed password that fits the hashing driver', function (string $password, ?string $confirmation, string $message) {
        config(['hashing.driver' => 'bcrypt']);
        PasswordRules::defaults(fn () => Password::min(1));
        signInRequiringSecondFactor($this);

        $response = submitNewPassword($this, $password, $confirmation);

        $response->assertSessionHasErrors(['password' => __($message, ['attribute' => 'password', 'max' => 72])]);
    })->with([
        'unconfirmed' => ['password', 'passwort', 'validation.confirmed'],
        'over 72 bytes' => [str_repeat('a', 73), null, 'validation.max.string'],
    ]);

    it('never reads the app\'s rules at sign-in', function () {
        PasswordRules::defaults(fn () => Password::min(2000));
        $account = $this->createAccount();
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'password', 'secret' => Crypt::encryptString(Hash::make('password'))]);

        $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($account);
    });
});
