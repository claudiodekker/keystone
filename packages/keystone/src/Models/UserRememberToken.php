<?php

namespace ClaudioDekker\Keystone\Models;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Database\Factories\UserRememberTokenFactory;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|string $user_id
 * @property string $digest
 * @property int $epoch
 * @property CarbonImmutable $expires_at
 *
 * @api
 */
#[UseFactory(UserRememberTokenFactory::class)]
class UserRememberToken extends Model
{
    /** @use HasFactory<UserRememberTokenFactory> */
    use HasFactory;

    /**
     * The attributes kept out of the model's array form.
     *
     * @var list<string>
     */
    protected $hidden = ['digest'];

    /**
     * Get the account the browser is remembered for.
     *
     * @return BelongsTo<Model&KeystoneUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Keystone::userModel(), 'user_id');
    }

    /**
     * Get the attributes to cast.
     *
     * @return array{epoch: 'integer', expires_at: 'immutable_datetime'}
     */
    protected function casts(): array
    {
        return [
            'epoch' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
