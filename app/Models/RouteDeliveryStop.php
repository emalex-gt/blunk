<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteDeliveryStop extends Model
{
    protected $fillable = ['business_id', 'branch_id', 'route_delivery_run_id', 'route_delivery_batch_id', 'route_delivery_batch_pre_sale_id', 'pre_sale_id', 'sale_id', 'customer_id', 'customer_name_snapshot', 'customer_address_snapshot', 'customer_phone_snapshot', 'position', 'delivery_tracking_snapshot', 'collection_responsibility_snapshot', 'status', 'not_delivered_reason_code', 'delivery_notes', 'assigned_by', 'assigned_at', 'completed_by', 'completed_at'];
    protected $casts = ['assigned_at' => 'datetime', 'completed_at' => 'datetime'];
    public function run() { return $this->belongsTo(RouteDeliveryRun::class, 'route_delivery_run_id'); }
    public function entry() { return $this->belongsTo(RouteDeliveryBatchPreSale::class, 'route_delivery_batch_pre_sale_id'); }
    public function sale() { return $this->belongsTo(Sale::class); }
    public function deliveryCollection() { return $this->hasOne(RouteDeliveryCollection::class); }
}
