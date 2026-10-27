<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin", "microsoft-graph"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        /*
         * The scheduling address is a shared mailbox with no password, so it
         * cannot authenticate to SMTP. Graph sends on its behalf with an
         * app-only token instead -- see MicrosoftGraphTransport.
         *
         * These credentials are deliberately separate from services.microsoft,
         * which the calendar integration signs users in with. Mail holds an
         * application permission that can send as a mailbox with nobody
         * present, so it gets its own registration and its own secret to
         * rotate. They fall back to the calendar's registration for a setup
         * that runs both from one app -- with ?: rather than an env() default,
         * because a key that is present but empty is an empty string, not a
         * missing value, and would never reach the default.
         */
        'microsoft-graph' => [
            'transport' => 'microsoft-graph',
            'tenant' => env('MAIL_GRAPH_TENANT') ?: env('MICROSOFT_TENANT'),
            'client_id' => env('MAIL_GRAPH_CLIENT_ID') ?: env('MICROSOFT_CLIENT_ID'),
            'client_secret' => env('MAIL_GRAPH_CLIENT_SECRET') ?: env('MICROSOFT_CLIENT_SECRET'),
            'mailbox' => env('MAIL_GRAPH_MAILBOX') ?: env('MAIL_FROM_ADDRESS'),
            // Also sent through as themselves when a message is from them.
            'send_as' => array_values(array_filter([env('MAIL_ACCOUNT_FROM_ADDRESS')])),
            'save_to_sent_items' => env('MAIL_GRAPH_SAVE_TO_SENT_ITEMS', true),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'microsoft-graph',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS') ?: 'notifications@coresched.com',
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Mail "From" Address
    |--------------------------------------------------------------------------
    |
    | Mail about someone's account -- a password reset, an email verification,
    | an invitation to an organization. It comes from the global address above
    | unless MAIL_ACCOUNT_FROM_ADDRESS names a different one, such as a
    | no-reply mailbox. See SendAccountMailFromNoReply.
    |
    */

    'account_from' => [
        'address' => env('MAIL_ACCOUNT_FROM_ADDRESS') ?: (env('MAIL_FROM_ADDRESS') ?: 'notifications@coresched.com'),
        'name' => env('MAIL_ACCOUNT_FROM_NAME') ?: env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sales Inbox
    |--------------------------------------------------------------------------
    |
    | Where the pricing page's "Contact sales" form is delivered. Falls back to
    | the main sender's mailbox until SALES_EMAIL names a dedicated inbox.
    |
    */

    'sales_address' => env('SALES_EMAIL') ?: (env('MAIL_FROM_ADDRESS') ?: 'notifications@coresched.com'),

];
