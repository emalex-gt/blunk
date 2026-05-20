<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    public const STATUSES = ['active', 'paused', 'cancelled', 'trial', 'expired'];

    protected $fillable = [
        'business_id',
        'plan_name',
        'status',
        'price_amount',
        'currency',
        'starts_at',
        'ends_at',
        'paused_at',
        'cancelled_at',
        'notes',
    ];

    protected $casts = [
        'price_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
