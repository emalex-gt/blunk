<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RoutePostConversionCollection extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'route_delivery_batch_pre_sale_id', 'pre_sale_id', 'sale_id',
        'collected_by', 'recorded_by', 'amount', 'payment_method', 'reference', 'details', 'collected_at',
        'cash_custody_policy_snapshot', 'custody_status', 'cash_posting_state', 'cash_register_session_id',
        'cash_movement_id', 'operation_idempotency_key_id', 'override_reason', 'status',
    ];

    protected $casts = ['amount' => 'decimal:2', 'details' => 'array', 'collected_at' => 'datetime'];

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function preSale(): BelongsTo { return $this->belongsTo(PreSale::class); }
    public function entry(): BelongsTo { return $this->belongsTo(RouteDeliveryBatchPreSale::class, 'route_delivery_batch_pre_sale_id'); }
    public function collectedBy(): BelongsTo { return $this->belongsTo(User::class, 'collected_by'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id'); }
    public function cashMovement(): BelongsTo { return $this->belongsTo(CashMovement::class, 'cash_movement_id'); }
    public function salePayment(): HasOne { return $this->hasOne(SalePayment::class, 'route_post_conversion_collection_id'); }
    public function reversal(): HasOne { return $this->hasOne(RoutePostConversionCollectionReversal::class); }
    public function scopeCaptured(Builder $query): Builder { return $query->where($query->qualifyColumn('status'), 'captured'); }
    public function scopeReversed(Builder $query): Builder { return $query->where($query->qualifyColumn('status'), 'reversed'); }
}
