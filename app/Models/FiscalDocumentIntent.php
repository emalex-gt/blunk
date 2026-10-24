<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentIntent extends Model
{
    public const PENDING = 'pending';
    public const UNKNOWN = 'unknown';
    public const CERTIFIED = 'certified';
    public const REJECTED = 'rejected';
    public const RETRYABLE = 'retryable';
    public const FINALIZED = 'finalized';

    protected $fillable = [
        'business_id', 'branch_id', 'finalized_sale_id', 'cash_register_session_id', 'credit_customer_id', 'credit_hold_amount', 'created_by', 'country', 'provider',
        'environment', 'document_type', 'operation_type', 'internal_reference',
        'idempotency_key', 'status', 'operation_payload', 'fiscal_payload',
        'provider_response', 'negative_reconciliation_count', 'last_reconciliation_at',
        'next_reconciliation_at', 'last_reconciliation_result', 'last_reconciliation_error', 'last_finalization_error',
        'certified_at', 'finalized_at',
    ];

    protected $casts = [
        'operation_payload' => 'array',
        'fiscal_payload' => 'array',
        'provider_response' => 'array',
        'last_reconciliation_result' => 'array',
        'negative_reconciliation_count' => 'integer',
        'credit_hold_amount' => 'decimal:2',
        'last_reconciliation_at' => 'datetime',
        'next_reconciliation_at' => 'datetime',
        'certified_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function finalizedSale(): BelongsTo { return $this->belongsTo(Sale::class, 'finalized_sale_id'); }
    public function cashRegisterSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class); }
    public function creditCustomer(): BelongsTo { return $this->belongsTo(Customer::class, 'credit_customer_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function isUnresolved(): bool
    {
        return in_array($this->status, [self::PENDING, self::UNKNOWN, self::CERTIFIED], true);
    }
}
