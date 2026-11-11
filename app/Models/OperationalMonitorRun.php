<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalMonitorRun extends Model
{
    protected $fillable = [
        'status', 'checks', 'active_incident_count', 'started_at', 'finished_at', 'duration_ms',
    ];

    protected $casts = [
        'checks' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'active_incident_count' => 'integer',
    ];
}
