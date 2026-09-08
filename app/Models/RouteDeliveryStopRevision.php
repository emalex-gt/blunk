<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteDeliveryStopRevision extends Model
{
    protected $fillable = ['business_id', 'branch_id', 'route_delivery_stop_id', 'version', 'previous_values', 'new_values', 'correction_reason', 'corrected_by', 'corrected_at'];
    protected $casts = ['previous_values' => 'array', 'new_values' => 'array', 'corrected_at' => 'datetime'];
    public function stop() { return $this->belongsTo(RouteDeliveryStop::class, 'route_delivery_stop_id'); }
}
