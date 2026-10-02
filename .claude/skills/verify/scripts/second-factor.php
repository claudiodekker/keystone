<?php

use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base32;
use Workbench\App\Models\User;

// Enrolling an authenticator has no UI yet, so the fixture writes the credential the way enrolment will.
$account = User::findOrFail(DB::table('user_emails')->where('address', 'jane@example.com')->value('user_id'));

DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->delete();

$key = random_bytes(20);

(new Credentials($account))->store(
    $account,
    app(CredentialTypes::class)->find('totp', Surface::CHALLENGE) ?? throw new LogicException('The workbench needs keystone-totp.'),
    identifier: null,
    secret: (new TotpSecret($key, lastStep: null))->toStored(),
);

$codes = (new RecoveryCodes($account))->generate();
(new RecoveryCodes($account))->replace($account->getKey(), $codes);

echo json_encode(['email' => 'jane@example.com', 'password' => 'password', 'totp_key' => Base32::encodeUpperUnpadded($key), 'recovery_codes' => $codes]).PHP_EOL;
