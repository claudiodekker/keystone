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
        'address' => [
            'claim_attempted' => [
                'subject' => 'Someone tried to sign up with your email address',
                'what' => 'Someone just tried to sign up with your email address. Your account wasn\'t changed. If this was you, recover your account.',
            ],
            'lost' => [
                'subject' => 'An email address was removed from your account',
                'what' => 'An email address was removed from your account because another account verified it first. Your account still has its other addresses.',
            ],
        ],
        'challenge' => [
            'abandoned' => [
                'subject' => 'A sign-in to your account was started but not finished',
                'what' => 'Someone passed the first step of signing in to your account from a browser it hasn\'t signed in from recently, and had not finished the second step 7 minutes later. Whoever it was has what the first step asks for, such as your password.',
                'count' => '{1} This happened once.|[2,*] This happened :count times.',
            ],
        ],
        'credential' => [
            'added' => [
                'subject' => 'A sign-in method was added to your account',
                'what' => 'A new way to sign in or confirm it\'s you was added to your account.',
            ],
            'removed' => [
                'subject' => 'A sign-in method was removed from your account',
                'what' => 'A way to sign in or confirm it\'s you was removed from your account. Every other session of your account was signed out.',
            ],
            'replaced' => [
                'subject' => 'A sign-in method on your account was changed',
                'what' => 'A way to sign in or confirm it\'s you was replaced with a new one. The old one no longer works. Every other session of your account was signed out.',
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
                'subject' => 'Attempts to prove it\'s you were paused',
                'what' => 'Your account got too many wrong answers to a sign-in step or to a check before a sensitive change. Further attempts with that kind of credential are refused for a while. Browsers you signed in from recently count their own wrong answers, so they are only paused when the wrong answers came from them.',
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
                'what' => 'A new set of recovery codes was saved for your account. Your old codes no longer work. Every other session of your account was signed out.',
            ],
        ],
        'session' => [
            'revoked' => [
                'subject' => 'One of your sessions was signed out',
                'what' => 'One session of your account was signed out from your security settings. The details below are of the session that was signed out. Your other sessions stay signed in.',
            ],
        ],
        'sessions' => [
            'revoked_others' => [
                'subject' => 'Your other sessions were signed out',
                'what' => 'Every other session of your account was signed out from your security settings. The device that did it stays signed in.',
            ],
            'terminated' => [
                'subject' => 'Your account was signed out everywhere',
                'what' => 'An administrator ended every session of your account, so it is now signed out on every device.',
            ],
        ],
        'signed_in' => [
            'subject' => 'New sign-in to your account',
            'what' => 'Your account was signed in to from a browser it hasn\'t signed in from recently.',
        ],
        'sudo' => [
            'failed' => [
                'subject' => 'A wrong answer was given before a change to your account',
                'what' => 'A signed-in session of your account was asked to prove it\'s you before a sensitive change, and gave a wrong answer. Whoever it was is signed in to your account, or holds a copy of its session, and got no further.',
            ],
            'network_changed' => [
                'subject' => 'Your session moved to another network',
                'what' => 'A session signed in to your account tried to make a sensitive change from a different network. It had proved it\'s you on another network a short while before. It must prove it\'s you again before it can make the change. A session cookie copied to another computer looks like this.',
            ],
        ],
    ],
];
