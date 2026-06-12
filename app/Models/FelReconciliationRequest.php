<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FelReconciliationRequest extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'sale_id',
        'internal_reference',
        'issued_date',
        'provider',
        'environment',
        'status',
        'last_error',
        'attempts',
        'payload_snapshot',
        'response_snapshot',
        'resolved_sale_id',
        'resolved_electronic_document_id',
        'created_by',
        'resolved_by',
        'checked_at',
        'resolved_at',
    ];

    protected $casts = [
        'issued_date' => 'datetime',
        'attempts' => 'integer',
        'payload_snapshot' => 'array',
        'response_snapshot' => 'array',
        'checked_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function resolvedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'resolved_sale_id');
    }

    public function resolvedElectronicDocument(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'resolved_electronic_document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
