<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @internal
 */
class RecoveryCodes
{
    /**
     * How many codes a set holds.
     */
    public const int SET_SIZE = 8;

    /**
     * How many characters a code holds.
     */
    public const int LENGTH = 26;

    /**
     * How many characters each dash-separated block of a shown code holds.
     */
    public const int BLOCK_LENGTH = 5;

    /**
     * The characters a code is drawn from.
     */
    public const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * The shape of a code imported from Fortify, which matches only exactly as typed.
     */
    public const string FORTIFY_PATTERN = '/^[A-Za-z0-9]{10}-[A-Za-z0-9]{10}$/';

    /**
     * Create a new recovery codes instance on the model's connection.
     */
    public function __construct(
        protected Model $model,
    ) {
        //
    }

    /**
     * Make a new set of codes, each shown as dash-separated blocks.
     *
     * @return list<string>
     */
    public function generate(): array
    {
        return array_map(fn () => $this->code(), range(1, self::SET_SIZE));
    }

    /**
     * Replace the account's codes with the set, storing only the digest of each normalized code.
     *
     * @param  list<string>  $codes
     */
    public function replace(int|string $accountId, #[\SensitiveParameter] array $codes): void
    {
        $this->query()->where('user_id', $accountId)->delete();

        $rows = array_map(fn (string $code) => [
            'user_id' => $accountId,
            'code_hash' => hash('sha256', static::normalize($code)),
            'created_at' => now(),
        ], $codes);

        $this->query()->insert($rows);
    }

    /**
     * Get the id of the account's code the typed one matches: a native code in any case, with or without dashes, or a Fortify code exactly as typed.
     */
    public function find(int|string $accountId, #[\SensitiveParameter] string $typed): ?int
    {
        $digests = [hash('sha256', static::normalize($typed))];

        if (preg_match(self::FORTIFY_PATTERN, $typed) === 1) {
            $digests[] = hash('sha256', $typed);
        }

        $id = $this->query()->where('user_id', $accountId)->whereIn('code_hash', $digests)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Count the account's unspent codes.
     */
    public function remaining(int|string $accountId): int
    {
        return $this->query()->where('user_id', $accountId)->count();
    }

    /**
     * Delete the code, only while nothing else spent it first.
     */
    public function spend(int $id): bool
    {
        return $this->query()->where('id', $id)->delete() === 1;
    }

    /**
     * Normalize a typed code: dashes and whitespace removed, then uppercased.
     */
    public static function normalize(#[\SensitiveParameter] string $typed): string
    {
        return Str::of($typed)->replaceMatches('/[\s-]+/u', '')->upper()->value();
    }

    /**
     * Make one code, each character drawn uniformly from the alphabet, shown as dash-separated blocks.
     */
    protected function code(): string
    {
        $code = Collection::times(self::LENGTH, fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');

        return Str::of($code)->split(self::BLOCK_LENGTH)->implode('-');
    }

    /**
     * Get a query for the recovery codes table on the model's connection.
     */
    protected function query(): Builder
    {
        return $this->model->getConnection()->table('user_recovery_codes');
    }
}
