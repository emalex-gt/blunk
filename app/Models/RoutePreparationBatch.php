<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutePreparationBatch extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'business_id',
        'branch_id',
        'route_work_day_id',
        'route_zone_id',
        'prepared_by',
        'prepared_at',
        'status',
        'stock_deduction_timing',
        'invoicing_mode',
        'total_pre_sales',
        'total_items',
        'total_amount',
        'documents_generated_at',
        'notes',
    ];

    protected $casts = [
        'prepared_at' => 'datetime',
        'documents_generated_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function workDay(): BelongsTo
    {
        return $this->belongsTo(RouteWorkDay::class, 'route_work_day_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(RouteZone::class, 'route_zone_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function preSales(): HasMany
    {
        return $this->hasMany(RoutePreparationBatchPreSale::class);
    }
}
