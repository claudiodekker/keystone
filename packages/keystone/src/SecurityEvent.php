<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * @api
 *
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property SecurityEventType $type
 * @property int|string|null $user_id
 * @property Actor $actor
 * @property string|null $operator
 * @property string|null $flow
 * @property string|null $credential_type
 * @property int|null $credential_id
 * @property string|null $credential_label
 * @property string|null $reason
 * @property string|null $ip_address
 * @property string|null $location
 * @property string|null $user_agent
 * @property bool|null $known_device
 * @property string|null $request_id
 */
class SecurityEvent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_security_events';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['ip_address', 'location', 'user_agent'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SecurityEventType::class,
            'actor' => Actor::class,
            'credential_id' => 'integer',
            'ip_address' => 'encrypted',
            'location' => 'encrypted',
            'user_agent' => 'encrypted',
            'known_device' => 'boolean',
        ];
    }

    /**
     * Keep the time the event occurred in UTC, whatever the app's timezone.
     *
     * @return Attribute<CarbonImmutable, DateTimeInterface>
     */
    protected function occurredAt(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => CarbonImmutable::parse($value, 'UTC'),
            set: function (DateTimeInterface $value) {
                $utc = CarbonImmutable::instance($value)->utc();
                $format = $this->getDateFormat();

                return $utc->format($format);
            },
        );
    }
}
