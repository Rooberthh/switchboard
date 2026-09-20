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

];
