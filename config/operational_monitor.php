<?php

return [
    'enabled' => (bool) env('OPERATIONAL_MONITOR_ENABLED', false),
    'reminder_minutes' => max(15, (int) env('OPERATIONAL_MONITOR_REMINDER_MINUTES', 60)),
    'confirmations' => max(1, (int) env('OPERATIONAL_MONITOR_CONFIRMATIONS', 2)),
    'disk_min_free_mb' => max(1, (int) env('OPERATIONAL_MONITOR_DISK_MIN_FREE_MB', 1024)),
    'memory_min_available_mb' => max(1, (int) env('OPERATIONAL_MONITOR_MEMORY_MIN_AVAILABLE_MB', 256)),
    'swap_min_available_mb' => max(0, (int) env('OPERATIONAL_MONITOR_SWAP_MIN_AVAILABLE_MB', 128)),
    'cpu_load_max' => max(0.1, (float) env('OPERATIONAL_MONITOR_CPU_LOAD_MAX', 2.0)),
    'queue_lag_seconds' => max(30, (int) env('OPERATIONAL_MONITOR_QUEUE_LAG_SECONDS', 300)),
    'failed_jobs_threshold' => max(1, (int) env('OPERATIONAL_MONITOR_FAILED_JOBS_THRESHOLD', 1)),
    'fel_stale_minutes' => max(5, (int) env('OPERATIONAL_MONITOR_FEL_STALE_MINUTES', 30)),
    'telegram' => [
        'token' => env('OPERATIONAL_MONITOR_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('OPERATIONAL_MONITOR_TELEGRAM_CHAT_ID'),
    ],
    'mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPERATIONAL_MONITOR_EMAILS', ''))))),
];
