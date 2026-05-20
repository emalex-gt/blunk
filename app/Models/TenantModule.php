<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModule extends Model
{
    protected $fillable = [
        'business_id',
        'module',
        'is_enabled',
        'enabled_at',
        'disabled_at',
        'created_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
