<?php

namespace ClaudioDekker\Keystone\Password;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @internal
 */
class HibpBreachedPasswords implements BreachedPasswords
{
    /**
     * The Pwned Passwords range endpoint, which takes the first characters of a password's SHA-1 hash.
     */
    public const string RANGE_URL = 'https://api.pwnedpasswords.com/range/';

    /**
     * How many characters of the hash leave the app.
     */
    public const int PREFIX_CHARACTERS = 5;

    /**
     * The longest a range query may take before it counts as an outage.
     */
    public const int TIMEOUT_SECONDS = 5;

    /**
     * The warning logged when a range query fails.
     */
    public const string UNAVAILABLE_MESSAGE = "Keystone couldn't reach Pwned Passwords, so a new password was accepted without the breach check.";

    /**
     * Determine if Pwned Passwords lists the password, saying no when it can't be asked.
     */
    public function isBreached(#[\SensitiveParameter] string $password): bool
    {
        $hash = strtoupper(hash('sha1', $password));
        $range = $this->range(substr($hash, 0, self::PREFIX_CHARACTERS));

        return $range !== null && $this->lists($range, substr($hash, self::PREFIX_CHARACTERS));
    }

    /**
     * Get the hash suffixes Pwned Passwords knows for the prefix, or null after logging a warning when it can't answer.
     */
    protected function range(string $prefix): ?string
    {
        try {
            $response = $this->client()->get($prefix);
        } catch (Throwable $e) {
            $this->warn($e::class);

            return null;
        }

        if ($response->status() !== 200) {
            $this->warn("Pwned Passwords answered {$response->status()}.");

            return null;
        }

        return $response->body();
    }

    /**
     * Determine if the range lists the suffix with a count above zero, as padding lines carry a count of zero.
     */
    protected function lists(string $range, string $suffix): bool
    {
        foreach (preg_split('/\R/', $range) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$listed, $count] = explode(':', $line, 2);

            if (hash_equals($suffix, strtoupper($listed)) && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the request every range query starts from: padded, given 5 seconds and never redirected.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl(self::RANGE_URL)
            ->withHeaders(['Add-Padding' => 'true'])
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->withoutRedirecting();
    }

    /**
     * Log on Keystone's channel that the range query failed, naming why but nothing of the password.
     */
    protected function warn(string $reason): void
    {
        $configured = config('keystone.log_channel');

        Log::channel(is_string($configured) ? $configured : null)->warning(self::UNAVAILABLE_MESSAGE, ['reason' => $reason]);
    }
}
