<?php

return [
    'queue_worker_enabled' => filter_var(env('DEPLOY_QUEUE_WORKER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'queue' => 'deploys',
    'script_path' => base_path('scripts/deploy-production.sh'),
    'log_directory' => storage_path('logs/deploys'),
    'timeout' => 900,
];
