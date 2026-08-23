<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationIdempotencyKey extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'user_id',
        'operation_type',
        'idempotency_key',
        'request_hash',
        'status',
        'result_type',
        'result_id',
        'response_payload',
        'locked_at',
        'completed_at',
        'expires_at',
        'replay_count',
        'conflict_count',
        'last_replayed_at',
        'last_conflict_at',
        'last_error',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'locked_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_replayed_at' => 'datetime',
        'last_conflict_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
