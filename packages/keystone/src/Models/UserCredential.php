<?php

namespace ClaudioDekker\Keystone\Models;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Database\Factories\UserCredentialFactory;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Credential;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SensitiveParameter;

/**
 * @api
 */
#[UseFactory(UserCredentialFactory::class)]
class UserCredential extends Model
{
    /** @use HasFactory<UserCredentialFactory> */
    use HasFactory;

    /**
     * The attributes kept out of the model's array form.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * Get the account that holds the credential.
     *
     * @return BelongsTo<Model&KeystoneUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Keystone::userModel(), 'user_id');
    }

    /**
     * Get the credential as a type sees it.
     */
    public function toCredential(): Credential
    {
        return new Credential($this->getKey(), $this->secret);
    }

    /**
     * Replace the secret, only while the row still holds the ciphertext this model loaded.
     *
     * Every write encrypts with a fresh IV, so a ciphertext that differs from the loaded one means another write got there first.
     */
    public function updateSecretUnlessChanged(#[SensitiveParameter] string $secret): void
    {
        $loaded = $this->getRawOriginal('secret');
        $encrypted = $this->castAttributeAsEncryptedString('secret', $secret);

        $this->newQuery()
            ->whereKey($this->getKey())
            ->where('secret', $loaded)
            ->update(['secret' => $encrypted]);
    }

    /**
     * Get the attribute casts: core always encrypts the secret, whatever the type hashed into it.
     *
     * @return array{secret: 'encrypted'}
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
        ];
    }
}
