<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerCreditPayment extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'customer_id',
        'customer_credit_account_id',
        'payment_number',
        'amount',
        'payment_method',
        'paid_from_cash_register',
        'cash_register_session_id',
        'reference',
        'notes',
        'status',
        'created_by',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'payment_number' => 'integer',
        'amount' => 'decimal:2',
        'paid_from_cash_register' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditAccount::class, 'customer_credit_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerCreditPaymentAllocation::class, 'payment_id');
    }
}
