<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FelCancellationRequest extends Model
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const UNKNOWN = 'unknown';
    public const MANUAL_RESOLUTION_REQUIRED = 'manual_resolution_required';

    protected $fillable = [
        'business_id',
        'sale_id',
        'electronic_document_id',
        'requested_by',
        'finalized_by',
        'provider',
        'environment',
        'uuid',
        'reason',
        'status',
        'request_payload',
        'response_payload',
        'last_error',
        'finalization_error',
        'attempted_at',
        'confirmed_at',
        'finalized_at',
        'resolved_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'attempted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'finalized_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function electronicDocument(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::CONFIRMED,
            self::REJECTED,
            self::MANUAL_RESOLUTION_REQUIRED,
        ], true);
    }
}
