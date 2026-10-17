<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read what Keystone recorded during a test: the stored events and the security event log lines.
 *
 * @api
 */
class RecordedSecurityEvents
{
    /**
     * Get every stored event, oldest first.
     *
     * @return Collection<int, UserSecurityEvent>
     */
    public function stored(): Collection
    {
        return UserSecurityEvent::query()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the events stored for the user, oldest first.
     *
     * @return Collection<int, UserSecurityEvent>
     */
    public function for(KeystoneUser $user): Collection
    {
        return UserSecurityEvent::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }
}
