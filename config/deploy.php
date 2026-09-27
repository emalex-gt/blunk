<?php

return [
    'queue_worker_enabled' => filter_var(env('DEPLOY_QUEUE_WORKER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'queue' => 'deploys',
    'script_path' => base_path('scripts/deploy-production.sh'),
    'log_directory' => storage_path('logs/deploys'),
    'timeout' => 900,
    'lock_seconds' => 1200,
    'home' => env('DEPLOY_HOME'),
    'composer_home' => env('DEPLOY_COMPOSER_HOME'),
    'composer_path' => env('DEPLOY_COMPOSER_PATH'),
    'pause_workers_hook' => env('DEPLOY_PAUSE_WORKERS_HOOK'),
    'resume_workers_hook' => env('DEPLOY_RESUME_WORKERS_HOOK'),
];
