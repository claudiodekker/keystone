<?php

return [
    'review' => 'If this wasn\'t you, sign in and review your security settings.',

    'unknown' => 'Unknown',
    'unknown_device' => 'Unknown device',
    'device' => ':browser on :platform',

    'fields' => [
        'when' => 'When',
        'ip_address' => 'IP address',
        'location' => 'Location',
        'device' => 'Device',
        'credential' => 'Credential',
    ],

    'types' => [
        'account' => [
            'suspended' => [
                'subject' => 'Your account was suspended',
                'what' => 'An administrator suspended your account. It is signed out on every device and can\'t sign in until it is unsuspended.',
            ],
            'unsuspended' => [
                'subject' => 'Your account was unsuspended',
                'what' => 'An administrator lifted the suspension of your account, so it can sign in again.',
            ],
        ],
        'recovery_code' => [
            'used' => [
                'subject' => 'A recovery code was used on your account',
                'what' => 'One of your recovery codes was used to answer the second-factor challenge when signing in. Each code works only once.',
                'remaining' => '{0} You have no recovery codes left.|{1} You have 1 recovery code left.|[2,*] You have :count recovery codes left.',
            ],
        ],
        'sessions' => [
            'terminated' => [
                'subject' => 'Your account was signed out everywhere',
                'what' => 'An administrator ended every session of your account, so it is now signed out on every device.',
            ],
        ],
    ],
];
