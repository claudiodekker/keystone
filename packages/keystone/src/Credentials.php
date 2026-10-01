<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;

/**
 * @internal
 */
class Credentials
{
    /**
     * Create a new credentials instance.
     */
    public function __construct(
        protected Model&KeystoneUser $users,
    ) {
        //
    }

    /**
     * Store a credential of the type for the account, encrypting its identifier and secret.
     */
    public function store(
        Model&KeystoneUser $account,
        CredentialType $type,
        ?string $identifier,
        #[\SensitiveParameter] ?string $secret,
        ?string $label = null,
    ): int {
        return $this->query()->insertGetId([
            'user_id' => $account->getKey(),
            'type' => $type->name(),
            'identifier' => $identifier === null ? null : Crypt::encryptString($identifier),
            'identifier_hash' => $identifier === null ? null : hash('sha256', $identifier),
            'label' => $label,
            'secret' => $secret === null ? null : Crypt::encryptString($secret),
            'served_challenge' => isset($type->surfaces()[Surface::CHALLENGE->value]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Get the account's usable credentials of the type, decrypted.
     *
     * @return list<StoredCredential>
     */
    public function ofType(int|string $accountId, string $type): array
    {
        $rows = $this->usable($type)
            ->where('user_id', $accountId)
            ->orderBy('id')
            ->get();

        $credentials = $rows->map(fn (object $row) => new StoredCredential(
            id: $row->id,
            identifier: $row->identifier === null ? null : Crypt::decryptString($row->identifier),
            secret: $row->secret === null ? null : Crypt::decryptString($row->secret),
            label: $row->label,
        ));

        return array_values($credentials->all());
    }

    /**
     * Get the names of the types the account holds a usable credential of.
     *
     * @return list<string>
     */
    public function typesOf(int|string $accountId): array
    {
        $types = $this->query()->where('user_id', $accountId)->whereNull('disabled_at')->distinct()->pluck('type');

        return array_values($types->all());
    }

    /**
     * Determine if the account holds a challenge credential of another type than the first factor's.
     */
    public function holdsSecondFactor(int|string $accountId, string $firstFactor): bool
    {
        return $this->query()
            ->where('user_id', $accountId)
            ->where('type', '!=', $firstFactor)
            ->where('served_challenge', true)
            ->whereNull('disabled_at')
            ->exists();
    }

    /**
     * Replace the secret of the usable credential of the type, only while it still holds the secret that was verified.
     */
    public function replaceSecret(StoredCredential $credential, string $type, #[\SensitiveParameter] string $secret): bool
    {
        return $this->users->getConnection()->transaction(function () use ($credential, $type, $secret) {
            $row = $this->usable($type)->where('id', $credential->id)->lockForUpdate()->first(['secret']);
            $held = $row?->secret === null ? null : Crypt::decryptString($row->secret);

            if ($row === null || $held !== $credential->secret) {
                return false;
            }

            return $this->query()->where('id', $credential->id)->update([
                'secret' => Crypt::encryptString($secret),
                'updated_at' => now(),
            ]) === 1;
        });
    }

    /**
     * Get the id of the account owning the usable credential of the type.
     */
    public function ownerOf(int $credentialId, string $type): int|string|null
    {
        return $this->usable($type)->where('id', $credentialId)->value('user_id');
    }

    /**
     * Get a query for the usable credentials of the type.
     */
    protected function usable(string $type): Builder
    {
        return $this->query()->where('type', $type)->whereNull('disabled_at');
    }

    /**
     * Get a query for the credentials table on the user model's connection.
     */
    protected function query(): Builder
    {
        return $this->users->getConnection()->table('user_credentials');
    }
}
