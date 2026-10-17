<?php

namespace ClaudioDekker\Keystone\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Database\Factories\UserSecurityEventFactory;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\SecurityEvents\Actor;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A security event: one entry of the audit trail, with the same fixed fields whatever its type.
 *
 * @property int|string|null $user_id
 *
 * @api
 */
#[UseFactory(UserSecurityEventFactory::class)]
class UserSecurityEvent extends Model
{
    /** @use HasFactory<UserSecurityEventFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Whether the model keeps created and updated timestamps, which the time it occurred at replaces.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes kept out of the model's array form.
     *
     * @var list<string>
     */
    protected $hidden = ['ip_address', 'location', 'user_agent'];

    /**
     * Get the account the event is for, if any.
     *
     * @return BelongsTo<Model&KeystoneUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Keystone::userModel(), 'user_id');
    }

    /**
     * Determine if the event names no account.
     */
    public function isAnonymous(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Get every field in clear, hidden ones included, for the log line.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return (clone $this)->makeVisible($this->hidden)->attributesToArray();
    }

    /**
     * Get the time of the event, kept and read in UTC whatever the app's timezone is.
     *
     * @return Attribute<CarbonImmutable, string|CarbonInterface>
     */
    protected function occurredAt(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => CarbonImmutable::parse($value, 'UTC'),
            set: fn (string|CarbonInterface $value) => CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.u'),
        );
    }

    /**
     * Get the attribute casts.
     *
     * @return array{actor: class-string<Actor>, flow: class-string<Flow>, ip_address: 'encrypted', known_device: 'boolean', location: 'encrypted', type: class-string<SecurityEventType>, user_agent: 'encrypted'}
     */
    protected function casts(): array
    {
        return [
            'actor' => Actor::class,
            'flow' => Flow::class,
            'ip_address' => 'encrypted',
            'known_device' => 'boolean',
            'location' => 'encrypted',
            'type' => SecurityEventType::class,
            'user_agent' => 'encrypted',
        ];
    }
}
