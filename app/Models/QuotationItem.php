<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class QuotationItem extends Model { protected $fillable=['business_id','quotation_id','product_id','price_type_id','product_name','product_code','product_barcode','price_type_name','quantity','unit_price','original_price','price_source','manual_price','discount_amount','total_before_discount','total_after_discount','total']; protected $casts=['manual_price'=>'boolean','unit_price'=>'decimal:2','original_price'=>'decimal:2','discount_amount'=>'decimal:2','total_before_discount'=>'decimal:2','total_after_discount'=>'decimal:2','total'=>'decimal:2']; public function quotation(): BelongsTo{return $this->belongsTo(Quotation::class);} public function product(): BelongsTo{return $this->belongsTo(Product::class);} public function priceType(): BelongsTo{return $this->belongsTo(PriceType::class);} }
