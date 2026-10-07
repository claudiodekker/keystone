<?php

return [
    'review' => 'If this wasn\'t you, sign in and review your security settings.',

    'unknown' => 'Unknown',
    'unknown_device' => 'Unknown device',
    'device' => ':browser on :platform',
    'more' => ':values and :count more',

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
        'challenge' => [
            'abandoned' => [
                'subject' => 'A sign-in to your account had not finished its second step after 7 minutes',
                'what' => 'Someone passed the first step of signing in to your account from a browser it hasn\'t signed in from recently, and had not finished the second step 7 minutes later. Whoever it was has what the first step asks for, such as your password.',
                'count' => '{1} This happened once.|[2,*] This happened :count times.',
            ],
        ],
        'credential' => [
            'added' => [
                'subject' => 'A sign-in method was added to your account',
                'what' => 'A new way to sign in or confirm it\'s you was added to your account.',
            ],
        ],
        'device_cookie' => [
            'reused' => [
                'subject' => 'A browser you sign in from may have been copied',
                'what' => 'Your account was signed in to from a browser whose device cookie had already been replaced by a later sign-in. That happens when the cookie was copied to another browser, and rarely when a sign-in was cut off. Your account now knows only the browser that just signed in, and you will be told again if the other one signs in.',
            ],
        ],
        'limit' => [
            'tripped' => [
                'subject' => 'Sign-ins to your account were paused',
                'what' => 'Too many wrong answers were given while signing in to your account, so further attempts with that kind of credential are refused for a while. Wrong answers from browsers you signed in from recently are counted apart, so those browsers can still sign in unless the wrong answers came from them.',
            ],
        ],
        'recovery_code' => [
            'used' => [
                'subject' => 'A recovery code was used on your account',
                'what' => 'One of your recovery codes was used to answer the second-factor challenge when signing in. Each code works only once.',
                'remaining' => '{0} You have no recovery codes left.|{1} You have 1 recovery code left.|[2,*] You have :count recovery codes left.',
            ],
        ],
        'recovery_codes' => [
            'generated' => [
                'subject' => 'Your recovery codes were replaced',
                'what' => 'A new set of recovery codes was saved for your account. Your old codes no longer work.',
            ],
        ],
        'sessions' => [
            'terminated' => [
                'subject' => 'Your account was signed out everywhere',
                'what' => 'An administrator ended every session of your account, so it is now signed out on every device.',
            ],
        ],
        'signed_in' => [
            'subject' => 'New sign-in to your account',
            'what' => 'Your account was signed in to from a browser it hasn\'t signed in from recently.',
        ],
    ],
];
