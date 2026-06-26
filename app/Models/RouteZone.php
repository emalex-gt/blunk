<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteZone extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'assigned_user_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function zoneCustomers(): HasMany
    {
        return $this->hasMany(RouteZoneCustomer::class);
    }

    public function workDays(): HasMany
    {
        return $this->hasMany(RouteWorkDay::class);
    }
}
