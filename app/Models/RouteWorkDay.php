<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteWorkDay extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'route_zone_id',
        'seller_id',
        'work_date',
        'status',
        'started_at',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(RouteZone::class, 'route_zone_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(RouteVisit::class);
    }

    public function preSales(): HasMany
    {
        return $this->hasMany(PreSale::class);
    }
}
