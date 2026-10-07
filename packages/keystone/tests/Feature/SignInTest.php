<?php

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\RogueType;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

pest()->extend(AppTestCase::class);

describe('the sign-in page', function () {
    it('lists the types serving sign-in with their initiate shapes', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'second-factor', surfaces: ['challenge']));

        $this->get(route('login'))->assertExactJson(['types' => [['type' => 'form', 'shape' => 'form'], ['type' => 'password', 'shape' => 'form']], 'status' => null, 'rememberOffered' => true]);
    });

    it('carries the translated status after signing out', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport)->validProof(Surface::SIGN_IN)]);
        $this->post(route('logout'));

        $this->get(route('login'))->assertJsonPath('status', 'You have been logged out.');
    });

    it('carries the app\'s own translation of the status', function () {
        $this->app['translator']->addLines(['messages.status.signed-out' => 'See you soon.'], 'en', 'keystone');
        session()->flash('keystone.status', 'signed-out');

        $this->get(route('login'))->assertJsonPath('status', 'See you soon.');
    });

    it('ignores an unknown status', function () {
        session()->flash('keystone.status', 'no-such-status');

        $this->get(route('login'))->assertJsonPath('status', null);
    });
});

describe('proofs', function () {
    it('refuses a proof when the type fails, and reports the failure', function () {
        Exceptions::fake();
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => throw new RuntimeException('Broken method.')));
        $this->createAccount();

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com'])
            ->assertSessionHasErrors(['identifier' => 'These credentials do not match our records.']);

        $this->assertGuest();
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Broken method.');
    });

    it('refuses a proof naming a credential the subject does not hold', function (Closure $credential) {
        $jane = $this->createAccount();
        $john = $this->createAccount('john@example.com');
        $id = $credential->call($this, $jane, $john);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, identifier: null, secret: null, label: null))));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'proof.rejected',
            'user_id' => $jane->getKey(),
            'credential_id' => null,
            'reason' => 'keystone.foreign_credential',
        ]);
    })->with([
        'another account\'s' => fn ($jane, $john) => rogueCredential($john),
        'disabled' => function ($jane) {
            $id = rogueCredential($jane);
            DB::table('user_credentials')->where('id', $id)->update(['disabled_at' => now()]);

            return $id;
        },
        'of another type' => fn ($jane) => rogueCredential($jane, 'form'),
        'missing' => fn () => 999,
    ]);

    it('refuses a proven proof when no account was named', function () {
        $id = rogueCredential($this->createAccount());
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, identifier: null, secret: null, label: null))));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'nobody@example.com']);

        $this->assertGuest();
    });

    it('gives the type only the subject\'s usable credentials of its own type, and only its own input', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $id = rogueCredential($account);
        rogueCredential($this->createAccount('john@example.com'));
        $this->app->make(CredentialTypes::class)->register($rogue = new RogueType(fn () => Proof::rejected('rogue.mismatch')));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com', 'secret' => 'typed', 'extra' => 'ignored']);

        expect($rogue->calls)->toBe([[Surface::SIGN_IN, ['secret' => 'typed'], [$id]]]);
    });

    it('lets the type do its work when no account was named', function () {
        $this->app->make(CredentialTypes::class)->register($rogue = new RogueType(fn () => Proof::rejected('rogue.mismatch')));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'nobody@example.com', 'secret' => 'typed']);

        expect($rogue->calls)->toBe([[Surface::SIGN_IN, ['secret' => 'typed'], []]]);
    });

    it('names the subject\'s credential a rejection points at, with its stored label', function () {
        $account = $this->createAccount();
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'label' => 'Laptop']);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::rejected('rogue.mismatch', new StoredCredential($id, identifier: null, secret: null, label: 'Forged'))));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertDatabaseHas('user_security_events', [
            'type' => 'proof.rejected',
            'user_id' => $account->getKey(),
            'credential_type' => 'rogue',
            'credential_id' => $id,
            'credential_label' => 'Laptop',
            'reason' => 'rogue.mismatch',
        ]);
    });

    it('names no credential of another account in a rejection', function () {
        $jane = $this->createAccount();
        $id = rogueCredential($this->createAccount('john@example.com'));
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::rejected('rogue.mismatch', new StoredCredential($id, identifier: null, secret: null, label: null))));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertDatabaseHas('user_security_events', ['user_id' => $jane->getKey(), 'credential_id' => null, 'reason' => 'rogue.mismatch']);
    });

    it('records a suspended account\'s valid proof as refused', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);

        $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport)->validProof(Surface::SIGN_IN)]);

        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'proof.rejected',
            'user_id' => $account->getKey(),
            'credential_id' => DB::table('user_credentials')->value('id'),
            'reason' => 'keystone.barred',
        ]);
    });
});

describe('updated secrets', function () {
    it('stores the updated secret a proven proof carries, without moving the epoch', function () {
        $account = $this->createAccount();
        $id = rogueCredential($account);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, identifier: null, secret: null, label: null), updatedSecret: fn () => 'rehashed')));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertAuthenticatedAs($account);
        expect(Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret')))->toBe('rehashed')
            ->and(DB::table('users')->where('id', $account->getKey())->value('credential_epoch'))->toEqual(0);
    });

    it('stores no updated secret once the credential changed after the type verified it', function (array $change) {
        $account = $this->createAccount();
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('verified')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, identifier: null, secret: 'verified', label: null), updatedSecret: fn () => 'rehashed')));
        Event::listen(Login::class, fn () => DB::table('user_credentials')->where('id', $id)->update($change));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertAuthenticatedAs($account);
        expect(Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret')))->not->toBe('rehashed');
    })->with([
        'its secret' => fn () => ['secret' => Crypt::encryptString('changed')],
        'its type' => fn () => ['type' => 'other'],
        'disabled' => fn () => ['disabled_at' => now()],
    ]);

    it('stores the secret an advanced proof moves its credential on to', function () {
        $account = $this->createAccount();
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('step-1')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::advanced(new StoredCredential($id, identifier: null, secret: 'step-1', label: null), 'step-2')));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertAuthenticatedAs($account);
        expect(Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret')))->toBe('step-2');
    });

    it('refuses an advanced proof once another proof moved its credential on first', function () {
        $account = $this->createAccount();
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('step-1')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(function () use ($id) {
            DB::table('user_credentials')->where('id', $id)->update(['secret' => Crypt::encryptString('step-2')]);

            return Proof::advanced(new StoredCredential($id, identifier: null, secret: 'step-1', label: null), 'step-2');
        }));

        $response = $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $response->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'credential_id' => $id, 'reason' => 'keystone.superseded']);
    });

    it('makes and stores no updated secret when the account is refused', function () {
        $account = $this->createAccount();
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('verified')]);
        DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);
        $made = false;
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, identifier: null, secret: 'verified', label: null), updatedSecret: function () use (&$made) {
            $made = true;

            return 'rehashed';
        })));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertGuest();
        expect($made)->toBeFalse()
            ->and(Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret')))->toBe('verified');
    });

    it('signs in and reports the failure when the updated secret cannot be stored', function () {
        Exceptions::fake();
        $account = $this->createAccount();
        $id = rogueCredential($account);
        $this->app->make(CredentialTypes::class)->register(new RogueType(function () use ($id) {
            DB::table('user_credentials')->where('id', $id)->update(['secret' => 'not-encrypted']);

            return Proof::proven(new StoredCredential($id, identifier: null, secret: null, label: null), updatedSecret: fn () => 'rehashed');
        }));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertAuthenticatedAs($account);
        Exceptions::assertReported(DecryptException::class);
    });

    it('signs in and reports the failure when the updated secret cannot be made', function () {
        Exceptions::fake();
        $account = $this->createAccount();
        $id = rogueCredential($account);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, identifier: null, secret: null, label: null), updatedSecret: fn () => throw new RuntimeException('Cannot hash.'))));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com']);

        $this->assertAuthenticatedAs($account);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Cannot hash.');
    });
});

describe('account lookup', function () {
    it('signs in the account a swapped lookup names', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->app->bind(AccountLookup::class, fn () => new class($account->getKey()) extends AccountLookup
        {
            public function __construct(protected int $id)
            {
                //
            }

            public function handle(string $identifier): int|string|null
            {
                return $identifier === 'employee-7' ? $this->id : null;
            }
        });

        $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'employee-7', ...(new FormTypeSupport)->validProof(Surface::SIGN_IN)]);

        $this->assertAuthenticatedAs($account);
    });

    it('still refuses a disabled account a swapped lookup names', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        DB::table('users')->where('id', $account->getKey())->update(['invalidated_at' => now()]);
        $this->app->bind(AccountLookup::class, fn () => new class($account->getKey()) extends AccountLookup
        {
            public function __construct(protected int $id)
            {
                //
            }

            public function handle(string $identifier): int|string|null
            {
                return $this->id;
            }
        });

        $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'anything', ...(new FormTypeSupport)->validProof(Surface::SIGN_IN)]);

        $this->assertGuest();
    });
});

function rogueCredential($account, string $type = 'rogue'): int
{
    return DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => $type]);
}

describe('failures around the proof', function () {
    it('refuses exactly like an unknown account when a stored credential cannot be decrypted', function () {
        Exceptions::fake();
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        DB::table('user_credentials')->update(['secret' => 'not-encrypted']);
        $proof = (new FormTypeSupport)->validProof(Surface::SIGN_IN);

        $this->assertIndistinguishable(
            fn () => $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'jane@example.com', ...$proof]),
            fn () => $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'nobody@example.com', ...$proof]),
        );
        $this->assertGuest();
        Exceptions::assertReported(DecryptException::class);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'credential_id' => null, 'reason' => 'keystone.verify_failed']);
    });

    it('refuses an account suspended while its proof was checked', function () {
        $account = $this->createAccount();
        $id = rogueCredential($account);
        $this->app->make(CredentialTypes::class)->register(new RogueType(function () use ($account, $id) {
            DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);

            return Proof::proven(new StoredCredential($id, identifier: null, secret: null, label: null));
        }));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com'])
            ->assertSessionHasErrors(['identifier' => 'These credentials do not match our records.']);

        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'credential_id' => $id, 'reason' => 'keystone.barred']);
    });

    it('surfaces a Login listener\'s failure instead of refusing the signed-in account', function () {
        Exceptions::fake();
        Event::listen(Login::class, fn () => throw new InvalidArgumentException('Listener broke.'));
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);

        $response = $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport)->validProof(Surface::SIGN_IN)]);

        $response->assertServerError();
        $this->assertAuthenticatedAs($account);
        Exceptions::assertReported(fn (InvalidArgumentException $e) => $e->getMessage() === 'Listener broke.');
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
    });

    it('flashes back nothing for an identifier that is not a string', function () {
        $this->post(route('login.submit', ['type' => 'form']), ['identifier' => ['jane@example.com'], 'secret' => 'typed']);

        expect(session()->getOldInput())->toBe([]);
    });
});

function signInFrom(AppTestCase $test, int $address, string $type, array $input = []): TestResponse
{
    $test->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$address}"]);

    return $test->post(route('login.submit', ['type' => $type]), ['identifier' => 'jane@example.com', ...$input]);
}

describe('rate limits', function () {
    it('refuses a spent limit with 429, Retry-After and the message', function () {
        $this->freezeSecond();

        foreach (range(1, 10) as $ignored) {
            $this->post(route('login.submit', ['type' => 'form']));
        }

        $this->post(route('login.submit', ['type' => 'form']))
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertSee('Too many attempts. Please try again in 60 seconds.');
    });

    it('keeps a spent limit out of the exception reports', function () {
        $this->freezeSecond();

        foreach (range(1, 10) as $ignored) {
            $this->post(route('login.submit', ['type' => 'form']));
        }

        Exceptions::fake();

        $response = $this->post(route('login.submit', ['type' => 'form']));

        $response->assertTooManyRequests();
        Exceptions::assertNothingReported();
    });

    it('takes the request limit from keystone.rate_limits', function () {
        $this->freezeSecond();
        config(['keystone.rate_limits.requests_per_minute.submit' => 2]);
        $this->post(route('login.submit', ['type' => 'form']));
        $this->post(route('login.submit', ['type' => 'form']));

        $response = $this->post(route('login.submit', ['type' => 'form']));

        $response->assertTooManyRequests();
    });

    it('takes the failed-attempt allowance from keystone.rate_limits', function () {
        $this->createAccount();
        $this->app->make(CredentialTypes::class)->register($rogue = new RogueType(fn () => Proof::rejected('rogue.mismatch')));
        config(['keystone.rate_limits.failed_attempts_per_hour' => 3]);

        foreach (range(1, 5) as $address) {
            signInFrom($this, $address, 'rogue');
        }

        expect($rogue->calls)->toHaveCount(3);
    });

    it('never lets the type verify more answers than the allowance', function () {
        $this->createAccount();
        $this->app->make(CredentialTypes::class)->register($rogue = new RogueType(fn () => Proof::rejected('rogue.mismatch')));

        foreach (range(1, 25) as $address) {
            signInFrom($this, $address, 'rogue');
        }

        expect($rogue->calls)->toHaveCount(20);
    });

    it('gives back the attempt when the type fails', function () {
        $this->createAccount();
        $this->app->make(CredentialTypes::class)->register($rogue = new RogueType(fn () => throw new RuntimeException('Broken method.')));

        foreach (range(1, 21) as $address) {
            signInFrom($this, $address, 'rogue');
        }

        expect($rogue->calls)->toHaveCount(21);
    });

    it('counts a suspended account\'s valid proofs like wrong answers', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);

        foreach (range(1, 20) as $address) {
            signInFrom($this, $address, 'form', (new FormTypeSupport)->validProof(Surface::SIGN_IN));
        }

        signInFrom($this, 21, 'form', (new FormTypeSupport)->validProof(Surface::SIGN_IN))->assertTooManyRequests();
    });

    it('counts an address apart from its diacritic variant, whether or not it names an account', function (string $spent, string $variant) {
        $this->createAccount();
        $this->app->make(CredentialTypes::class)->register($rogue = new RogueType(fn () => Proof::rejected('rogue.mismatch')));
        config(['keystone.rate_limits.failed_attempts_per_hour' => 3]);

        foreach (range(1, 3) as $ignored) {
            $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => $spent]);
        }

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => $variant]);

        expect($rogue->calls)->toHaveCount(4);
    })->with([
        'a real account' => ['jane@example.com', 'jané@example.com'],
        'a made-up address' => ['nobody@example.com', 'nóbody@example.com'],
    ]);

    it('counts every spelling of one address together, whether or not it names an account', function (string $spent, string $spelling) {
        $this->createAccount('jane@xn--bcher-kva.example');
        $this->app->make(CredentialTypes::class)->register($rogue = new RogueType(fn () => Proof::rejected('rogue.mismatch')));
        config(['keystone.rate_limits.failed_attempts_per_hour' => 3]);

        foreach (range(1, 3) as $ignored) {
            $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => $spent]);
        }

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => $spelling]);

        expect($rogue->calls)->toHaveCount(3);
    })->with([
        'a real account' => ['jane@bücher.example', 'Jane@xn--bcher-kva.example'],
        'a made-up address' => ['nobody@bücher.example', 'Nobody@xn--bcher-kva.example'],
    ]);
});

describe('the methods allow-list', function () {
    it('lists only the types keystone.methods allows on sign-in', function (array $methods) {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'both', surfaces: ['sign-in', 'challenge']));
        config(['keystone.methods' => $methods]);

        $response = $this->get(route('login'));

        $response->assertExactJson(['types' => [['type' => 'password', 'shape' => 'form']], 'status' => null, 'rememberOffered' => true]);
    })->with([
        'a bare entry' => [['password', 'both' => ['challenge']]],
        'a narrowed entry' => [['password' => ['sign-in'], 'both' => ['challenge']]],
    ]);

    it('refuses a valid proof of a type keystone.methods leaves off sign-in', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        config(['keystone.methods' => ['password']]);

        $response = $this->post(route('login.submit', ['type' => 'form']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport)->validProof(Surface::SIGN_IN)]);

        $response->assertSessionHasErrors(['identifier' => __('keystone::messages.failed')]);
        $this->assertGuest();
    });
});
