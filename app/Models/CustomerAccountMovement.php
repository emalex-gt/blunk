<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAccountMovement extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'customer_id',
        'customer_credit_account_id',
        'sale_id',
        'payment_id',
        'credit_receipt_id',
        'type',
        'direction',
        'description',
        'amount',
        'balance_after',
        'payment_method',
        'reference',
        'notes',
        'metadata',
        'created_by',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'cancelled_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditAccount::class, 'customer_credit_account_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditPayment::class, 'payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
