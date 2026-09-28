<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteCashSettlementReversal extends Model
{
    protected $fillable = ['business_id', 'branch_id', 'route_cash_settlement_id', 'reason_code', 'explanation', 'reversed_by', 'reversed_at', 'cash_correction_type', 'compensating_cash_movement_id', 'operation_idempotency_key_id'];

    protected $casts = ['reversed_at' => 'datetime'];

    public function settlement() { return $this->belongsTo(RouteCashSettlement::class, 'route_cash_settlement_id'); }
    public function business() { return $this->belongsTo(Business::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function reversedBy() { return $this->belongsTo(User::class, 'reversed_by'); }
    public function compensatingCashMovement() { return $this->belongsTo(CashMovement::class, 'compensating_cash_movement_id'); }
    public function operationIdempotencyKey() { return $this->belongsTo(OperationIdempotencyKey::class); }
    public function reversedItems() { return $this->hasMany(RouteCashSettlementItem::class, 'reversed_by_route_cash_settlement_reversal_id'); }
}
