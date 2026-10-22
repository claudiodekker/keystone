<?php

namespace ClaudioDekker\Keystone\Http;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Read the three config keys that loosen the hardening floor, and say that a key is malformed by giving back null.
 *
 * @internal
 */
class HardeningSettings
{
    /**
     * Create the settings.
     */
    public function __construct(
        protected Config $config,
    ) {
        //
    }

    /**
     * Get the CSP sources that may frame Keystone's pages.
     *
     * @return list<string>|null
     */
    public function frameAncestors(): ?array
    {
        return $this->strings('keystone.hardening.frame_ancestors', $this->isSource(...));
    }

    /**
     * Get the other origins that pass the cross-site check.
     *
     * @return list<string>|null
     */
    public function trustedOrigins(): ?array
    {
        return $this->strings('keystone.trusted_origins', $this->isOrigin(...));
    }

    /**
     * Get the kinds of site data an ended session clears.
     *
     * @return list<SiteData>|null
     */
    public function siteData(): ?array
    {
        $kinds = $this->list('keystone.clear_site_data');

        if ($kinds === null) {
            return null;
        }

        $parsed = array_map(fn (mixed $kind) => is_string($kind) ? SiteData::tryFrom($kind) : null, $kinds);

        if (in_array(null, $parsed, true)) {
            return null;
        }

        return array_values(array_unique($parsed, SORT_REGULAR));
    }

    /**
     * Get the key's list when every item passes the test, or null.
     *
     * @param  callable(mixed): bool  $isValid
     * @return list<string>|null
     */
    protected function strings(string $key, callable $isValid): ?array
    {
        $values = $this->list($key);

        if ($values === null || ! array_all($values, $isValid)) {
            return null;
        }

        return $values;
    }

    /**
     * Get the key's value when it is a list, or null.
     *
     * @return list<mixed>|null
     */
    protected function list(string $key): ?array
    {
        $values = $this->config->get($key);

        if (! is_array($values) || ! array_is_list($values)) {
            return null;
        }

        return $values;
    }

    /**
     * Determine if the value is a single CSP source expression.
     */
    protected function isSource(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[^\s;,]+$/D', $value) === 1;
    }

    /**
     * Determine if the value is a serialized origin: a scheme and a host, with nothing after them.
     */
    protected function isOrigin(mixed $value): bool
    {
        return is_string($value) && preg_match('~^https?://[^\s/?#@\\\\]+$~D', $value) === 1;
    }
}
