<?php

namespace ClaudioDekker\Keystone\Accounts;

use Normalizer;

/**
 * An email address's identity: trimmed, NFC, lowercase, with its domain as IDN ASCII, and no accent folding.
 *
 * @internal
 */
class EmailAddress
{
    /**
     * Normalize a typed or stored address, so one mailbox always compares equal.
     */
    public static function normalize(string $address): string
    {
        $address = mb_strtolower(Normalizer::normalize(trim($address)));

        if (! str_contains($address, '@')) {
            return $address;
        }

        [$local, $domain] = explode('@', $address, 2);

        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $local.'@'.($ascii === false ? $domain : $ascii);
    }
}
