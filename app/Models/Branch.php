<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'code',
        'address',
        'phone',
        'logo_url',
        'logo_public_id',
        'fel_establishment_code',
        'fel_establishment_name',
        'fel_address',
        'fel_postal_code',
        'fel_municipality',
        'fel_department',
        'fel_country',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductBranchStock::class);
    }
}
