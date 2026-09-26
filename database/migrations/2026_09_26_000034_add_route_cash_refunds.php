<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('route_immediate_paid_entry_id')
                ->nullable()
                ->constrained('route_delivery_batch_pre_sales')
                ->restrictOnDelete();
            $table->unique('route_immediate_paid_entry_id', 'sale_payments_route_immediate_paid_entry_unique');
        });
        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_route_provenance_check CHECK (num_nonnulls(route_pre_sale_collection_id, route_delivery_collection_id, route_post_conversion_collection_id, route_immediate_paid_entry_id) <= 1)");

        Schema::create('sale_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_operation_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('original_payment_method', 32);
            $table->string('refund_method', 32);
            $table->string('status', 20);
            $table->string('reference')->nullable();
            $table->string('refunded_to')->nullable();
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('refunded_at');
            $table->foreignId('cash_register_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->timestamps();

            $table->unique('route_operation_return_id', 'sale_refunds_route_return_unique');
            $table->unique('sale_payment_id', 'sale_refunds_payment_unique');
            $table->unique('cash_movement_id', 'sale_refunds_cash_movement_unique');
            $table->unique(['business_id', 'branch_id', 'idempotency_key'], 'sale_refunds_idempotency_unique');
            $table->index(['business_id', 'branch_id', 'refunded_at'], 'sale_refunds_scope_refunded');
        });
        DB::statement("ALTER TABLE sale_refunds ADD CONSTRAINT sale_refunds_state_check CHECK (amount > 0 AND original_payment_method = 'cash' AND refund_method = 'cash' AND status = 'confirmed')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_sale_refund_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE refund_row record; return_row record; sale_row record; payment_row record; entry_row record; batch_row record; movement_row record;
BEGIN
    FOR refund_row IN SELECT * FROM sale_refunds LOOP
        SELECT * INTO return_row FROM route_operation_returns WHERE id = refund_row.route_operation_return_id;
        SELECT * INTO sale_row FROM sales WHERE id = refund_row.sale_id;
        SELECT * INTO payment_row FROM sale_payments WHERE id = refund_row.sale_payment_id;
        SELECT * INTO entry_row FROM route_delivery_batch_pre_sales WHERE id = payment_row.route_immediate_paid_entry_id;
        SELECT * INTO batch_row FROM route_delivery_batches WHERE id = entry_row.route_delivery_batch_id;
        SELECT * INTO movement_row FROM cash_movements WHERE id = refund_row.cash_movement_id;
        IF return_row.id IS NULL OR sale_row.id IS NULL OR payment_row.id IS NULL OR entry_row.id IS NULL OR batch_row.id IS NULL OR movement_row.id IS NULL
           OR return_row.sale_id <> sale_row.id OR return_row.route_delivery_batch_pre_sale_id <> entry_row.id
           OR entry_row.sale_id <> sale_row.id OR batch_row.collection_workflow_mode_snapshot <> 'immediate_paid'
           OR refund_row.business_id <> sale_row.business_id OR refund_row.branch_id <> sale_row.branch_id
           OR payment_row.business_id <> refund_row.business_id OR payment_row.sale_id <> sale_row.id
           OR payment_row.status <> 'captured' OR payment_row.method <> 'cash'
           OR payment_row.route_pre_sale_collection_id IS NOT NULL OR payment_row.route_delivery_collection_id IS NOT NULL OR payment_row.route_post_conversion_collection_id IS NOT NULL
           OR refund_row.amount <> payment_row.amount OR refund_row.amount <> sale_row.total
           OR return_row.status <> 'completed' OR sale_row.status <> 'cancelled'
           OR movement_row.business_id <> refund_row.business_id OR movement_row.branch_id <> refund_row.branch_id
           OR movement_row.cash_register_session_id <> refund_row.cash_register_session_id
           OR movement_row.type <> 'sale_refund_cash' OR movement_row.amount <> -refund_row.amount
           OR movement_row.reference_type <> 'sale_refund' OR movement_row.reference_id <> refund_row.id THEN
            RAISE EXCEPTION 'Sale refund financial integrity mismatch';
        END IF;
    END LOOP;
    RETURN NULL;
END $$;
SQL);

        foreach ([
            'sale_refunds' => 'sale_refund_refund_trigger',
            'sale_payments' => 'sale_refund_payment_trigger',
            'route_operation_returns' => 'sale_refund_return_trigger',
            'route_delivery_batch_pre_sales' => 'sale_refund_entry_trigger',
            'sales' => 'sale_refund_sale_trigger',
            'cash_movements' => 'sale_refund_cash_movement_trigger',
        ] as $table => $trigger) {
            DB::statement("CREATE CONSTRAINT TRIGGER {$trigger} AFTER INSERT OR UPDATE OR DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_sale_refund_integrity()");
        }
    }

    public function down(): void
    {
        foreach ([
            'sale_refunds' => 'sale_refund_refund_trigger',
            'sale_payments' => 'sale_refund_payment_trigger',
            'route_operation_returns' => 'sale_refund_return_trigger',
            'route_delivery_batch_pre_sales' => 'sale_refund_entry_trigger',
            'sales' => 'sale_refund_sale_trigger',
            'cash_movements' => 'sale_refund_cash_movement_trigger',
        ] as $table => $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS validate_sale_refund_integrity()');
        Schema::dropIfExists('sale_refunds');
        DB::statement('ALTER TABLE sale_payments DROP CONSTRAINT sale_payments_route_provenance_check');
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payments_route_immediate_paid_entry_unique');
            $table->dropConstrainedForeignId('route_immediate_paid_entry_id');
        });
    }
};
