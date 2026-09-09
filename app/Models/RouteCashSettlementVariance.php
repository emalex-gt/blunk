<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteCashSettlementVariance extends Model
{
    protected $fillable = ['business_id', 'branch_id', 'route_cash_settlement_id', 'collector_user_id', 'difference_amount', 'status', 'reason_code', 'explanation', 'assigned_to', 'opened_by', 'opened_at', 'resolved_by', 'resolved_at', 'resolution_note'];

    protected $casts = ['difference_amount' => 'decimal:2', 'opened_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function settlement() { return $this->belongsTo(RouteCashSettlement::class, 'route_cash_settlement_id'); }
    public function resolutions() { return $this->hasMany(RouteCashSettlementVarianceResolution::class, 'variance_id'); }
    public function events() { return $this->hasMany(RouteCashSettlementVarianceEvent::class, 'variance_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
}
