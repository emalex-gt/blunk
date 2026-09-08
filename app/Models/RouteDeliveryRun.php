<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteDeliveryRun extends Model
{
    protected $fillable = ['business_id', 'branch_id', 'delivery_user_id', 'created_by', 'started_by', 'started_at', 'closed_by', 'closed_at', 'status', 'delivery_tracking_snapshot', 'collection_responsibility_snapshot'];
    protected $casts = ['started_at' => 'datetime', 'closed_at' => 'datetime'];
    public function stops() { return $this->hasMany(RouteDeliveryStop::class); }
    public function deliveryUser() { return $this->belongsTo(User::class, 'delivery_user_id'); }
}
