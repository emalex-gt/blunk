<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RouteDeliveryCollection extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'sale_id', 'pre_sale_id', 'route_external_delivery_reconciliation_item_id', 'route_delivery_stop_id', 'delivery_origin',
        'collected_by', 'recorded_by', 'amount', 'payment_method', 'reference', 'details', 'collected_at',
        'cash_custody_policy_snapshot', 'custody_status', 'cash_posting_state',
        'physical_branch_receipt_confirmed_at', 'physical_branch_receipt_confirmed_by',
        'cash_register_session_id', 'cash_movement_id', 'operation_idempotency_key_id', 'override_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2', 'details' => 'array', 'collected_at' => 'datetime',
        'physical_branch_receipt_confirmed_at' => 'datetime',
    ];

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function preSale(): BelongsTo { return $this->belongsTo(PreSale::class); }
    public function reconciliationItem(): BelongsTo { return $this->belongsTo(RouteExternalDeliveryReconciliationItem::class, 'route_external_delivery_reconciliation_item_id'); }
    public function stop(): BelongsTo { return $this->belongsTo(RouteDeliveryStop::class, 'route_delivery_stop_id'); }
    public function collectedBy(): BelongsTo { return $this->belongsTo(User::class, 'collected_by'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id'); }
    public function cashMovement(): BelongsTo { return $this->belongsTo(CashMovement::class, 'cash_movement_id'); }
    public function salePayment(): HasOne { return $this->hasOne(SalePayment::class, 'route_delivery_collection_id'); }
}
