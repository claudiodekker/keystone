<?php

return [
    'failed' => 'These credentials do not match our records.',

    'invalid_credential' => 'The provided credential is invalid.',

    'last_recovery_code' => 'This is your last recovery code. It is kept for account recovery and cannot be used here.',

    'recovery_code_mismatch' => 'The recovery code you entered is incorrect.',

    'enrollment_failed' => 'This method couldn\'t be set up. Please try again or choose another.',

    'throttled' => 'Too many attempts. Please try again in :seconds seconds.',

    'status' => [
        'signed-out' => 'You have been logged out.',
        'session-expired' => 'Your session has expired. Please sign in again.',
        'sign-in-cancelled' => 'Sign-in cancelled. You were not logged in.',
        'second-factor-unavailable' => 'Your second factor is no longer available. Recover your account to sign in again.',
        'enrollment-cancelled' => 'Two-factor setup cancelled. You were not logged in.',
        'enrollment-expired' => 'Your enrollment session expired. Please start again.',
        'enrollment-owed' => 'Finish setting up two-factor authentication to continue.',
    ],
];
