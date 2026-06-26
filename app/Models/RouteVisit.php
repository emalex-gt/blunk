<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RouteVisit extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'route_work_day_id',
        'route_zone_id',
        'customer_id',
        'seller_id',
        'visit_order',
        'status',
        'started_at',
        'finished_at',
        'notes',
    ];

    protected $casts = [
        'visit_order' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function workDay(): BelongsTo
    {
        return $this->belongsTo(RouteWorkDay::class, 'route_work_day_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(RouteZone::class, 'route_zone_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function preSale(): HasOne
    {
        return $this->hasOne(PreSale::class, 'route_visit_id');
    }
}
