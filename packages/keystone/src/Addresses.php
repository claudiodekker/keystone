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
        $address = trim($address);
        $address = Normalizer::normalize($address, Normalizer::FORM_C) ?: $address;
        $address = Str::lower($address);

        if (! str_contains($address, '@')) {
            return $address;
        }

        $local = Str::beforeLast($address, '@');
        $domain = Str::afterLast($address, '@');
        $domain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII) ?: $domain;

        return $local.'@'.$domain;
    }

    /**
     * Get the id of the one active account that holds the address verified or counting as verified.
     */
    public function resolve(string $address): int|string|null
    {
        $users = $this->users->getTable();
        $key = $this->users->getKeyName();
        $deletedAt = $this->users->getDeletedAtColumn();

        $holdsAVerifiedAddress = fn (Builder $query) => $query
            ->from('user_emails', 'verified')
            ->whereColumn('verified.user_id', 'user_emails.user_id')
            ->whereNotNull('verified.verified_at');

        $countsAsVerified = fn (Builder $query) => $query
            ->whereNotNull('user_emails.verified_at')
            ->orWhereNotExists($holdsAVerifiedAddress);

        $holders = $this->users->getConnection()->table('user_emails')
            ->join($users, "{$users}.{$key}", '=', 'user_emails.user_id')
            ->where('user_emails.address', static::normalize($address))
            ->whereNull("{$users}.{$deletedAt}")
            ->whereNull("{$users}.invalidated_at")
            ->where($countsAsVerified)
            ->distinct()
            ->limit(2)
            ->pluck('user_emails.user_id');

        return $holders->count() === 1 ? $holders->first() : null;
    }

    /**
     * Get every address the account holds, verified or not.
     *
     * @return list<string>
     */
    public function heldBy(Model&KeystoneUser $account): array
    {
        /** @var list<string> */
        return $this->users->getConnection()->table('user_emails')
            ->where('user_id', $account->getKey())
            ->orderBy('id')
            ->pluck('address')
            ->all();
    }

    /**
     * Get the addresses the account's alerts go to: every verified one, or the unverified ones when none is verified.
     *
     * @return list<string>
     */
    public function recipientsOf(Model&KeystoneUser $account): array
    {
        $addresses = $this->users->getConnection()->table('user_emails')
            ->where('user_id', $account->getKey())
            ->orderBy('id');

        $verified = (clone $addresses)->whereNotNull('verified_at')->pluck('address');
        $recipients = $verified->isEmpty() ? $addresses->pluck('address') : $verified;

        /** @var list<string> */
        return $recipients->all();
    }
}
