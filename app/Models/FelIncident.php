<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FelIncident extends Model
{
    protected $fillable = [
        'business_id',
        'sale_id',
        'internal_reference',
        'type',
        'severity',
        'status',
        'message',
        'metadata',
        'created_by',
        'reviewed_by',
        'resolved_by',
        'reviewed_at',
        'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
