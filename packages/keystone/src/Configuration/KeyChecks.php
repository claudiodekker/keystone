<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Http\HardeningSettings;
use ClaudioDekker\Keystone\Http\SiteData;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Arr;

/**
 * @internal
 */
class KeyChecks
{
    /**
     * Create the checks.
     */
    public function __construct(
        protected Config $config,
        protected HardeningSettings $hardening,
    ) {
        //
    }

    /**
     * Get the failure of every key that holds a bad value.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return [
            ...$this->absoluteLifetime(),
            ...$this->allowances(),
            ...$this->clearSiteData(),
            ...$this->eventsEnabled(),
            ...$this->frameAncestors(),
            ...$this->logChannel(),
            ...$this->trustedOrigins(),
        ];
    }

    /**
     * Get the failure of session.absolute_lifetime_seconds when it is neither null nor a positive integer.
     *
     * @return list<string>
     */
    protected function absoluteLifetime(): array
    {
        $lifetime = $this->config->get('keystone.session.absolute_lifetime_seconds');

        if ($lifetime === null || $this->isPositiveInteger($lifetime)) {
            return [];
        }

        return ['keystone.session.absolute_lifetime_seconds must be null or a positive integer.'];
    }

    /**
     * Get the failure of each rate limit that isn't a positive integer.
     *
     * @return list<string>
     */
    protected function allowances(): array
    {
        $keys = ['keystone.rate_limits.failed_attempts_per_hour'];

        foreach (StepKind::cases() as $kind) {
            $keys[] = "keystone.rate_limits.requests_per_minute.{$kind->value}";
        }

        $bad = array_filter($keys, fn (string $key) => ! $this->isPositiveInteger($this->config->get($key)));

        return array_map(fn (string $key) => "{$key} must be a positive integer.", array_values($bad));
    }

    /**
     * Get the failure of events.enabled when it isn't a boolean.
     *
     * @return list<string>
     */
    protected function eventsEnabled(): array
    {
        if (is_bool($this->config->get('keystone.events.enabled'))) {
            return [];
        }

        return ['keystone.events.enabled must be true or false.'];
    }

    /**
     * Get the failure of log_channel when it names no channel the logging config holds.
     *
     * @return list<string>
     */
    protected function logChannel(): array
    {
        if ($this->isLogChannel($this->config->get('keystone.log_channel'))) {
            return [];
        }

        return ['keystone.log_channel must be null or the name of a channel in the logging config.'];
    }

    /**
     * Get the failure of hardening.frame_ancestors when it isn't a list of single CSP sources.
     *
     * @return list<string>
     */
    protected function frameAncestors(): array
    {
        if ($this->hardening->frameAncestors() !== null) {
            return [];
        }

        return ['keystone.hardening.frame_ancestors must be a list of CSP sources, each a single expression without spaces, semicolons or commas.'];
    }

    /**
     * Get the failure of trusted_origins when it isn't a list of serialized origins.
     *
     * @return list<string>
     */
    protected function trustedOrigins(): array
    {
        if ($this->hardening->trustedOrigins() !== null) {
            return [];
        }

        return ['keystone.trusted_origins must be a list of origins, each a scheme and a host with nothing after it, such as https://admin.example.com.'];
    }

    /**
     * Get the failure of clear_site_data when it isn't a list of the kinds a browser can clear.
     *
     * @return list<string>
     */
    protected function clearSiteData(): array
    {
        if ($this->hardening->siteData() !== null) {
            return [];
        }

        $kinds = Arr::join(array_map(fn (SiteData $kind) => $kind->value, SiteData::cases()), ', ', ' and ');

        return ["keystone.clear_site_data must be a list of {$kinds}."];
    }

    /**
     * Determine if the value selects a log channel that exists.
     */
    protected function isLogChannel(mixed $channel): bool
    {
        if ($channel === null) {
            return true;
        }

        $channels = $this->config->get('logging.channels');

        return is_string($channel)
            && is_array($channels)
            && array_key_exists($channel, $channels);
    }

    /**
     * Determine if the value is an integer of at least 1.
     */
    protected function isPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value >= 1;
    }
}
