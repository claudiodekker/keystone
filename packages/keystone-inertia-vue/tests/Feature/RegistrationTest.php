<?php

use ClaudioDekker\Keystone\Actions\CreateAccount;
use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RegistrationAssertions;
use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RegistrationFinishAssertions;
use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RegistrationLinkAssertions;
use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use ClaudioDekker\Keystone\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Uri;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(RegistrationAssertions::class, RegistrationLinkAssertions::class, RegistrationFinishAssertions::class, SignInAssertions::class);

beforeEach(function () {
    config(['keystone.methods' => ['password', 'totp']]);
    Notification::fake();
});

it('offers to create an account on the sign-in page only while registration is open', function (array $methods, bool $open) {
    config(['keystone.methods' => $methods]);

    $response = $this->get(route('login'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('registrationOpen', $open));
})->with([
    'open' => [['password', 'totp'], true],
    'closed' => [['password' => ['sign-in'], 'totp'], false],
]);

it('renders the register page, kept encrypted in the browser\'s history', function () {
    $response = $this->get(route('register'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Register')->where('status', null)->where('email', null));
    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('fills the register page with the address invalid input flashed back', function () {
    $this->post(route('register.submit'), ['email' => 'not-an-address']);

    $response = $this->get(route('register'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Register')->where('email', 'not-an-address')->where('errors.email', fn ($error) => $error !== null));
});

it('says registration is unavailable on the sign-in page while it is closed', function () {
    config(['keystone.methods' => ['password' => ['sign-in']]]);
    $this->get(route('register'));

    $response = $this->get(route('login'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('status', __('keystone::messages.status.registration-unavailable')));
});

it('sends a submitted address on to the link-sent page', function () {
    $response = $this->post(route('register.submit'), ['email' => 'new@example.com']);

    $this->assertRegistrationLinkSent($response);
    $this->assertRegistrationLinkSentPage($this->get(route('register.link-sent')));
});

it('renders the mailed link\'s step with an action that posts the link back', function () {
    $this->post(route('register.submit'), ['email' => 'new@example.com']);
    $url = $this->mailedLinkTo('new@example.com');

    $response = $this->get($url);

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/EmailedLink')->where('action', route('register.verify.consume', Uri::of($url)->query()->all(), absolute: false)));
    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
    $this->assertEmailedLinkHardeningFloor($response);
});

it('sends a spent link on to the finish page, which shows the proven address', function () {
    $this->post(route('register.submit'), ['email' => 'new@example.com']);

    $response = $this->post($this->mailedLinkTo('new@example.com'));

    $this->assertRegistrationLinkConsumed($response);
    $this->get(route('register.finish'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/RegisterFinish')
        ->where('address', 'new@example.com')
        ->where('types', [['type' => 'password', 'shape' => 'form']])
        ->where('status', null));
});

it('fills the finish page with the name invalid input flashed back', function () {
    $this->registerAddress();
    $this->post(route('register.finish.submit', ['type' => 'password']), ['name' => 'Jane Doe', 'password' => 'short', 'password_confirmation' => 'short']);

    $response = $this->get(route('register.finish'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/RegisterFinish')
        ->where('name', 'Jane Doe')
        ->where('errors.password', fn ($error) => $error !== null));
});

it('signs the new account in and sends it home, or on to the enrollment it owes', function (bool $mandated, Closure $assert) {
    $mandated ? $this->withMandates() : $this->withoutMandates();
    $this->registerAddress();

    $response = $this->finishRegistration(new PasswordTypeSupport);

    $assert->call($this, $response);
})->with([
    'owing nothing' => [false, fn ($response) => $this->assertRegistered($response, '/')],
    'owing enrollment' => [true, fn ($response) => $this->assertRegistrationEnrollmentOwed($response)],
]);

it('shows the enrollment a new account owes with origin registration', function () {
    $this->withMandates();
    $this->registerAddress();
    $this->finishRegistration(new PasswordTypeSupport);

    $response = $this->get(route('login.enrollment'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Enrollment')->where('origin', 'registration'));
});

it('refuses an account created barred on the sign-in page, as a refused sign-in', function () {
    $this->withoutMandates();
    $this->app->bind(CreateAccount::class, fn () => new class extends CreateAccount
    {
        public function handle(array $profile): Model
        {
            return tap(parent::handle($profile), fn (Model $account) => $account->forceFill(['suspended_at' => now()])->save());
        }
    });
    $this->registerAddress();

    $this->assertRegistrationBarred($this->finishRegistration(new PasswordTypeSupport));
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('errors.identifier', __('keystone::messages.failed')));
});

it('says on the sign-in page that an address another account came to hold is already registered', function () {
    $this->registerAddress();
    $this->createAccount('new@example.com');

    $response = $this->finishRegistration(new PasswordTypeSupport);

    $this->assertAddressTaken($response);
    $this->assertSignInPage($this->get(route('login')), Status::ADDRESS_ALREADY_REGISTERED->label());
});

it('refuses a credential the type couldn\'t make with the message on its field', function () {
    $this->registerAddress();
    Hash::shouldReceive('make')->andThrow(new RuntimeException('Hasher down.'));

    $this->assertRegistrationRefused($this->finishRegistration(new PasswordTypeSupport), 'password');
});

it('sends a link that no longer works on to the link-expired page', function () {
    $response = $this->post(route('register.verify.consume', ['expires' => '1', 'token' => 'x', 'signature' => 'y']));

    $this->assertRegistrationLinkExpired($response);
    $this->assertRegistrationLinkExpiredPage($this->get(route('register.link-expired')));
});
