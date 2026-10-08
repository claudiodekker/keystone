<?php

use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base32;
use Workbench\App\Models\User;

// Seed a TOTP credential so a recipe can start past the enrollment a first sign-in is held at.
$account = User::findOrFail(DB::table('user_emails')->where('address', 'jane@example.com')->value('user_id'));

DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->delete();

$key = random_bytes(20);

(new Credentials($account))->store(
    $account,
    app(CredentialTypes::class)->find('totp', Surface::CHALLENGE) ?? throw new LogicException('The workbench needs keystone-totp.'),
    identifier: null,
    secret: (new TotpSecret($key, lastStep: null))->toStored(),
);

$recoveryCodes = new RecoveryCodes($account);
$codes = $recoveryCodes->generate();
$recoveryCodes->replace($account->getKey(), $codes);

echo json_encode(['email' => 'jane@example.com', 'password' => 'password', 'totp_key' => Base32::encodeUpperUnpadded($key), 'recovery_codes' => $codes]).PHP_EOL;
