<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerCreditAccount extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'customer_id',
        'credit_limit',
        'current_balance',
        'is_blocked',
        'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_blocked' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CustomerAccountMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerCreditPayment::class);
    }
}
