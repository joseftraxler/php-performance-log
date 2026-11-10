<?php

declare(strict_types=1);

return [
    // configure name of log channel defined in config/logging.php used for logging
    // with null value default log channel will be used
    'log_channel' => null,
    // thresholds definitions, if anything run longer than threshold service will log warning
    // all thresholds are float value in milliseconds
    // null threshold will turn off any measuring
    // threshold zero value will log running time for anything as debug
    'database' => [
        // define threshold for single database query
        'slow_query_threshold' => 50,

        // define threshold for logging EXPLAIN of a slow select query, with the query log or as debug (MySQL and MariaDB only)
        'slow_query_explain_threshold' => null,

        // define threshold for one database transaction
        'slow_transaction_threshold' => 300,
    ],
    'http' => [
        // define threshold for http client request call
        'slow_client_request_threshold' => 700,
        // define threshold for whole http server request
        'slow_server_request_threshold' => 1000,
    ],
    'console' => [
        // define threshold for whole console command
        // the command threshold has the same behavior as another threshold
        // but has different units, is configured in seconds!
        'slow_command_threshold' => null,
    ],
    'queue' => [
        // define threshold for queue job
        'slow_job_threshold' => 40000,
    ],
];
