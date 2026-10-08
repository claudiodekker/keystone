<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\CredentialRemovalAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\DB;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(CredentialRemovalAssertions::class),
    AppTestCase::assertions(SudoAssertions::class),
);

beforeEach(function () {
    $this->withoutMandates();
});

it('names the credential, then removes it and keeps the remover signed in', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $this->arrangeCredential($account, $this->supportsFor(Surface::CHALLENGE)[0], Surface::CHALLENGE);
    $id = DB::table('user_credentials')->where('user_id', $account->getKey())->max('id');
    DB::table('user_credentials')->where('id', $id)->update(['label' => 'Jane phone']);

    $this->assertRemovalPage($this->get(route('security.credentials.remove', ['credential' => $id])), 'Jane phone');
    $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

    $this->assertCredentialRemoved($response);
    $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
    $this->assertDatabaseHas('user_security_events', ['type' => 'credential.removed', 'user_id' => $account->getKey(), 'credential_id' => $id]);
    $this->assertAuthenticatedAs($account);
});

it('sends the user away from another account\'s credential, removing nothing', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $this->signInAccount($support);
    $other = $this->createAccount('john@example.com');
    $this->arrangeCredential($other, $support, Surface::SIGN_IN);
    $id = DB::table('user_credentials')->where('user_id', $other->getKey())->value('id');

    $this->assertCredentialNotFound($this->get(route('security.credentials.remove', ['credential' => $id])));
    $this->assertCredentialNotFound($this->delete(route('security.credentials.remove.submit', ['credential' => $id])));
    $this->assertDatabaseHas('user_credentials', ['id' => $id]);
});

it('asks for sudo before removing anything', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $this->arrangeCredential($account, $this->supportsFor(Surface::CHALLENGE)[0], Surface::CHALLENGE);
    $id = DB::table('user_credentials')->where('user_id', $account->getKey())->max('id');
    $this->delete(route('sudo.end'));

    $this->assertSudoRequired($this->delete(route('security.credentials.remove.submit', ['credential' => $id])));
    $this->assertDatabaseHas('user_credentials', ['id' => $id]);
});
