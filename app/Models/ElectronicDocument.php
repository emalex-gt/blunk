<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectronicDocument extends Model
{
    protected $fillable = [
        'business_id',
        'sale_id',
        'provider',
        'environment',
        'document_type',
        'internal_reference',
        'status',
        'uuid',
        'series',
        'number',
        'certification_date',
        'issued_at',
        'request_payload',
        'response_payload',
        'metadata',
        'xml_base64',
        'pdf_base64',
        'html',
        'error_message',
        'cancelled_at',
        'cancellation_request_payload',
        'cancellation_response_payload',
        'created_by',
    ];

    protected $casts = [
        'certification_date' => 'datetime',
        'issued_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'metadata' => 'array',
        'cancelled_at' => 'datetime',
        'cancellation_request_payload' => 'array',
        'cancellation_response_payload' => 'array',
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

    public function attempts(): HasMany
    {
        return $this->hasMany(FelCertificationAttempt::class);
    }
}
