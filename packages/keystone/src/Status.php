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
    case SUDO_REVOKED = 'sudo-revoked';
    case CREDENTIAL_REMOVED = 'credential-removed';
    case CREDENTIAL_REPLACED = 'credential-replaced';
    case CREDENTIAL_NOT_FOUND = 'credential-not-found';
    case OTHER_SESSIONS_REVOKED = 'other-sessions-revoked';
    case SESSION_REVOKED = 'session-revoked';
    case SESSION_NOT_FOUND = 'session-not-found';
    case SESSIONS_UNAVAILABLE = 'sessions-unavailable';
    case ENROLLED = 'enrolled';
    case RECOVERY_CODES_REGENERATED = 'recovery-codes-regenerated';
    case RECOVERY_CODES_EXPIRED = 'recovery-codes-expired';
    case REGISTRATION_UNAVAILABLE = 'registration-unavailable';
    case ADDRESS_ALREADY_REGISTERED = 'address-already-registered';

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
