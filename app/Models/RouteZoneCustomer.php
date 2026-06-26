<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteZoneCustomer extends Model
{
    protected $fillable = [
        'business_id',
        'route_zone_id',
        'customer_id',
        'visit_order',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'visit_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(RouteZone::class, 'route_zone_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
