<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\LastSecondFactor;
use ClaudioDekker\Keystone\Exceptions\LastSignInCredential;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;

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
     * Get the account's credentials of every type, disabled ones included, oldest first, with when each was added and last used as ISO 8601.
     *
     * @return list<array{id: int, type: string, label: ?string, addedAt: ?string, lastUsedAt: ?string, disabled: bool}>
     */
    public function ofAccount(int|string $accountId): array
    {
        $rows = $this->query()
            ->where('user_id', $accountId)
            ->orderBy('id')
            ->get(['id', 'type', 'label', 'created_at', 'last_used_at', 'disabled_at']);

        $credentials = $rows->map(fn (object $row) => [
            'id' => (int) $row->id,
            'type' => $row->type,
            'label' => $row->label,
            'addedAt' => $row->created_at === null ? null : Date::parse($row->created_at)->toIso8601String(),
            'lastUsedAt' => $row->last_used_at === null ? null : Date::parse($row->last_used_at)->toIso8601String(),
            'disabled' => $row->disabled_at !== null,
        ]);

        return array_values($credentials->all());
    }

    /**
     * Get the account's credential with the id, of any type, disabled or not.
     *
     * @return array{id: int, type: string, label: ?string}|null
     */
    public function find(int $credentialId, int|string $accountId): ?array
    {
        $row = $this->query()->where('id', $credentialId)->where('user_id', $accountId)->first(['id', 'type', 'label']);

        return $row === null ? null : ['id' => (int) $row->id, 'type' => $row->type, 'label' => $row->label];
    }

    /**
     * Delete the account's credential with the id, of any type, disabled or not.
     */
    public function delete(int $credentialId, int|string $accountId): void
    {
        $this->query()->where('id', $credentialId)->where('user_id', $accountId)->delete();
    }

    /**
     * Delete every credential of the type the account holds, disabled or not, returning how many it held.
     */
    public function deleteOfType(int|string $accountId, string $type): int
    {
        return $this->query()->where('user_id', $accountId)->where('type', $type)->delete();
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
     * Determine if the account holds a usable credential of a listed type that serves the challenge, of another type than the first factor's when there is one.
     */
    public function holdsSecondFactor(int|string $accountId, ?string $firstFactor = null): bool
    {
        return $this->usableServing(Surface::CHALLENGE, $accountId)
            ->when($firstFactor !== null, fn (Builder $query) => $query->where('type', '!=', $firstFactor))
            ->exists();
    }

    /**
     * Lock the account's usable credentials of the listed types that serve the surface and get their ids, read as last committed whatever the transaction read before.
     *
     * @return list<int>
     */
    public function lockServing(int|string $accountId, Surface $surface): array
    {
        $ids = $this->usableServing($surface, $accountId)->orderBy('id')->lockForUpdate()->pluck('id');

        return array_map(intval(...), array_values($ids->all()));
    }

    /**
     * Get why removing the account's credential would be refused: it is the only usable one that signs in, or the only second factor while the app requires one.
     *
     * Inside a transaction it locks the credentials it reads, so the answer holds until the transaction ends.
     */
    public function removalRefusal(int|string $accountId, int $credentialId): LastSignInCredential|LastSecondFactor|null
    {
        return $this->removalRefusals($accountId)[$credentialId] ?? null;
    }

    /**
     * Get why removing each of the account's credentials would be refused, keyed by id, leaving out those whose removal is allowed.
     *
     * Inside a transaction it locks the credentials it reads, so the answers hold until the transaction ends.
     *
     * @return array<int, LastSignInCredential|LastSecondFactor>
     */
    public function removalRefusals(int|string $accountId): array
    {
        $refusals = [];
        $signIn = $this->lockServing($accountId, Surface::SIGN_IN);

        if (count($signIn) === 1) {
            $refusals[$signIn[0]] = new LastSignInCredential;
        }

        if (config('keystone.require_second_factor') !== true) {
            return $refusals;
        }

        $challenge = $this->lockServing($accountId, Surface::CHALLENGE);

        if (count($challenge) === 1) {
            $refusals[$challenge[0]] ??= new LastSecondFactor;
        }

        return $refusals;
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
     * Set when the usable credential of the type was last used to now, or answer false when it is gone or disabled.
     */
    public function stampLastUse(int $credentialId, string $type): bool
    {
        $credential = $this->usable($type)->where('id', $credentialId);

        // MySQL counts the rows an update changed, and a second use within the same second changes none.
        return $credential->clone()->update(['last_used_at' => now()]) === 1 || $credential->exists();
    }

    /**
     * Get the id of the account owning the usable credential of the type.
     */
    public function ownerOf(int $credentialId, string $type): int|string|null
    {
        return $this->usable($type)->where('id', $credentialId)->value('user_id');
    }

    /**
     * Get a query for the account's usable credentials of the listed types that serve the surface.
     */
    protected function usableServing(Surface $surface, int|string $accountId): Builder
    {
        $names = array_map(fn (CredentialType $type) => $type->name(), app(CredentialTypes::class)->serving($surface));

        return $this->query()
            ->where('user_id', $accountId)
            ->whereIn('type', $names)
            ->whereNull('disabled_at');
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
