<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OperationalIncident extends Model
{
    public const OPEN = 'open';
    public const ACKNOWLEDGED = 'acknowledged';
    public const RESOLVED = 'resolved';

    protected $fillable = [
        'uuid', 'fingerprint', 'business_id', 'type', 'severity', 'status', 'message', 'metadata',
        'occurrence_count', 'detected_at', 'last_occurred_at', 'last_notified_at', 'last_reminded_at',
        'acknowledged_by', 'acknowledged_at', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array', 'detected_at' => 'datetime', 'last_occurred_at' => 'datetime',
        'last_notified_at' => 'datetime', 'last_reminded_at' => 'datetime', 'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $incident): void {
            $incident->uuid ??= (string) Str::uuid();
        });
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function acknowledgedBy(): BelongsTo { return $this->belongsTo(User::class, 'acknowledged_by'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
