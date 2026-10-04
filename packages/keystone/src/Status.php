<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Http\Request;

/**
 * @api
 */
enum Status: string
{
    case SIGNED_OUT = 'signed-out';
    case SESSION_EXPIRED = 'session-expired';
    case SIGN_IN_CANCELLED = 'sign-in-cancelled';
    case ENROLLMENT_CANCELLED = 'enrollment-cancelled';
    case ENROLLMENT_EXPIRED = 'enrollment-expired';
    case ENROLLMENT_OWED = 'enrollment-owed';

    /**
     * The session key the status is flashed under.
     */
    public const string SESSION_KEY = 'keystone.status';

    /**
     * Flash the status for the next show step.
     */
    public function flash(Request $request): void
    {
        $request->session()->flash(self::SESSION_KEY, $this->value);
    }

    /**
     * Get the status flashed by the previous request.
     */
    public static function flashed(Request $request): ?self
    {
        $value = $request->session()->get(self::SESSION_KEY);

        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * Get the status message in the app's locale.
     */
    public function label(): string
    {
        return __("keystone::messages.status.{$this->value}");
    }
}
