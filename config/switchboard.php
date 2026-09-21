<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | Rename the tables if they collide with your schema. Publish the
    | migrations before running them if you change these.
    |
    */

    'tables' => [
        'inbox_messages' => 'switchboard_inbox_messages',
        'outbox_messages' => 'switchboard_outbox_messages',
        'endpoints' => 'switchboard_endpoints',
        'deliveries' => 'switchboard_deliveries',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where inbox processing and outbox delivery jobs are dispatched.
    | Null uses your application's default connection and queue.
    |
    */

    'queue' => [
        'connection' => env('SWITCHBOARD_QUEUE_CONNECTION'),
        'name' => env('SWITCHBOARD_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbox
    |--------------------------------------------------------------------------
    |
    | "path" is the prefix the conventional provider endpoint is mounted under,
    | so a provider registered as "stripe" is served at POST /webhooks/stripe.
    | Pass a path to Switchboard::provider() to override it per provider.
    |
    | "tolerance" is how many seconds either side of now a signed timestamp may
    | be. It is symmetric: too old and too far in the future are both rejected.
    | It is the default for the shipped StandardWebhooks verification.
    |
    | "tries" and "backoff" are how a message is processed: how many attempts a
    | handler gets before the message is recorded as failed, and how many
    | seconds to wait between them.
    |
    | "stale_after" is how many seconds a message may sit unprocessed before
    | switchboard:relay treats its job as lost and queues it again. Null
    | derives a window from "tries", "backoff" and your queue connection's
    | retry_after, which is what you want unless a handler of yours runs for
    | longer than that.
    |
    */

    'inbox' => [
        'path' => env('SWITCHBOARD_INBOX_PATH', 'webhooks'),
        'tolerance' => (int) env('SWITCHBOARD_INBOX_TOLERANCE', 300),
        'tries' => 5,
        'backoff' => [10, 60, 360, 2160],
        'stale_after' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Where a provider class reads its secret from, keyed by the provider's
    | name. Switchboard itself never reads these: the provider's secret()
    | method does, and a provider is free to override it and read its secret
    | somewhere else entirely.
    |
    |     'acme' => ['secret' => env('ACME_WEBHOOK_SECRET')],
    |
    */

    'providers' => [
        //
    ],

];
