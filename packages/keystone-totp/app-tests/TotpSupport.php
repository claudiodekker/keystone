<?php

namespace ClaudioDekker\Keystone\Totp\AppTests;

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Totp\Totp;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use Illuminate\Support\Facades\Date;
use ParagonIE\ConstantTime\Base32;
use PHPUnit\Framework\Assert;

/**
 * @api
 */
class TotpSupport implements CredentialTypeSupport
{
    /**
     * The Base32 key every arranged credential holds, 160 bits as an authenticator app's QR code carries it.
     */
    public const string KEY = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * Get the installed TOTP type, naming the fix when it isn't registered.
     */
    public function type(): CredentialType
    {
        return app(CredentialTypes::class)->find('totp', Surface::CHALLENGE)
            ?? Assert::fail('The TOTP type is not registered. Install claudiodekker/keystone-totp.');
    }

    /**
     * Give the user a TOTP credential holding the fixture key, with no code accepted yet.
     */
    public function arrangeCredential(Surface $surface, KeystoneUser $user): static
    {
        return $this->arrangeKey($user, static::KEY);
    }

    /**
     * Get the fields that prove the fixture key: the code of the current step.
     *
     * @return array<string, mixed>
     */
    public function validProof(Surface $surface, KeystoneUser $user): array
    {
        return ['code' => $this->code()];
    }

    /**
     * Get a code the fixture key makes for no step the window accepts.
     *
     * @return array<string, mixed>
     */
    public function rejectedProof(Surface $surface): array
    {
        $window = config()->integer('keystone.totp.window_steps');
        $accepted = array_map(fn (int $steps) => $this->code($steps), range(-$window, $window));

        $code = 0;

        while (in_array(sprintf('%06d', $code), $accepted, true)) {
            $code++;
        }

        return ['code' => sprintf('%06d', $code)];
    }

    /**
     * Give the user a TOTP credential holding the Base32 key, as an import would write one.
     */
    public function arrangeKey(KeystoneUser $user, string $key): static
    {
        $secret = new TotpSecret(Base32::decodeUpper($key), lastStep: null);

        UserCredential::factory()->create([
            'user_id' => $user->getAuthIdentifier(),
            'type' => 'totp',
            'secret' => $secret->toStored(),
        ]);

        return $this;
    }

    /**
     * Get the code the key makes for the step the given number of steps from now, the fixture key unless given.
     */
    public function code(int $steps = 0, string $key = self::KEY): string
    {
        $now = Totp::stepAt(Date::now()->getTimestamp());

        return Totp::code(Base32::decodeUpper($key), $now + $steps);
    }
}
