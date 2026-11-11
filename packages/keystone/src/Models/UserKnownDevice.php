<?php

namespace ClaudioDekker\Keystone\Models;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Database\Factories\UserKnownDeviceFactory;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|string $user_id
 * @property string $digest
 * @property string $device_digest
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $last_seen_at
 *
 * @api
 */
#[UseFactory(UserKnownDeviceFactory::class)]
class UserKnownDevice extends Model
{
    /** @use HasFactory<UserKnownDeviceFactory> */
    use HasFactory;

    /**
     * The attributes kept out of the model's array form.
     *
     * @var list<string>
     */
    protected $hidden = ['device_digest', 'digest', 'ip_address', 'user_agent'];

    /**
     * Get the account that signed in from the device.
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
     * @return array{ip_address: 'encrypted', last_seen_at: 'immutable_datetime', user_agent: 'encrypted'}
     */
    protected function casts(): array
    {
        return [
            'ip_address' => 'encrypted',
            'last_seen_at' => 'immutable_datetime',
            'user_agent' => 'encrypted',
        ];
    }
}
