<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('credit_receipts', function(Blueprint $t){ $t->unsignedSmallInteger('snapshot_version')->nullable(); $t->string('discount_type')->nullable(); $t->decimal('discount_value',12,2)->nullable(); $t->text('discount_reason')->nullable(); });
  Schema::table('credit_receipt_lines', function(Blueprint $t){ $t->decimal('subtotal_before_discount',12,2)->nullable(); $t->decimal('line_discount_amount',12,2)->nullable(); $t->decimal('general_discount_amount',12,2)->nullable(); $t->decimal('total_after_discount',12,2)->nullable(); $t->text('line_discount_reason')->nullable(); });
  Schema::table('credit_receipt_line_invoice', function(Blueprint $t){ $t->decimal('subtotal_before_discount',12,2)->nullable(); $t->decimal('line_discount_amount',12,2)->nullable(); $t->decimal('general_discount_amount',12,2)->nullable(); $t->decimal('total_after_discount',12,2)->nullable(); });
 }
 public function down(): void {
  Schema::table('credit_receipt_line_invoice', fn(Blueprint $t)=>$t->dropColumn(['subtotal_before_discount','line_discount_amount','general_discount_amount','total_after_discount']));
  Schema::table('credit_receipt_lines', fn(Blueprint $t)=>$t->dropColumn(['subtotal_before_discount','line_discount_amount','general_discount_amount','total_after_discount','line_discount_reason']));
  Schema::table('credit_receipts', fn(Blueprint $t)=>$t->dropColumn(['snapshot_version','discount_type','discount_value','discount_reason']));
 }
};
