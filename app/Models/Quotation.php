<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quotation extends Model {
    public const OPEN='open', CONVERSION_PENDING='conversion_pending', CONVERTED='converted', CANCELLED='cancelled';
    protected $fillable=['business_id','business_number','branch_id','customer_id','created_by','status','issued_at','valid_until','note','customer_name','customer_commercial_name','customer_doc_type','customer_doc_number','customer_address','customer_address_reference','customer_postal_code','customer_municipality','customer_department','customer_country','customer_phone','total','subtotal_before_discount','discount_type','discount_value','discount_amount','discount_reason','cancelled_at','cancelled_by','cancellation_reason','converted_at'];
    protected $casts=['issued_at'=>'datetime','valid_until'=>'date','cancelled_at'=>'datetime','converted_at'=>'datetime','total'=>'decimal:2','subtotal_before_discount'=>'decimal:2','discount_value'=>'decimal:2','discount_amount'=>'decimal:2'];
    public function business(): BelongsTo{return $this->belongsTo(Business::class);} public function branch(): BelongsTo{return $this->belongsTo(Branch::class);} public function customer(): BelongsTo{return $this->belongsTo(Customer::class);} public function createdBy(): BelongsTo{return $this->belongsTo(User::class,'created_by');} public function cancelledBy(): BelongsTo{return $this->belongsTo(User::class,'cancelled_by');} public function items(): HasMany{return $this->hasMany(QuotationItem::class);} public function convertedSale(): HasOne{return $this->hasOne(Sale::class);}
    public function getVisibleNumberAttribute(): string{return 'COT-'.str_pad((string)$this->business_number,6,'0',STR_PAD_LEFT);} public function isExpired(?string $timezone=null): bool{return $this->status===self::OPEN && $this->valid_until->lt(now($timezone)->startOfDay());}
}
