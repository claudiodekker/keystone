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
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

pest()->extend(AppTestCase::class);

describe('the sign-in page', function () {
    it('lists the types serving sign-in with their initiate shapes', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'second-factor', surfaces: ['challenge']));

        $this->get(route('login'))->assertExactJson(['types' => [['type' => 'form', 'shape' => 'form']], 'status' => null]);
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
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, null, null, null))));

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
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($id, null, null, null))));

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
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::rejected('rogue.mismatch', new StoredCredential($id, null, null, 'Forged'))));

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
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::rejected('rogue.mismatch', new StoredCredential($id, null, null, null))));

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

            return Proof::proven(new StoredCredential($id, null, null, null));
        }));

        $this->post(route('login.submit', ['type' => 'rogue']), ['identifier' => 'jane@example.com'])
            ->assertSessionHasErrors(['identifier' => 'These credentials do not match our records.']);

        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'credential_id' => $id, 'reason' => 'keystone.barred']);
    });

    it('flashes back nothing for an identifier that is not a string', function () {
        $this->post(route('login.submit', ['type' => 'form']), ['identifier' => ['jane@example.com'], 'secret' => 'typed']);

        expect(session()->getOldInput())->toBe([]);
    });
});
