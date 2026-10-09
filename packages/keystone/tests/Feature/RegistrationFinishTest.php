<?php

use ClaudioDekker\Keystone\Actions\CreateAccount;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\Notifications\Welcome;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use ClaudioDekker\Keystone\PendingOrigin;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\Registering;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->freezeSecond();
    Notification::fake();
});

function raceTheAddress(string $address = 'new@example.com'): void
{
    $raced = false;

    DB::beforeExecuting(function (string $query) use (&$raced, $address) {
        if ($raced || preg_match('/^insert into [`"]?user_emails[`"]?/i', $query) !== 1) {
            return;
        }

        $raced = true;
        $winner = DB::table('users')->insertGetId(['name' => 'Winner', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('user_emails')->insert([
            'user_id' => $winner,
            'address' => $address,
            'verified_address' => $address,
            'verified_at' => now(),
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

describe('finishing', function () {
    it('creates the account with the proven address as its verified primary one and the password, and signs it in', function () {
        $this->withoutMandates();
        $this->registerAddress();

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirect('/');
        $account = User::sole();
        expect($account->name)->toBe('Jane Doe')
            ->and(Keystone::guard()->id())->toBe($account->getKey())
            ->and(Keystone::guard()->registration())->toBeNull()
            ->and(Hash::check('correct horse battery staple', (string) (new Credentials($account))->ofType($account->getKey(), 'password')[0]->secret))->toBeTrue();
        $this->assertDatabaseHas('user_emails', ['user_id' => $account->getKey(), 'address' => 'new@example.com', 'verified_address' => 'new@example.com', 'verified_at' => now(), 'is_primary' => true]);
        $this->assertDatabaseCount('user_emails', 1);
        $this->assertDatabaseCount('user_credentials', 1);
    });

    it('sends the new account on to the page it asked for before registering', function () {
        $this->withoutMandates();
        session()->put('url.intended', '/dashboard');
        $this->registerAddress();

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirect('/dashboard');
        expect(session()->has('url.intended'))->toBeFalse();
    });

    it('signs the new account in on a new session id', function () {
        $this->withoutMandates();
        $this->registerAddress();
        $sessionId = session()->getId();

        $this->finishRegistration(new PasswordTypeSupport);

        expect(session()->getId())->not->toBe($sessionId);
    });

    it('holds an account that owes enrollment at enrollment, with origin registration', function () {
        $this->withMandates();
        $this->registerAddress();

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('login.enrollment');
        $pending = Keystone::guard()->pending();
        expect(Keystone::guard()->check())->toBeFalse()
            ->and(Keystone::guard()->registration())->toBeNull()
            ->and($pending->account->is(User::sole()))->toBeTrue()
            ->and($pending->origin)->toBe(PendingOrigin::REGISTRATION)
            ->and($pending->stage)->toBe(PendingStage::ENROLLMENT)
            ->and($pending->firstFactor)->toBe('password');
        $this->assertDatabaseHas('user_security_events', ['type' => 'sign_in.held', 'flow' => 'registration', 'reason' => 'keystone.enrollment']);
    });

    it('records account.registered with the credential, and sends the new address the welcome mail and no alert', function () {
        $this->withoutMandates();
        $this->registerAddress();

        $this->finishRegistration(new PasswordTypeSupport);

        $account = User::sole();
        $credentialId = DB::table('user_credentials')->value('id');
        $this->assertDatabaseHas('user_security_events', ['type' => 'account.registered', 'user_id' => $account->getKey(), 'flow' => 'registration', 'credential_type' => 'password', 'credential_id' => $credentialId]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
        Notification::assertSentOnDemandTimes(Welcome::class, 1);
        Notification::assertSentOnDemand(Welcome::class, fn (Welcome $mail, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com');
        Notification::assertNotSentTo(new AnonymousNotifiable, SecurityAlert::class);
    });

    it('records the sign-in that ends the registration without a new-device alert', function () {
        $this->withoutMandates();
        $this->registerAddress();

        $this->finishRegistration(new PasswordTypeSupport);

        $this->assertDatabaseHas('user_security_events', ['type' => 'signed_in', 'flow' => 'registration', 'credential_type' => 'password', 'known_device' => false]);
        Notification::assertNotSentTo(new AnonymousNotifiable, SecurityAlert::class);
    });

    it('grants the new session a fresh sudo, never one the registering session held', function () {
        $this->withoutMandates();
        $this->registerAddress();
        session()->put('keystone_sudo_web', ['granted_at' => now()->subMinutes(5)->getTimestamp(), 'subnet' => '10.0.0.0/24']);

        $this->finishRegistration(new PasswordTypeSupport);

        expect(Keystone::guard()->sudoGrant())->grantedAt->toEqual(now()->toImmutable());
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.granted', 'reason' => 'keystone.sign_in']);
    });

    it('sends the welcome mail an app names in its slot, or none for null', function (?string $slot, int $sent) {
        $this->withoutMandates();
        config(['keystone.notifications' => [...config('keystone.notifications'), 'account.registered' => $slot]]);
        $this->registerAddress();

        $this->finishRegistration(new PasswordTypeSupport);

        Notification::assertSentOnDemandTimes(Welcome::class, $sent);
        $this->assertDatabaseHas('user_security_events', ['type' => 'account.registered']);
    })->with(['the default' => [Welcome::class, 1], 'none' => [null, 0]]);

    it('creates the account through the app\'s own CreateAccount, with the fields it validates', function () {
        $this->withoutMandates();
        $this->app->bind(CreateAccount::class, fn () => new class extends CreateAccount
        {
            public function rules(): array
            {
                return [...parent::rules(), 'email' => ['required', 'string', 'email']];
            }

            public function handle(array $profile): User
            {
                return tap(User::make()->forceFill(['name' => $profile['name'], 'email' => $profile['email']]))->save();
            }
        });
        $this->registerAddress();
        $this->finishRegistration(new PasswordTypeSupport)->assertRedirectToRoute('register.finish')->assertSessionHasErrors(['email']);

        $response = $this->post(route('register.finish.submit', ['type' => 'password']), [
            'name' => 'Jane Doe',
            'email' => 'contact@example.com',
            ...(new PasswordTypeSupport)->validEnrollment(null),
        ]);

        $response->assertRedirect('/');
        expect(User::sole())->email->toBe('contact@example.com');
    });

    it('refuses an account the app\'s CreateAccount barred as a sign-in refuses one, ending the registration', function () {
        $this->withoutMandates();
        $this->app->bind(CreateAccount::class, fn () => new class extends CreateAccount
        {
            public function handle(array $profile): User
            {
                return tap(User::make()->forceFill(['name' => $profile['name'], 'suspended_at' => now()]))->save();
            }
        });
        $this->registerAddress();

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('login')->assertSessionHasErrors(['identifier' => __('keystone::messages.failed')]);
        expect(Keystone::guard()->check())->toBeFalse()
            ->and(Keystone::guard()->pending())->toBeNull()
            ->and(Keystone::guard()->registration())->toBeNull();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => User::sole()->getKey(), 'flow' => 'registration', 'reason' => 'keystone.barred']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'signed_in']);
    });

    it('creates nothing when the app\'s CreateAccount refuses the registration', function () {
        $this->withoutMandates();
        $this->app->bind(CreateAccount::class, fn () => new class extends CreateAccount
        {
            public function handle(array $profile): User
            {
                throw ValidationException::withMessages(['name' => 'Registration is by invitation only.']);
            }
        });
        $this->registerAddress();

        $response = $this->from(route('register.finish'))->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('register.finish')->assertSessionHasErrors(['name' => 'Registration is by invitation only.']);
        expect(Keystone::guard()->registration())->not->toBeNull();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_emails', 0);
    });
});

describe('refusing', function () {
    it('validates the name and the password, flashing back only the name', function () {
        $this->registerAddress();

        $response = $this->post(route('register.finish.submit', ['type' => 'password']), ['name' => str_repeat('a', 256), 'password' => 'short']);

        $response->assertRedirectToRoute('register.finish')->assertSessionHasErrors(['name', 'password']);
        expect(session()->getOldInput())->toBe(['name' => str_repeat('a', 256)])
            ->and(Keystone::guard()->registration())->not->toBeNull();
        $this->assertDatabaseCount('users', 0);
    });

    it('asks for a name', function () {
        $this->registerAddress();

        $response = $this->post(route('register.finish.submit', ['type' => 'password']), (new PasswordTypeSupport)->validEnrollment(null));

        $response->assertRedirectToRoute('register.finish')->assertSessionHasErrors(['name']);
    });

    it('keeps the proven address when the type refuses the credential, flashing back only the name', function () {
        $this->registerAddress();
        Hash::shouldReceive('make')->andThrow(new RuntimeException('Hasher down.'));

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('register.finish')->assertSessionHasErrors(['password' => __('keystone::messages.invalid_credential')]);
        expect(session()->getOldInput())->toBe(['name' => 'Jane Doe'])
            ->and(Keystone::guard()->registration())->not->toBeNull();
        $this->assertDatabaseCount('users', 0);
    });

    it('sends a type that doesn\'t serve registration back to the finish page, creating nothing', function (string $type) {
        $this->registerAddress();

        $response = $this->post(route('register.finish.submit', ['type' => $type]), ['name' => 'Jane Doe', 'secret' => 'x']);

        $response->assertRedirectToRoute('register.finish');
        $this->assertDatabaseCount('users', 0);
    })->with(['a type serving only sign-in' => 'form', 'an unknown type' => 'nope']);

    it('sends a session that proved no address back to register, creating nothing', function () {
        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('register');
        $this->assertDatabaseCount('users', 0);
    });

    it('creates nothing once the 30 minutes have passed', function () {
        $this->registerAddress();
        $this->travel(Registering::WINDOW_SECONDS)->seconds();

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('register');
        $this->assertDatabaseCount('users', 0);
    });

    it('creates nothing when the 30 minutes pass while the password is checked', function () {
        $this->registerAddress();
        $this->travel(Registering::WINDOW_SECONDS - 1)->seconds();
        $hasher = Hash::getFacadeRoot();
        Hash::shouldReceive('make')->andReturnUsing(function (string $password) use ($hasher) {
            $this->travel(1)->second();

            return $hasher->make($password);
        });

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('register');
        $this->assertDatabaseCount('users', 0);
    });

    it('creates nothing for an address an active account came to hold, and ends the registration', function (bool $verified) {
        $this->registerAddress();
        $owner = $this->createAccount('new@example.com', verified: $verified);

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('login')->assertSessionHas(Status::SESSION_KEY, Status::ADDRESS_ALREADY_REGISTERED->value);
        expect(Keystone::guard()->registration())->toBeNull()
            ->and(Keystone::guard()->check())->toBeFalse();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_credentials', 0);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'account.registered']);
        expect($owner->fresh())->not->toBeNull();
    })->with(['verified' => true, 'counted as verified' => false]);

    it('says the address is already registered', function () {
        expect(Status::ADDRESS_ALREADY_REGISTERED->label())->toBe('That email address is already registered. Please sign in instead.');
    });

    it('sends a signed-in user away, creating nothing', function () {
        $this->registerAddress();
        $this->signInAccount(new FormTypeSupport, 'jane@example.com');

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirect('/');
        $this->assertDatabaseCount('users', 1);
    });

    it('refuses while registration is closed, creating nothing', function () {
        $this->registerAddress();
        config(['keystone.methods' => ['form']]);

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('login')->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_UNAVAILABLE->value);
        $this->assertDatabaseCount('users', 0);
    });

    it('takes the submit limit', function () {
        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.submit')) as $ignored) {
            $this->finishRegistration(new PasswordTypeSupport)->assertRedirectToRoute('register');
        }

        $this->finishRegistration(new PasswordTypeSupport)->assertTooManyRequests();
    });
});

describe('a registration that is no longer live', function () {
    it('tells the register page the registration expired when the finish is posted', function (Closure $arrange) {
        $arrange->call($this);

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('register')->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_EXPIRED->value);
        $this->assertDatabaseCount('users', 0);
    })->with([
        'never registering' => [fn () => null],
        'past the window' => [function () {
            $this->registerAddress();
            $this->travel(Registering::WINDOW_SECONDS)->seconds();
        }],
        'past the window while the password is checked' => [function () {
            $this->registerAddress();
            $this->travel(Registering::WINDOW_SECONDS - 1)->seconds();
            $hasher = Hash::getFacadeRoot();
            Hash::shouldReceive('make')->andReturnUsing(function (string $password) use ($hasher) {
                $this->travel(1)->second();

                return $hasher->make($password);
            });
        }],
    ]);

    it('sends the finish page back to register without a word', function (Closure $arrange) {
        $arrange->call($this);

        $response = $this->get(route('register.finish'));

        $response->assertRedirectToRoute('register')->assertSessionMissing(Status::SESSION_KEY);
    })->with([
        'never registering' => [fn () => null],
        'past the window' => [function () {
            $this->registerAddress();
            $this->travel(Registering::WINDOW_SECONDS)->seconds();
        }],
    ]);

    it('says the registration expired', function () {
        expect(Status::REGISTRATION_EXPIRED->label())->toBe('Your registration expired. Ask for a new link.');
    });

    it('cancels without saying it expired', function () {
        $this->delete(route('register.finish.cancel'))->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_CANCELLED->value);
    });
});

describe('cancelling', function () {
    it('ends the registration before the account exists, dropping every ceremony slot and creating nothing', function () {
        $this->registerAddress();
        Keystone::guard()->slots()->put('password', 'registration', 'bytes', capSeconds: 300);

        $response = $this->delete(route('register.finish.cancel'));

        $response->assertRedirectToRoute('register')->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_CANCELLED->value);
        expect(Keystone::guard()->registration())->toBeNull()
            ->and(Keystone::guard()->slots()->get('password', 'registration'))->toBeNull();
        $this->assertDatabaseCount('users', 0);
        $this->finishRegistration(new PasswordTypeSupport)->assertRedirectToRoute('register');
    });

    it('says the registration was cancelled', function () {
        expect(Status::REGISTRATION_CANCELLED->label())->toBe('Registration cancelled. No account was created.');
    });

    it('answers the same for a session that registers nothing', function () {
        $response = $this->delete(route('register.finish.cancel'));

        $response->assertRedirectToRoute('register')->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_CANCELLED->value);
    });

    it('sends a signed-in user away', function () {
        $this->signInAccount(new FormTypeSupport, 'jane@example.com');

        $this->delete(route('register.finish.cancel'))->assertRedirect('/');

        expect(Keystone::guard()->check())->toBeTrue();
    });

    it('leaves the ceremony of an enrollment a new account owes alone when nothing is being registered', function () {
        $this->withMandates();
        $this->registerAddress();
        $this->finishRegistration(new PasswordTypeSupport);
        $this->get(route('login.enrollment.start', ['type' => 'code']));
        $ceremony = $this->enrollmentCeremony('code');

        $response = $this->delete(route('register.finish.cancel'));

        $response->assertRedirectToRoute('register')->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_CANCELLED->value);
        expect($ceremony)->not->toBeNull()
            ->and($this->enrollmentCeremony('code'))->toBe($ceremony)
            ->and(Keystone::guard()->pending()?->origin)->toBe(PendingOrigin::REGISTRATION);
    });

    it('takes the change limit', function () {
        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
            $this->delete(route('register.finish.cancel'))->assertRedirectToRoute('register');
        }

        $this->delete(route('register.finish.cancel'))->assertTooManyRequests();
    });

    it('signs out at the enrollment a new account owes, keeping the account, which owes it again at its next sign-in', function () {
        $this->withMandates();
        $this->registerAddress();
        $this->finishRegistration(new PasswordTypeSupport);

        $response = $this->delete(route('login.enrollment.cancel'));

        $response->assertRedirectToRoute('login')->assertSessionHas(Status::SESSION_KEY, Status::REGISTRATION_ENROLLMENT_CANCELLED->value);
        expect(Keystone::guard()->check())->toBeFalse()
            ->and(Keystone::guard()->pending())->toBeNull();
        $this->assertDatabaseCount('users', 1);
        $this->submitSignIn(new PasswordTypeSupport, 'new@example.com', (new PasswordTypeSupport)->validProof(Surface::SIGN_IN))->assertRedirectToRoute('login.enrollment');
        expect(Keystone::guard()->pending()?->origin)->toBe(PendingOrigin::LOGIN);
    });

    it('says the account was kept when its owed enrollment is cancelled', function () {
        expect(Status::REGISTRATION_ENROLLMENT_CANCELLED->label())->toBe('Your account was created. Sign in to finish setting it up.');
    });

    it('asks to sign in again to finish setting up the account, whatever it owes', function () {
        expect(Status::ENROLLMENT_OWED->label())->toBe('Please sign in again to finish setting up your account.');
    });
});

describe('a lost race', function () {
    it('creates nothing when another finish writes the address first, so two finishes make one account', function () {
        $this->withoutMandates();
        $this->registerAddress();
        raceTheAddress();

        $response = $this->finishRegistration(new PasswordTypeSupport);

        $response->assertRedirectToRoute('login')->assertSessionHas(Status::SESSION_KEY, Status::ADDRESS_ALREADY_REGISTERED->value);
        expect(Keystone::guard()->check())->toBeFalse()
            ->and(Keystone::guard()->registration())->toBeNull();
        $this->assertDatabaseMissing('users', ['name' => 'Jane Doe']);
        $this->assertDatabaseCount('user_credentials', 0);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'account.registered']);
        Notification::assertNotSentTo(new AnonymousNotifiable, Welcome::class);
    });
});
