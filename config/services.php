<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pixelforge
    |--------------------------------------------------------------------------
    |
    | One API key per environment: each key has its own webhook URL, its own
    | signing secret, and its own GET /v1/usage report, so a deployed app's
    | numbers can be reconciled independently of local development.
    |
    */

    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

        // Keep well inside the provider's 10s webhook budget and well under the
        // queue worker's timeout.
        'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),
        'connect_timeout' => (int) env('PIXELFORGE_CONNECT_TIMEOUT', 5),

        // What we add on top of the provider's cost, as an exact percentage.
        'markup_percent' => (int) env('PIXELFORGE_MARKUP_PERCENT', 25),

        // The provider rejects a 4th concurrent generation with 429
        // concurrency_limit_exceeded. We gate on our own count of in-flight
        // generations so we rarely provoke it.
        'max_in_flight' => (int) env('PIXELFORGE_MAX_IN_FLIGHT', 3),

        // How long to wait when our own in-flight count is at the cap. Matches
        // the Retry-After the provider sends with concurrency_limit_exceeded.
        'concurrency_wait_seconds' => (int) env('PIXELFORGE_CONCURRENCY_WAIT', 5),

        // Submit retry budget. Beyond this, with the outcome still unknown, the
        // generation is parked as `unknown` and resolved by idempotent replay.
        'submit_max_attempts' => (int) env('PIXELFORGE_SUBMIT_MAX_ATTEMPTS', 6),

        // How long we keep trying to resolve an `unknown` generation before
        // giving up, releasing the hold and telling the user plainly.
        'resolve_unknown_for_minutes' => (int) env('PIXELFORGE_RESOLVE_UNKNOWN_MINUTES', 60),

        // Reject webhook signatures older than this, to stop replays.
        'webhook_tolerance_seconds' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

        // How stale an unsettled generation may get before the reconciler polls
        // the provider for it directly.
        'reconcile_after_seconds' => (int) env('PIXELFORGE_RECONCILE_AFTER', 45),

        // Starting credits granted to a new account, so the app is usable
        // immediately. Dollars.
        'signup_credit_usd' => env('PIXELFORGE_SIGNUP_CREDIT_USD', '5.00'),
    ],

];
