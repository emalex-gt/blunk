<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteCashSettlement extends Model
{
    protected $fillable = ['business_id','branch_id','collector_user_id','received_by','recorded_by','confirmed_by','cash_register_session_id','cash_movement_id','expected_amount','received_amount','difference_amount','status','notes','confirmed_at','cancelled_by','cancelled_at','cancellation_reason','operation_idempotency_key_id'];
    protected $casts = ['expected_amount'=>'decimal:2','received_amount'=>'decimal:2','difference_amount'=>'decimal:2','confirmed_at'=>'datetime','cancelled_at'=>'datetime'];
    public function items() { return $this->hasMany(RouteCashSettlementItem::class); }
    public function collector() { return $this->belongsTo(User::class, 'collector_user_id'); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by'); }
    public function cashSession() { return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id'); }
    public function cashMovement() { return $this->belongsTo(CashMovement::class); }
}
