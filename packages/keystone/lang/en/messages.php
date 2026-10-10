<?php

return [
    'failed' => 'These credentials do not match our records.',

    'invalid_credential' => 'The provided credential is invalid.',

    'last_recovery_code' => 'This is your last recovery code. It is kept for account recovery and cannot be used here.',

    'recovery_code_mismatch' => 'The recovery code you entered is incorrect.',

    'enrollment_failed' => 'This method couldn\'t be set up. Please try again or choose another.',

    'throttled' => 'Too many attempts. Please try again in :seconds seconds.',

    'last_sign_in_credential' => 'You cannot remove your only way to sign in.',

    'last_second_factor' => 'You cannot remove your last two-factor credential while two-factor authentication is required.',

    'sudo_required' => 'Please confirm it\'s you before making this change.',

    'current_session' => 'You cannot revoke your current session; sign out instead.',

    'status' => [
        'signed-out' => 'You have been logged out.',
        'session-expired' => 'Your session has expired. Please sign in again.',
        'sign-in-cancelled' => 'Sign-in cancelled. You were not logged in.',
        'enrollment-cancelled' => 'Two-factor setup cancelled. You were not logged in.',
        'enrollment-expired' => 'Your enrollment session expired. Please start again.',
        'enrollment-owed' => 'Please sign in again to finish setting up your account.',
        'sudo-revoked' => 'Sudo has ended. You will be asked to prove your identity again before your next sensitive change.',
        'credential-removed' => 'The credential was removed. Your other sessions were signed out.',
        'credential-replaced' => 'The credential was replaced. Your other sessions were signed out.',
        'credential-not-found' => 'That credential was not found. It may already have been removed.',
        'enrolled' => 'The new credential was added.',
        'recovery-codes-regenerated' => 'Your recovery codes were regenerated.',
        'recovery-codes-expired' => 'Those recovery codes expired before they were saved. Save this new set instead.',
        'other-sessions-revoked' => 'Your other sessions were signed out.',
        'session-revoked' => 'The session was signed out.',
        'session-not-found' => 'That session was not found. It may already have been signed out.',
        'sessions-unavailable' => 'Your sessions cannot be listed in this app.',
        'registration-unavailable' => 'Registration is not available.',
        'address-already-registered' => 'That email address is already registered. Please sign in instead.',
        'registration-cancelled' => 'Registration cancelled. No account was created.',
        'registration-expired' => 'Your registration expired. Ask for a new link.',
        'registration-enrollment-cancelled' => 'Your account was created. Sign in to finish setting it up.',
    ],
];
