<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashExpense extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'business_id',
        'branch_id',
        'cash_register_session_id',
        'cash_expense_category_id',
        'category',
        'description',
        'amount',
        'created_by',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(CashExpenseCategory::class, 'cash_expense_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
