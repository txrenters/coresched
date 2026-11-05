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

    'calendly' => [
        'token' => env('CALENDLY_API_KEY'),
        'base_url' => env('CALENDLY_API_URL', 'https://api.calendly.com'),
    ],

    /*
     | TEXRA, the lead CRM. A booking that carries a `reference` came from a
     | TEXRA link, and every change to it is reported to this URL, signed
     | with the secret (which must equal BOOKINGS_WEBHOOK_SECRET over there).
     | With no URL nothing is sent. See .ai/rules/webhooks.md.
     */
    'texra' => [
        'webhook_url' => env('TEXRA_WEBHOOK_URL'),
        'webhook_secret' => env('TEXRA_WEBHOOK_SECRET'),
        // Asked before a TEXRA link books: is the lead already held? See App\Services\Texra\ReferenceGate.
        'reference_url' => env('TEXRA_REFERENCE_URL'),
        'reference_secret' => env('TEXRA_REFERENCE_SECRET', env('TEXRA_WEBHOOK_SECRET')),
    ],

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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    /*
     * Twilio sends the workflow text messages. Each organization connects its
     * own account in its settings (Team::twilio()); there is no installation-
     * wide account. sid/token are read ONLY by the 2026_10_27 migration, which
     * hands this account to every organization that existed at deploy time.
     */
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'base_url' => env('TWILIO_API_URL', 'https://api.twilio.com/2010-04-01'),
    ],

    /*
     * Cloudflare Web Analytics counts visits to the public marketing pages
     * only (home, pricing, legal). It is cookieless, so no consent banner, and
     * works without Cloudflare DNS. Blank token = nothing is loaded. See
     * usePublicAnalytics.ts.
     */
    'cloudflare_analytics' => [
        'token' => env('CLOUDFLARE_ANALYTICS_TOKEN'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

];
