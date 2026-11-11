<?php

use ClaudioDekker\Keystone\Notifications\SecurityAlert;

return [

    /*
    |--------------------------------------------------------------------------
    | Clear Site Data
    |--------------------------------------------------------------------------
    |
    | What the browser clears when Keystone ends a session: any of cache,
    | cookies, storage and executionContexts. An empty list sends none.
    | Clearing cookies signs the browser out of everything it holds.
    |
    */

    'clear_site_data' => ['cache', 'storage'],

    /*
    |--------------------------------------------------------------------------
    | Security Events
    |--------------------------------------------------------------------------
    |
    | Every security event is recorded unless enabled is set to false. That
    | turns the recorder off in any environment, so no trail is kept and
    | no alert is sent. Only the boolean false turns the recorder off.
    |
    */

    'events' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hardening
    |--------------------------------------------------------------------------
    |
    | The CSP sources that may frame Keystone's pages, such as the host of
    | a white-labelled portal. Listing any drops X-Frame-Options, so the
    | listed sources can frame the sign-in page and click on it.
    |
    */

    'hardening' => [
        'frame_ancestors' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Location
    |--------------------------------------------------------------------------
    |
    | An alert names where its IP address is when stevebauman/location is
    | installed. Its drivers that send the address over plain HTTP are
    | skipped, unless you set allow_plaintext_driver to true here.
    |
    */

    'ip_location' => [
        'allow_plaintext_driver' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The log channel every security event's log line goes to, by its name
    | in the logging config. Leave it null to write to the app's default
    | channel. Every line carries the message keystone.security_event.
    |
    */

    'log_channel' => null,

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    |
    | The credential types to offer, by name. Null offers every installed
    | type on every surface it serves. A name with a list of surfaces
    | narrows it to those, and a type left out is offered nowhere.
    |
    */

    'methods' => null,

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Each type of security event mails its account's owner the notification
    | class named here, built from the event alone. Null sends no alert of
    | that type, so an app can silence each type it handles on its own.
    |
    */

    'notifications' => [
        'account.suspended' => SecurityAlert::class,
        'account.unsuspended' => SecurityAlert::class,
        'challenge.abandoned' => SecurityAlert::class,
        'credential.added' => SecurityAlert::class,
        'device_cookie.reused' => SecurityAlert::class,
        'limit.tripped' => SecurityAlert::class,
        'proof.rejected' => null,
        'recovery_code.used' => SecurityAlert::class,
        'recovery_codes.generated' => null,
        'request.rejected' => null,
        'session.ended' => null,
        'sessions.terminated' => SecurityAlert::class,
        'sign_in.held' => null,
        'sign_in.voided' => null,
        'signed_in' => SecurityAlert::class,
        'signed_out' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | How many failed attempts an account gets per credential type and flow
    | in an hour, and how many requests of each step kind one IP address
    | or one signed-in account gets a minute. The windows are fixed.
    |
    */

    'rate_limits' => [
        'failed_attempts_per_hour' => 20,
        'requests_per_minute' => [
            'change' => 10,
            'submit' => 10,
            'view' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remember Me
    |--------------------------------------------------------------------------
    |
    | How many seconds a browser that ticked remember me stays signed in,
    | counted from that sign-in, however often it comes back. Zero hides
    | the box and turns remember me off, ignoring cookies issued before.
    |
    */

    'remember' => [
        'lifetime_seconds' => 60 * 60 * 24 * 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Require Recovery Codes
    |--------------------------------------------------------------------------
    |
    | While recovery codes are required, a sign-in of an account holding no
    | code saves a set before it gets in, and the challenge keeps its last
    | code for account recovery. Set it to false to make codes optional.
    |
    */

    'require_recovery_codes' => true,

    /*
    |--------------------------------------------------------------------------
    | Require Second Factor
    |--------------------------------------------------------------------------
    |
    | While a second factor is required, a sign-in of an account holding no
    | second factor enrolls one before it gets in, and a session whose own
    | account no longer holds one is signed out on its next request too.
    |
    */

    'require_second_factor' => true,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many seconds Keystone keeps knowing a browser after the account's
    | last sign-in from it. Signing in from a browser it no longer knows
    | alerts the owner, and the nightly prune deletes what it forgot.
    |
    */

    'retention' => [
        'known_devices_seconds' => 60 * 60 * 24 * 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Session
    |--------------------------------------------------------------------------
    |
    | How many seconds a session lasts after its sign-in, however busy it
    | stays. Null turns it off. Laravel's session.lifetime still ends an
    | idle session, and every request extends that one, not this one.
    |
    */

    'session' => [
        'absolute_lifetime_seconds' => 12 * 60 * 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Origins
    |--------------------------------------------------------------------------
    |
    | Other origins of this app whose pages may submit to Keystone's routes,
    | written as scheme and host, such as https://admin.example.com. Any
    | page on a listed origin can then sign users in and out.
    |
    */

    'trusted_origins' => [],

];
