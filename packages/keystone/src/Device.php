<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
readonly class Device
{
    /**
     * Create a new device instance.
     */
    public function __construct(
        public ?string $platform = null,
        public ?string $browser = null,
    ) {
        //
    }

    /**
     * Get the label people know the device by, such as "Firefox on Windows", or null when neither is known.
     */
    public function label(): ?string
    {
        if ($this->platform !== null && $this->browser !== null) {
            return __('keystone::alerts.device', ['browser' => $this->browser, 'platform' => $this->platform]);
        }

        return $this->browser ?? $this->platform;
    }
}
