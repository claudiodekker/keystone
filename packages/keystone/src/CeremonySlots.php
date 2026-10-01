<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class CeremonySlots
{
    /**
     * The session key holding every slot.
     */
    public const string SESSION_KEY = 'keystone_slots';

    /**
     * Create a new ceremony slots instance.
     */
    public function __construct(
        protected Session $session,
        protected ?CarbonInterface $ownerEndsAt,
    ) {
        //
    }

    /**
     * Open the method's slot for the purpose until its cap or its owner ends.
     */
    public function put(string $method, string $purpose, #[\SensitiveParameter] mixed $value, int $capSeconds): void
    {
        $capEndsAt = Date::now()->addSeconds($capSeconds);
        $endsAt = $this->ownerEndsAt?->lessThan($capEndsAt) ? $this->ownerEndsAt : $capEndsAt;

        $slots = $this->all();

        $slots[$this->key($method, $purpose)] = [
            'value' => Crypt::encrypt($value),
            'ends_at' => $endsAt->getTimestamp(),
        ];

        $this->session->put(self::SESSION_KEY, $slots);
    }

    /**
     * Get the value in the method's slot for the purpose, forgetting the slot once it has ended.
     */
    public function get(string $method, string $purpose): mixed
    {
        $slot = $this->all()[$this->key($method, $purpose)] ?? null;

        if ($slot === null) {
            return null;
        }

        if ($slot['ends_at'] <= Date::now()->getTimestamp()) {
            $this->forget($method, $purpose);

            return null;
        }

        return Crypt::decrypt($slot['value']);
    }

    /**
     * Close the method's slot for the purpose.
     */
    public function forget(string $method, string $purpose): void
    {
        $slots = $this->all();

        unset($slots[$this->key($method, $purpose)]);

        $this->session->put(self::SESSION_KEY, $slots);
    }

    /**
     * Close every slot.
     */
    public function flush(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * Get every open slot, keyed by method and purpose.
     *
     * @return array<string, array{value: string, ends_at: int}>
     */
    protected function all(): array
    {
        $slots = $this->session->get(self::SESSION_KEY);

        return is_array($slots) ? $slots : [];
    }

    /**
     * Get the key of the method's slot for the purpose.
     */
    protected function key(string $method, string $purpose): string
    {
        return "{$method}:{$purpose}";
    }
}
