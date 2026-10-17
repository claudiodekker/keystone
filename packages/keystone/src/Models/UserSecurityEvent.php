<?php

namespace ClaudioDekker\Keystone\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use ClaudioDekker\Keystone\Database\Factories\UserSecurityEventFactory;
use ClaudioDekker\Keystone\SecurityEvents\Actor;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A security event: one entry of the audit trail, with the same fixed fields whatever its type.
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
     * @return array{type: class-string<SecurityEventType>, actor: class-string<Actor>, flow: class-string<Flow>, known_device: 'boolean'}
     */
    protected function casts(): array
    {
        return [
            'actor' => Actor::class,
            'flow' => Flow::class,
            'known_device' => 'boolean',
            'type' => SecurityEventType::class,
        ];
    }
}
