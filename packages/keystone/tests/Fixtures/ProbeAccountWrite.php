<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Actions\Concerns\ChangesAccounts;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventType;
use Closure;
use Illuminate\Database\Eloquent\Model;

class ProbeAccountWrite
{
    use ChangesAccounts;

    public function handle(Model&KeystoneUser $account, Closure $write): mixed
    {
        return $this->changeAccount($account, fn (Model&KeystoneUser $locked) => $write($locked, $this));
    }

    public function endSessionsOf(Model&KeystoneUser $account): void
    {
        $this->endSessions($account);
    }

    public function recordAbout(Model&KeystoneUser $account, SecurityEventType $type, ?string $operator = null, bool $alert = true): void
    {
        $this->record($type, $account, actor: Actor::OPERATOR, operator: $operator, alert: $alert);
    }

    public function recipients(): array
    {
        return $this->recipients;
    }
}
