<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegisterSession extends Model
{
    protected $fillable = [
        'business_id',
        'opened_by',
        'closed_by',
        'status',
        'opening_amount',
        'expected_cash',
        'counted_cash',
        'difference',
        'opened_at',
        'closed_at',
        'notes',
        'closing_notes',
    ];

    protected $casts = [
        'opening_amount' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'difference' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(CashExpense::class);
    }
}
