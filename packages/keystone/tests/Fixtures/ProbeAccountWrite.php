<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Actions\Concerns\ChangesAccounts;
use ClaudioDekker\Keystone\KeystoneUser;
use Closure;
use Illuminate\Database\Eloquent\Model;

class ProbeAccountWrite
{
    use ChangesAccounts;

    public function handle(Model&KeystoneUser $account, Closure $apply): mixed
    {
        return $this->changeAccount($account, $apply);
    }
}
