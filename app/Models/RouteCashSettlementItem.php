<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteCashSettlementItem extends Model
{
    protected $fillable = ['route_cash_settlement_id','route_pre_sale_collection_id','route_delivery_collection_id','amount_snapshot','is_active'];
    protected $casts = ['amount_snapshot'=>'decimal:2','is_active'=>'boolean'];
    public function settlement() { return $this->belongsTo(RouteCashSettlement::class, 'route_cash_settlement_id'); }
    public function preSaleCollection() { return $this->belongsTo(RoutePreSaleCollection::class, 'route_pre_sale_collection_id'); }
    public function deliveryCollection() { return $this->belongsTo(RouteDeliveryCollection::class, 'route_delivery_collection_id'); }
}
