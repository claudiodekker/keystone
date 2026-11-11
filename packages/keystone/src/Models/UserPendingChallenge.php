<?php

namespace ClaudioDekker\Keystone\Models;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Database\Factories\UserPendingChallengeFactory;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|string $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 *
 * @api
 */
#[UseFactory(UserPendingChallengeFactory::class)]
class UserPendingChallenge extends Model
{
    /** @use HasFactory<UserPendingChallengeFactory> */
    use HasFactory;

    /**
     * The column that keeps when the row was updated, which a held challenge never is.
     *
     * @var null
     */
    public const UPDATED_AT = null;

    /**
     * The attributes kept out of the model's array form.
     *
     * @var list<string>
     */
    protected $hidden = ['ip_address', 'user_agent'];

    /**
     * Get the account whose sign-in was held at the challenge.
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
     * @return array{created_at: 'immutable_datetime', ip_address: 'encrypted', user_agent: 'encrypted'}
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'ip_address' => 'encrypted',
            'user_agent' => 'encrypted',
        ];
    }
}
