<?php

return [
    'account' => [
        'suspended' => [
            'advice' => "If you think this is a mistake, contact the site's administrators.",
            'subject' => 'Your account was suspended',
            'summary' => "An administrator suspended your account, so it can't be signed in to.",
        ],
    ],
    'device' => 'Device: :device',
    'device-name' => ':browser on :platform',
    'if-not-you' => "If this wasn't you, sign in and review your security settings.",
    'ip-address' => 'IP address: :ip_address',
    'ip-address-at' => 'IP address: :ip_address (:location)',
    'sessions' => [
        'terminated' => [
            'subject' => 'You were signed out everywhere',
            'summary' => 'An administrator signed your account out of every session.',
        ],
    ],
    'unknown-device' => 'Unknown device',
    'when' => 'When: :time UTC',
];
