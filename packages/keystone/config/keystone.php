<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credential Methods
    |--------------------------------------------------------------------------
    |
    | The credential types people may use, and on which surfaces. Null allows
    | every installed type on every surface it declares. A bare entry keeps
    | its type's surfaces; a list of surfaces narrows, but never widens.
    |
    */

    'methods' => null,

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | How long a session may stay signed in, counted from the sign-in and
    | never extended by activity. Laravel's session.lifetime still ends
    | idle sessions sooner. A null lets an active session live forever.
    |
    */

    'session' => [
        'absolute_lifetime_seconds' => 43200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | How many requests to each kind of step one IP address or signed-in
    | account may make a minute, and how many wrong answers an account
    | may give per credential type and flow an hour before refusal.
    |
    */

    'rate_limits' => [
        'requests_per_minute' => [
            'view' => 60,
            'start' => 10,
            'submit' => 10,
            'change' => 10,
        ],
        'failed_attempts_per_hour' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Events
    |--------------------------------------------------------------------------
    |
    | Whether Keystone records security events at all, and the log channel
    | their lines are written to. A null channel means your default one;
    | turning recording off drops your users' audit trail completely.
    |
    */

    'events' => [
        'enabled' => true,
    ],

    'log_channel' => null,

    /*
    |--------------------------------------------------------------------------
    | Hardening
    |--------------------------------------------------------------------------
    |
    | The CSP sources that may frame Keystone's pages, the other origins the
    | cross-site check accepts, and what an ended session clears from the
    | browser. By default nothing frames them and only your origin may.
    |
    */

    'hardening' => [
        'frame_ancestors' => [],
    ],

    'trusted_origins' => [],

    'clear_site_data' => ['cache', 'storage'],

];
