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
        'manual_price_min_margin_percent',
        'remember_last_customer_product_price',
        'allow_receipts',
        'allow_invoices',
        'enable_credit_sales',
        'allow_negative_stock',
        'allow_duplicate_product_codes',
        'allow_duplicate_product_barcodes',
    ];

    protected $casts = [
        'use_product_images' => 'boolean',
        'use_branches' => 'boolean',
        'products_shared_across_branches' => 'boolean',
        'allow_manual_price' => 'boolean',
        'manual_price_min_margin_percent' => 'decimal:2',
        'remember_last_customer_product_price' => 'boolean',
        'allow_receipts' => 'boolean',
        'allow_invoices' => 'boolean',
        'enable_credit_sales' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'allow_duplicate_product_codes' => 'boolean',
        'allow_duplicate_product_barcodes' => 'boolean',
        'max_users' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
