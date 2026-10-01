<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @api
 */
trait HasKeystone
{
    use SoftDeletes;

    /**
     * The users columns only Keystone writes.
     *
     * @var list<string>
     */
    public const array KEYSTONE_COLUMNS = [
        'credential_epoch',
        'credential_epoch_moved_at',
        'deleted_at',
        'invalidated_at',
        'suspended_at',
        'has_second_factor',
        'has_recovery_codes',
    ];

    /**
     * Hide Keystone's columns and guard them against mass assignment.
     */
    public function initializeHasKeystone(): void
    {
        $this->mergeHidden(self::KEYSTONE_COLUMNS);

        $this->mergeGuarded(self::KEYSTONE_COLUMNS);
    }
}
