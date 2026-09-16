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
            $table->foreignId('route_post_conversion_collection_id')->nullable()->constrained()->nullOnDelete();
            $table->unique('route_post_conversion_collection_id', 'sale_payments_route_post_conversion_collection_unique');
        });

        Schema::table('route_cash_settlement_items', function (Blueprint $table) {
            $table->foreignId('route_post_conversion_collection_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE route_cash_settlement_items DROP CONSTRAINT route_cash_settlement_items_origin_check');
        DB::statement("ALTER TABLE route_cash_settlement_items ADD CONSTRAINT route_cash_settlement_items_origin_check CHECK (amount_snapshot > 0 AND ((route_pre_sale_collection_id IS NOT NULL AND route_delivery_collection_id IS NULL AND route_post_conversion_collection_id IS NULL) OR (route_pre_sale_collection_id IS NULL AND route_delivery_collection_id IS NOT NULL AND route_post_conversion_collection_id IS NULL) OR (route_pre_sale_collection_id IS NULL AND route_delivery_collection_id IS NULL AND route_post_conversion_collection_id IS NOT NULL)))");
        DB::statement('CREATE UNIQUE INDEX route_cash_settlement_items_post_conversion_active_unique ON route_cash_settlement_items (route_post_conversion_collection_id) WHERE is_active');

        Schema::create('route_post_conversion_collection_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_post_conversion_collection_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_payment_id')->constrained()->restrictOnDelete();
            $table->string('reason_code', 48);
            $table->text('explanation');
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at');
            $table->string('cash_correction_type', 48);
            $table->foreignId('compensating_cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('operation_idempotency_key_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'branch_id', 'reversed_at'], 'route_post_conversion_reversals_scope_reversed');
        });
        DB::statement("ALTER TABLE route_post_conversion_collection_reversals ADD CONSTRAINT route_post_conversion_reversals_reason_check CHECK (reason_code IN ('payment_recorded_by_mistake', 'wrong_customer', 'duplicate_collection', 'wrong_amount', 'other') AND btrim(explanation) <> '')");
        DB::statement("ALTER TABLE route_post_conversion_collection_reversals ADD CONSTRAINT route_post_conversion_reversals_cash_check CHECK ((cash_correction_type IN ('none', 'historical_closed_session_ledger') AND compensating_cash_movement_id IS NULL) OR (cash_correction_type = 'current_open_session_adjustment' AND compensating_cash_movement_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX route_post_conversion_reversals_collection_unique ON route_post_conversion_collection_reversals (route_post_conversion_collection_id)');
        DB::statement('CREATE UNIQUE INDEX route_post_conversion_reversals_payment_unique ON route_post_conversion_collection_reversals (sale_payment_id)');
        DB::statement('CREATE UNIQUE INDEX route_post_conversion_reversals_cash_movement_unique ON route_post_conversion_collection_reversals (compensating_cash_movement_id) WHERE compensating_cash_movement_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX route_post_conversion_reversals_idempotency_unique ON route_post_conversion_collection_reversals (operation_idempotency_key_id) WHERE operation_idempotency_key_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_route_post_conversion_collection_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; c record; p record; s record; e record; replacement record; captured_count integer;
BEGIN
    FOR r IN SELECT * FROM route_post_conversion_collection_reversals LOOP
        SELECT * INTO c FROM route_post_conversion_collections WHERE id = r.route_post_conversion_collection_id;
        SELECT * INTO p FROM sale_payments WHERE id = r.sale_payment_id;
        SELECT * INTO s FROM sales WHERE id = c.sale_id;
        SELECT * INTO e FROM route_delivery_batch_pre_sales WHERE id = c.route_delivery_batch_pre_sale_id;
        IF c.id IS NULL OR p.id IS NULL OR s.id IS NULL OR e.id IS NULL OR c.status <> 'reversed' OR p.status <> 'reversed'
           OR p.route_post_conversion_collection_id <> c.id
           OR c.business_id <> r.business_id OR c.branch_id <> r.branch_id
           OR p.business_id <> r.business_id OR s.business_id <> r.business_id OR s.branch_id <> r.branch_id
           OR e.sale_id <> c.sale_id OR e.pre_sale_id <> c.pre_sale_id THEN
            RAISE EXCEPTION 'Route post-conversion collection reversal financial post-state mismatch';
        END IF;
        SELECT * INTO replacement FROM route_post_conversion_collections WHERE sale_id = c.sale_id AND status = 'captured' ORDER BY id LIMIT 1;
        IF replacement.id IS NULL THEN
            IF s.payment_status <> 'unpaid' OR s.amount_paid <> 0 OR s.payment_method IS NOT NULL OR e.payment_method IS DISTINCT FROM e.agreed_payment_method_snapshot THEN
                RAISE EXCEPTION 'Reversed post-conversion collection without replacement must leave the sale unpaid';
            END IF;
        ELSE
            SELECT count(*) INTO captured_count FROM sale_payments payment_row WHERE payment_row.route_post_conversion_collection_id = replacement.id AND payment_row.sale_id = c.sale_id AND payment_row.business_id = r.business_id AND payment_row.status = 'captured';
            IF s.payment_status <> 'paid' OR s.amount_paid <> s.total OR captured_count <> 1 OR e.payment_method IS DISTINCT FROM replacement.payment_method THEN
                RAISE EXCEPTION 'Replacement post-conversion collection does not restore a complete paid sale';
            END IF;
        END IF;
        IF EXISTS (SELECT 1 FROM route_cash_settlement_items i JOIN route_cash_settlements st ON st.id = i.route_cash_settlement_id WHERE i.route_post_conversion_collection_id = c.id AND i.is_active AND st.status IN ('draft', 'confirmed')) THEN
            RAISE EXCEPTION 'Reversed post-conversion collection cannot retain an active cash settlement item';
        END IF;
        IF r.cash_correction_type = 'none' AND (r.compensating_cash_movement_id IS NOT NULL OR (c.payment_method = 'cash' AND c.custody_status = 'posted_to_branch_cash')) THEN
            RAISE EXCEPTION 'None cash correction is incompatible with posted cash';
        END IF;
        IF r.cash_correction_type = 'historical_closed_session_ledger' AND (r.compensating_cash_movement_id IS NOT NULL OR c.cash_movement_id IS NULL OR NOT EXISTS (SELECT 1 FROM cash_register_sessions cs JOIN cash_movements cm ON cm.cash_register_session_id = cs.id WHERE cm.id = c.cash_movement_id AND cs.status = 'closed')) THEN
            RAISE EXCEPTION 'Historical cash reversal requires original closed-session movement only';
        END IF;
        IF r.cash_correction_type = 'current_open_session_adjustment' AND NOT EXISTS (SELECT 1 FROM cash_movements cm JOIN cash_register_sessions cs ON cs.id = cm.cash_register_session_id WHERE cm.id = r.compensating_cash_movement_id AND cm.amount = -c.amount AND cm.type = 'route_post_conversion_collection_reversal_current_session' AND cm.reference_type = 'route_post_conversion_collection_reversal' AND cm.reference_id = r.id AND cs.status = 'open' AND cm.cash_register_session_id = c.cash_register_session_id) THEN
            RAISE EXCEPTION 'Current-session cash reversal adjustment mismatch';
        END IF;
    END LOOP;
    IF EXISTS (SELECT 1 FROM route_post_conversion_collections collection_row WHERE collection_row.status = 'reversed' AND NOT EXISTS (SELECT 1 FROM route_post_conversion_collection_reversals reversal_row WHERE reversal_row.route_post_conversion_collection_id = collection_row.id)) THEN
        RAISE EXCEPTION 'Reversed post-conversion collection requires reversal ledger';
    END IF;
    IF EXISTS (SELECT 1 FROM route_post_conversion_collections collection_row WHERE collection_row.status = 'captured' AND EXISTS (SELECT 1 FROM route_post_conversion_collection_reversals reversal_row WHERE reversal_row.route_post_conversion_collection_id = collection_row.id)) THEN
        RAISE EXCEPTION 'Captured post-conversion collection cannot have reversal ledger';
    END IF;
    RETURN NULL;
END $$;
SQL);
        foreach ([
            'route_post_conversion_collections' => 'route_post_conversion_reversal_collection_trigger',
            'sale_payments' => 'route_post_conversion_reversal_payment_trigger',
            'route_post_conversion_collection_reversals' => 'route_post_conversion_reversal_ledger_trigger',
            'route_cash_settlement_items' => 'route_post_conversion_reversal_settlement_item_trigger',
            'route_cash_settlements' => 'route_post_conversion_reversal_settlement_trigger',
            'route_delivery_batch_pre_sales' => 'route_post_conversion_reversal_entry_trigger',
            'sales' => 'route_post_conversion_reversal_sale_trigger',
        ] as $table => $trigger) {
            DB::statement("CREATE CONSTRAINT TRIGGER {$trigger} AFTER INSERT OR UPDATE OR DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_route_post_conversion_collection_integrity()");
        }
    }

    public function down(): void
    {
        if (DB::table('route_post_conversion_collection_reversals')->exists()
            || DB::table('sale_payments')->whereNotNull('route_post_conversion_collection_id')->exists()
            || DB::table('route_cash_settlement_items')->whereNotNull('route_post_conversion_collection_id')->exists()) {
            throw new RuntimeException('Cannot roll back post-conversion collection financial integrations while financial evidence exists.');
        }
        foreach ([
            'route_post_conversion_collections' => 'route_post_conversion_reversal_collection_trigger',
            'sale_payments' => 'route_post_conversion_reversal_payment_trigger',
            'route_post_conversion_collection_reversals' => 'route_post_conversion_reversal_ledger_trigger',
            'route_cash_settlement_items' => 'route_post_conversion_reversal_settlement_item_trigger',
            'route_cash_settlements' => 'route_post_conversion_reversal_settlement_trigger',
            'route_delivery_batch_pre_sales' => 'route_post_conversion_reversal_entry_trigger',
            'sales' => 'route_post_conversion_reversal_sale_trigger',
        ] as $table => $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS validate_route_post_conversion_collection_integrity()');
        Schema::dropIfExists('route_post_conversion_collection_reversals');
        DB::statement('DROP INDEX IF EXISTS route_cash_settlement_items_post_conversion_active_unique');
        DB::statement('ALTER TABLE route_cash_settlement_items DROP CONSTRAINT route_cash_settlement_items_origin_check');
        DB::statement("ALTER TABLE route_cash_settlement_items ADD CONSTRAINT route_cash_settlement_items_origin_check CHECK (amount_snapshot > 0 AND ((route_pre_sale_collection_id IS NOT NULL AND route_delivery_collection_id IS NULL) OR (route_pre_sale_collection_id IS NULL AND route_delivery_collection_id IS NOT NULL)))");
        Schema::table('route_cash_settlement_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('route_post_conversion_collection_id');
        });
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payments_route_post_conversion_collection_unique');
            $table->dropConstrainedForeignId('route_post_conversion_collection_id');
        });
    }
};
