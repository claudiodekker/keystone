<?php

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
