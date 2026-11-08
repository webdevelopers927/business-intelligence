<?php

return [

    'imports' => [
        // Maximum upload size in kilobytes (50 MB).
        'max_upload_kb' => (int) env('ORBIT_IMPORT_MAX_KB', 51200),
    ],

    'sources' => [
        // Hosts that may resolve to private/loopback addresses (e.g. a local
        // MySQL during development). Comma-separated.
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('ORBIT_ALLOWED_SOURCE_HOSTS', ''))))),
        'connect_timeout' => 5,
        'http_timeout' => 15,
    ],

];
