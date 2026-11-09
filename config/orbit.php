<?php

return [

    'imports' => [
        // Maximum upload size in kilobytes (50 MB).
        'max_upload_kb' => (int) env('ORBIT_IMPORT_MAX_KB', 51200),
    ],

    'reports' => [
        // Exports with more rows than this are generated in the background.
        'queue_threshold' => (int) env('ORBIT_REPORT_QUEUE_THRESHOLD', 5000),
    ],

    'insights' => [
        // Anomaly detection (docs/ARCHITECTURE.md §12).
        'window' => (int) env('ORBIT_ANOMALY_WINDOW', 28),
        'warning_z' => (float) env('ORBIT_ANOMALY_WARNING_Z', 2.0),
        'critical_z' => (float) env('ORBIT_ANOMALY_CRITICAL_Z', 3.0),
    ],

    'ai' => [
        // The assistant is disabled until a key is set (no canned answers).
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ORBIT_AI_MODEL', 'claude-opus-5-5'),
        // Claude Opus 5.5 defaults to medium; set explicitly so behaviour doesn't drift.
        'effort' => env('ORBIT_AI_EFFORT', 'medium'),
        'max_tokens' => (int) env('ORBIT_AI_MAX_TOKENS', 16000),
        // Upper bound on tool round-trips per question.
        'max_tool_rounds' => (int) env('ORBIT_AI_MAX_TOOL_ROUNDS', 8),
        // Questions per user per hour (cost guard).
        'hourly_limit' => (int) env('ORBIT_AI_HOURLY_LIMIT', 30),
        'timeout' => (int) env('ORBIT_AI_TIMEOUT', 120),
    ],

    'sources' => [
        // Hosts that may resolve to private/loopback addresses (e.g. a local
        // MySQL during development). Comma-separated.
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('ORBIT_ALLOWED_SOURCE_HOSTS', ''))))),
        'connect_timeout' => 5,
        'http_timeout' => 15,
    ],

];
