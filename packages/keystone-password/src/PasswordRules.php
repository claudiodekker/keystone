<?php

namespace ClaudioDekker\Keystone\Password;

use Closure;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * @api
 */
class PasswordRules
{
    /**
     * The app's rules for a new password, or the callback that returns them.
     *
     * @var (Closure(): mixed)|Rule|ValidationRule|array<array-key, mixed>|string|null
     */
    protected static Closure|Rule|ValidationRule|array|string|null $defaults = null;

    /**
     * Set the rules a new password must meet in place of Keystone's minimum length and blocklist, or null to keep Keystone's.
     *
     * A callback runs each time a new password is checked and may return null to keep Keystone's.
     *
     * @param  (Closure(): mixed)|Rule|ValidationRule|array<array-key, mixed>|string|null  $rules
     */
    public static function defaults(Closure|Rule|ValidationRule|array|string|null $rules): void
    {
        static::$defaults = $rules;
    }

    /**
     * Get the app's rules for a new password, or null when Keystone's own apply.
     *
     * @return list<mixed>|null
     */
    public static function resolve(): ?array
    {
        $rules = value(static::$defaults);

        return $rules === null ? null : array_values(Arr::wrap($rules));
    }
}
