<?php

return [
    'failed' => 'These credentials do not match our records.',

    'invalid_credential' => 'The provided credential is invalid.',

    'last_recovery_code' => 'This is your last recovery code. It is kept for account recovery and cannot be used here.',

    'recovery_code_mismatch' => 'The recovery code you entered is incorrect.',

    'enrollment_failed' => 'This method couldn\'t be set up. Please try again or choose another.',

    'throttled' => 'Too many attempts. Please try again in :seconds seconds.',

    'sudo_required' => 'Please confirm it\'s you before making this change.',

    'status' => [
        'signed-out' => 'You have been logged out.',
        'session-expired' => 'Your session has expired. Please sign in again.',
        'sign-in-cancelled' => 'Sign-in cancelled. You were not logged in.',
        'enrollment-cancelled' => 'Two-factor setup cancelled. You were not logged in.',
        'enrollment-expired' => 'Your enrollment session expired. Please start again.',
        'enrollment-owed' => 'Please sign in again to finish setting up two-factor authentication.',
        'sudo-revoked' => 'Sudo has ended. You will be asked to prove your identity again before your next sensitive change.',
        'credential-removed' => 'The credential was removed. Your other sessions were signed out.',
        'credential-not-found' => 'That credential was not found. It may already have been removed.',
    ],
];
