<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetting extends Model
{
    protected $fillable = [
        'business_id',
        'use_product_images',
        'max_users',
        'company_logo_url',
        'company_logo_public_id',
        'company_name',
        'company_tax_id',
        'company_address',
        'company_phone',
        'receipt_format',
        'use_branches',
        'products_shared_across_branches',
        'pricing_scope',
        'allow_manual_price',
        'remember_last_customer_product_price',
    ];

    protected $casts = [
        'use_product_images' => 'boolean',
        'use_branches' => 'boolean',
        'products_shared_across_branches' => 'boolean',
        'allow_manual_price' => 'boolean',
        'remember_last_customer_product_price' => 'boolean',
        'max_users' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
