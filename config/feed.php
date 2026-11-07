<?php

declare(strict_types=1);

return [
    // one token for all partners; empty locks the API instead of opening it
    'api_token' => env('FEED_API_TOKEN', ''),

    // measured with make scale: bigger chunks publish faster only up to 1000 rows, then only lock longer and resend more on failure
    'chunk_size' => 1000,

    'kafka_topic' => 'product-updates',

    'kafka_brokers' => env('KAFKA_BROKERS', 'kafka:9092'),

    'kafka_consumer_group' => 'feed-import',
];
