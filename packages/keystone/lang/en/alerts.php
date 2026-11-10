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
    'device' => 'Device: :device',
    'device-name' => ':browser on :platform',
    'if-not-you' => "If this wasn't you, sign in and review your security settings.",
    'ip-address' => 'IP address: :ip_address',
    'ip-address-at' => 'IP address: :ip_address (:location)',
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
    'unknown-device' => 'Unknown device',
    'when' => 'When: :time UTC',
];
