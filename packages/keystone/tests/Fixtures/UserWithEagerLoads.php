<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWithEagerLoads extends User
{
    protected $table = 'users';

    protected $with = ['self'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function self(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id');
    }
}
