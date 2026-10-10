<?php

return [
    'links' => [
        'expires' => '{1} The link works for 1 minute and only once.|[2,*] The link works for :count minutes and only once.',

        'registration' => [
            'subject' => 'Confirm your email address',
            'what' => 'Someone asked to create an account with this email address. Open the link to confirm the address is yours and finish creating your account.',
            'action' => 'Confirm your email address',
            'ignore' => 'If you didn\'t ask for this, ignore this email. No account is created without the link.',
        ],
    ],

    'welcome' => [
        'subject' => 'Welcome to :app',
        'created' => 'Your :app account is ready.',
        'sign_in' => 'Sign in with this email address from now on.',
    ],
];
