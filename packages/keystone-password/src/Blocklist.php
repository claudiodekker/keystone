<?php

namespace ClaudioDekker\Keystone\Password;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Normalizer;

/**
 * @internal
 */
class Blocklist implements ValidationRule
{
    /**
     * The bundled list of the most common passwords, one lowercase NFC password per line.
     */
    protected const string COMMON_PASSWORDS = __DIR__.'/../resources/common-passwords.txt';

    /**
     * The fewest characters a context word has.
     */
    protected const int MIN_WORD_CHARACTERS = 4;

    /**
     * Create a new blocklist rule instance.
     *
     * @param  list<string>  $context  the names a new password may not borrow a word from, such as "Acme Payroll" or "jane.doe"
     */
    public function __construct(
        protected array $context,
    ) {
        //
    }

    /**
     * Refuse a password that contains a context word or is a common password.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if ($this->containsContextWord($value)) {
            $fail('keystone-password::messages.context_word')->translate();

            return;
        }

        if ($this->isCommon($value)) {
            $fail('keystone-password::messages.common')->translate();
        }
    }

    /**
     * Determine if the password contains a context word, ignoring case.
     */
    protected function containsContextWord(#[\SensitiveParameter] string $password): bool
    {
        $words = $this->words();

        return $words !== [] && Str::contains($password, $words, ignoreCase: true);
    }

    /**
     * Get the context's words: each name split on anything but letters and digits, lowercased, keeping those of at least 4 characters.
     *
     * @return list<string>
     */
    protected function words(): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(implode(' ', $this->context)), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) >= self::MIN_WORD_CHARACTERS)));
    }

    /**
     * Determine if the password is on the bundled list, ignoring case.
     */
    protected function isCommon(#[\SensitiveParameter] string $password): bool
    {
        $normalised = mb_strtolower(Normalizer::normalize($password, Normalizer::FORM_C) ?: $password);

        return in_array($normalised, file(self::COMMON_PASSWORDS, FILE_IGNORE_NEW_LINES) ?: [], true);
    }
}
