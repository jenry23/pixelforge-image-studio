<?php

return [
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

    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

        'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),
        'connect_timeout' => (int) env('PIXELFORGE_CONNECT_TIMEOUT', 5),

        'markup_percent' => (int) env('PIXELFORGE_MARKUP_PERCENT', 25),

        'max_in_flight' => (int) env('PIXELFORGE_MAX_IN_FLIGHT', 3),

        'concurrency_wait_seconds' => (int) env('PIXELFORGE_CONCURRENCY_WAIT', 5),

        'submit_max_attempts' => (int) env('PIXELFORGE_SUBMIT_MAX_ATTEMPTS', 6),

        // How long one worker may hold a submit attempt. Must exceed the queue
        // worker's --timeout, or a job killed by that timeout leaves a claim
        // that blocks its own retries until the lease expires.
        'submit_lease_seconds' => (int) env('PIXELFORGE_SUBMIT_LEASE_SECONDS', 120),

        'claim_wait_seconds' => (int) env('PIXELFORGE_CLAIM_WAIT', 5),

        'resolve_unknown_for_minutes' => (int) env('PIXELFORGE_RESOLVE_UNKNOWN_MINUTES', 60),

        'webhook_tolerance_seconds' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

        'reconcile_after_seconds' => (int) env('PIXELFORGE_RECONCILE_AFTER', 45),

        'signup_credit_usd' => env('PIXELFORGE_SIGNUP_CREDIT_USD', '5.00'),
    ],
];
