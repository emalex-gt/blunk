<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'doc_type',
        'doc_number',
        'tax_condition',
        'address',
        'postal_code',
        'municipality',
        'department',
        'phone',
        'country',
        'is_final_consumer',
        'name_locked',
        'tax_lookup_payload',
        'tax_lookup_verified_at',
    ];

    protected $casts = [
        'is_final_consumer' => 'boolean',
        'name_locked' => 'boolean',
        'tax_lookup_payload' => 'array',
        'tax_lookup_verified_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
