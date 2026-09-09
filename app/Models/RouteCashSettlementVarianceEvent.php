<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class RouteCashSettlementVarianceEvent extends Model { protected $fillable=['variance_id','business_id','branch_id','type','note','occurred_at','recorded_by','operation_idempotency_key_id']; protected $casts=['occurred_at'=>'datetime']; public function variance(){return $this->belongsTo(RouteCashSettlementVariance::class,'variance_id');} public function recorder(){return $this->belongsTo(User::class,'recorded_by');} }
