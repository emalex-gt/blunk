<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCostRecalculationRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'executed_by',
        'executed_at',
        'status',
        'metadata',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductCostRecalculationItem::class);
    }
}
