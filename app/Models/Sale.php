<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $fillable = [
        'business_id',
        'business_number',
        'branch_id',
        'customer_id',
        'customer_name',
        'customer_doc_type',
        'customer_doc_number',
        'customer_address',
        'customer_postal_code',
        'customer_municipality',
        'customer_department',
        'customer_country',
        'customer_phone',
        'total',
        'subtotal_before_discount',
        'discount_type',
        'discount_value',
        'discount_amount',
        'discount_reason',
        'payment_method',
        'document_type',
        'electronic_document_id',
        'certification_status',
        'fel_uuid',
        'fel_series',
        'fel_number',
        'fel_xml_path',
        'fel_html_path',
        'fel_pdf_url',
        'fel_pdf_path',
        'fel_certified_at',
        'fel_issued_at',
        'fel_status',
        'fel_internal_reference',
        'fel_raw_response',
        'status',
        'note',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'subtotal_before_discount' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
        'fel_certified_at' => 'datetime',
        'fel_issued_at' => 'datetime',
        'fel_raw_response' => 'array',
        'business_number' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function electronicDocument(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class);
    }

    public function felCertificationAttempts(): HasMany
    {
        return $this->hasMany(FelCertificationAttempt::class);
    }

    public function felIncidents(): HasMany
    {
        return $this->hasMany(FelIncident::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
