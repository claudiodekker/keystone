<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Normalizer;

/**
 * @internal
 */
class Addresses
{
    /**
     * Create a new addresses instance.
     */
    public function __construct(
        protected Model&KeystoneUser $users,
    ) {
        //
    }

    /**
     * Normalize an email address to the form Keystone stores and compares.
     */
    public static function normalize(string $address): string
    {
        $address = Str::lower(Normalizer::normalize(trim($address), Normalizer::FORM_C) ?: trim($address));

        if (! str_contains($address, '@')) {
            return $address;
        }

        $local = Str::beforeLast($address, '@');
        $domain = Str::afterLast($address, '@');

        return $local.'@'.(idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII) ?: $domain);
    }

    /**
     * Get the id of the one active account that holds the address verified or counting as verified.
     */
    public function resolve(string $address): int|string|null
    {
        $users = $this->users->getTable();
        $key = $this->users->getKeyName();

        $holders = $this->users->getConnection()->table('user_emails')
            ->join($users, "{$users}.{$key}", '=', 'user_emails.user_id')
            ->where('user_emails.address', static::normalize($address))
            ->whereNull("{$users}.{$this->users->getDeletedAtColumn()}")
            ->whereNull("{$users}.invalidated_at")
            ->where(fn (Builder $query) => $query
                ->whereNotNull('user_emails.verified_at')
                ->orWhereNotExists(fn (Builder $query) => $query
                    ->from('user_emails', 'verified')
                    ->whereColumn('verified.user_id', 'user_emails.user_id')
                    ->whereNotNull('verified.verified_at')))
            ->distinct()
            ->limit(2)
            ->pluck('user_emails.user_id');

        return $holders->count() === 1 ? $holders->first() : null;
    }
}
