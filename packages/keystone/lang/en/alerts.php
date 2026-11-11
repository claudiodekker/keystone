<?php

return [
    'account' => [
        'suspended' => [
            'advice' => "If you think this is a mistake, contact the site's administrators.",
            'subject' => 'Your account was suspended',
            'summary' => "An administrator suspended your account, so it can't be signed in to.",
        ],
        'unsuspended' => [
            'subject' => 'Your account can sign in again',
            'summary' => 'An administrator lifted the suspension of your account, so you can sign in again.',
        ],
    ],
    'credential' => [
        'added' => [
            'subject' => 'A sign-in method was added',
            'summary' => 'A new way to sign in was added to your account.',
        ],
    ],
    'device' => 'Device: :device',
    'device_cookie' => [
        'reused' => [
            'subject' => 'A copy of a browser you use may have signed in',
            'summary' => "Your account was signed in to from a browser holding a copy of what another browser you signed in from keeps. Either browser could be someone else's.",
        ],
    ],
    'device-name' => ':browser on :platform',
    'if-not-you' => "If this wasn't you, sign in and review your security settings.",
    'ip-address' => 'IP address: :ip_address',
    'ip-address-at' => 'IP address: :ip_address (:location)',
    'limit' => [
        'tripped' => [
            'subject' => 'Too many failed sign-in attempts',
            'summary' => 'Someone got your sign-in wrong too many times, so further attempts are refused for a while. Browsers you signed in from before can still sign in.',
        ],
    ],
    'recovery_code' => [
        'used' => [
            'left' => '{0} You have no recovery codes left.|{1} You have 1 recovery code left.|[2,*] You have :count recovery codes left.',
            'subject' => 'A recovery code was used',
            'summary' => 'A recovery code was used to sign in to your account.',
        ],
    ],
    'sessions' => [
        'terminated' => [
            'subject' => 'You were signed out everywhere',
            'summary' => 'An administrator signed your account out of every session.',
        ],
    ],
    'signed_in' => [
        'subject' => 'New sign-in to your account',
        'summary' => "Your account was signed in to from a browser it hasn't used before.",
    ],
    'unknown-device' => 'Unknown device',
    'when' => 'When: :time UTC',
];
