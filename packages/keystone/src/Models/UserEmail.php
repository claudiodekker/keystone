<?php

namespace ClaudioDekker\Keystone\Models;

use ClaudioDekker\Keystone\Accounts\EmailAddress;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Database\Factories\UserEmailFactory;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address an account holds, verified or not.
 *
 * @api
 */
#[UseFactory(UserEmailFactory::class)]
class UserEmail extends Model
{
    /** @use HasFactory<UserEmailFactory> */
    use HasFactory;

    /**
     * Get the account that holds the address.
     *
     * @return BelongsTo<Model&KeystoneUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Keystone::userModel(), 'user_id');
    }

    /**
     * Store the address normalized, so every reader compares it as written.
     *
     * @return Attribute<never, string>
     */
    protected function address(): Attribute
    {
        return Attribute::set(fn (string $address) => EmailAddress::normalize($address));
    }
}
