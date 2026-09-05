<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RoutePreSaleCollection extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'pre_sale_id', 'route_work_day_id', 'collected_by', 'recorded_by',
        'amount', 'payment_method', 'reference', 'details', 'collected_at', 'status', 'custody_status',
        'cash_register_session_id', 'cash_movement_id', 'operation_idempotency_key_id', 'override_reason',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = ['amount' => 'decimal:2', 'details' => 'array', 'collected_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function preSale(): BelongsTo { return $this->belongsTo(PreSale::class); }
    public function workDay(): BelongsTo { return $this->belongsTo(RouteWorkDay::class, 'route_work_day_id'); }
    public function collectedBy(): BelongsTo { return $this->belongsTo(User::class, 'collected_by'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id'); }
    public function cashMovement(): BelongsTo { return $this->belongsTo(CashMovement::class, 'cash_movement_id'); }
    public function idempotencyKey(): BelongsTo { return $this->belongsTo(OperationIdempotencyKey::class, 'operation_idempotency_key_id'); }
    public function salePayment(): HasOne { return $this->hasOne(SalePayment::class, 'route_pre_sale_collection_id'); }
}
