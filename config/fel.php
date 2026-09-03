<?php

return [
    'route_automation_enabled' => filter_var(env('FEL_ROUTE_AUTOMATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'route_automation_queue' => env('FEL_ROUTE_AUTOMATION_QUEUE', 'fel'),
];
