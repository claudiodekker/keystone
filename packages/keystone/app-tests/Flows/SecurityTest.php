<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SecurityAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\DB;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SecurityAssertions::class));

beforeEach(function () {
    $this->withoutMandates();
});

it('lists the signed-in account\'s credentials and none of another account\'s', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->signInAccount($support);
    $other = $this->createAccount('john@example.com');
    $this->arrangeCredential($other, $support, Surface::SIGN_IN);
    DB::table('user_credentials')->where('user_id', $account->getKey())->update(['label' => 'Jane laptop']);
    DB::table('user_credentials')->where('user_id', $other->getKey())->update(['label' => 'John laptop']);

    $response = $this->get(route('security'));

    $this->assertSecurityPage($response, ['Jane laptop']);
    $this->assertSecurityPageOmits($response, 'John laptop');
});

it('shows the page to a session without sudo', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->signInAccount($support);
    DB::table('user_credentials')->where('user_id', $account->getKey())->update(['label' => 'Jane laptop']);
    $this->delete(route('sudo.end'));

    $response = $this->get(route('security'));

    $this->assertSecurityPage($response, ['Jane laptop']);
    $this->assertAuthenticatedAs($account);
});

it('sends a guest away', function () {
    $this->assertGuestSentAwayFromSecurity($this->get(route('security')));
    $this->assertGuest();
});
