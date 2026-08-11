<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationDraft extends Model
{
    public const TYPE_POS_SALE = 'pos_sale';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_TRANSFER = 'transfer';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISCARDED = 'discarded';
    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'business_id',
        'branch_id',
        'user_id',
        'type',
        'title',
        'customer_id',
        'supplier_id',
        'source_branch_id',
        'destination_branch_id',
        'payload',
        'payload_version',
        'status',
        'converted_type',
        'converted_id',
        'discarded_at',
        'converted_at',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'payload_version' => 'integer',
        'discarded_at' => 'datetime',
        'converted_at' => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }
}
