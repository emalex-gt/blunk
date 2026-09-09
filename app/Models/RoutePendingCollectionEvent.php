<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutePendingCollectionEvent extends Model
{
    protected $fillable = [
        'route_pending_collection_case_id', 'business_id', 'branch_id', 'type', 'note',
        'occurred_at', 'recorded_by', 'operation_idempotency_key_id',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function pendingCase(): BelongsTo { return $this->belongsTo(RoutePendingCollectionCase::class, 'route_pending_collection_case_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function operationIdempotencyKey(): BelongsTo { return $this->belongsTo(OperationIdempotencyKey::class); }
}
