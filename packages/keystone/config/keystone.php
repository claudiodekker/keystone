<?php

use ClaudioDekker\Keystone\Notifications\SecurityAlert;

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
    | Second Factor
    |--------------------------------------------------------------------------
    |
    | Whether every account must hold a second factor: a credential of a
    | listed type that answers the challenge. An account without one
    | enrolls it before it gets in.
    |
    */

    'require_second_factor' => true,

    /*
    |--------------------------------------------------------------------------
    | Recovery Codes
    |--------------------------------------------------------------------------
    |
    | Whether every account must hold recovery codes. An account without
    | them saves a new set before it gets in, and its last code can't
    | answer a challenge: it is kept to recover the account later.
    |
    */

    'require_recovery_codes' => true,

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | How long a session may stay signed in, counted from the sign-in and
    | never extended by activity. Laravel's session.lifetime still ends
    | idle sessions sooner. A null lets a busy session live forever.
    |
    */

    'session' => [
        'absolute_lifetime_seconds' => 43200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Remember Me
    |--------------------------------------------------------------------------
    |
    | How long a ticked "remember me" keeps a browser signed in, counted
    | from the sign-in that issued its token and never extended by a
    | return. A 0 turns this off, so no cookie is issued or read.
    |
    */

    'remember' => [
        'lifetime_seconds' => 2592000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sudo
    |--------------------------------------------------------------------------
    |
    | How long a session keeps the sudo that its sign-in brought, a grant
    | it needs to change how the account signs in. It counts from the
    | sign-in and nothing extends it. It must be at least 1 second.
    |
    */

    'sudo' => [
        'lifetime_seconds' => 900,
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
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long Keystone keeps what it learns about your users. A browser
    | nobody signed in from for this long is a new device again, and
    | it is pruned nightly. Every value must be at least 1 second.
    |
    */

    'retention' => [
        'known_devices_seconds' => 7776000,
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
    | Security Alerts
    |--------------------------------------------------------------------------
    |
    | The notification each type of security event mails its account's
    | owner, keyed by type. Name your own class to change a mail, or
    | set a type to null to silence it. Nothing silences them all.
    |
    */

    'notifications' => [
        'account.suspended' => SecurityAlert::class,
        'account.unsuspended' => SecurityAlert::class,
        'challenge.abandoned' => SecurityAlert::class,
        'credential.added' => SecurityAlert::class,
        'device_cookie.reused' => SecurityAlert::class,
        'limit.tripped' => SecurityAlert::class,
        'recovery_code.used' => SecurityAlert::class,
        'recovery_codes.generated' => SecurityAlert::class,
        'sessions.terminated' => SecurityAlert::class,
        'signed_in' => SecurityAlert::class,
        'sudo.network_changed' => SecurityAlert::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Location
    |--------------------------------------------------------------------------
    |
    | Whether stevebauman/location may use a driver that sends your
    | users' IP addresses over plain http. Keystone skips such a
    | driver unless you allow it, trying the next one instead.
    |
    */

    'ip_location' => [
        'allow_plaintext_driver' => false,
    ],

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
