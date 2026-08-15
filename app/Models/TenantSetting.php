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
        'manual_price_percentage_mode',
        'manual_price_min_markup_percent',
        'manual_price_max_discount_percent',
        'remember_last_customer_product_price',
        'pre_sale_price_type_id',
        'pre_sale_allow_manual_price',
        'route_pre_sale_invoicing_mode',
        'allow_receipts',
        'allow_invoices',
        'enable_credit_sales',
        'enable_credit_reservations',
        'reserve_stock_on_credit_reservations',
        'allow_negative_stock',
        'show_other_branches_stock_in_pos',
        'allow_duplicate_product_codes',
        'allow_duplicate_product_barcodes',
    ];

    protected $casts = [
        'use_product_images' => 'boolean',
        'use_branches' => 'boolean',
        'products_shared_across_branches' => 'boolean',
        'allow_manual_price' => 'boolean',
        'manual_price_min_margin_percent' => 'decimal:2',
        'manual_price_min_markup_percent' => 'decimal:2',
        'manual_price_max_discount_percent' => 'decimal:2',
        'remember_last_customer_product_price' => 'boolean',
        'pre_sale_allow_manual_price' => 'boolean',
        'allow_receipts' => 'boolean',
        'allow_invoices' => 'boolean',
        'enable_credit_sales' => 'boolean',
        'enable_credit_reservations' => 'boolean',
        'reserve_stock_on_credit_reservations' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'show_other_branches_stock_in_pos' => 'boolean',
        'allow_duplicate_product_codes' => 'boolean',
        'allow_duplicate_product_barcodes' => 'boolean',
        'max_users' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
