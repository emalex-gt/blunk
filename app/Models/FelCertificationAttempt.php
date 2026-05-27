<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FelCertificationAttempt extends Model
{
    protected $fillable = [
        'business_id',
        'sale_id',
        'electronic_document_id',
        'provider',
        'environment',
        'internal_reference',
        'issued_at',
        'status',
        'request_payload',
        'response_payload',
        'timings',
        'error_message',
        'started_at',
        'finished_at',
        'created_by',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'timings' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
