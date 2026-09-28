<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE route_cash_settlements DROP CONSTRAINT route_cash_settlements_state_check');
        DB::statement("ALTER TABLE route_cash_settlements ADD CONSTRAINT route_cash_settlements_state_check CHECK ((status = 'draft' AND cash_movement_id IS NULL AND confirmed_at IS NULL AND cancelled_at IS NULL) OR (status = 'confirmed' AND cash_movement_id IS NOT NULL AND cash_register_session_id IS NOT NULL AND received_by IS NOT NULL AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND received_amount IS NOT NULL AND received_amount > 0 AND difference_amount IS NOT NULL AND difference_amount = received_amount - expected_amount) OR (status = 'cancelled' AND cash_movement_id IS NULL AND cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND btrim(cancellation_reason) <> '') OR (status = 'reversed' AND cash_movement_id IS NOT NULL AND cash_register_session_id IS NOT NULL AND received_by IS NOT NULL AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND received_amount IS NOT NULL AND received_amount > 0 AND received_amount = expected_amount AND difference_amount = 0))");

        Schema::create('route_cash_settlement_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_cash_settlement_id')->unique()->constrained()->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('explanation');
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at');
            $table->string('cash_correction_type', 32);
            $table->foreignId('compensating_cash_movement_id')->nullable()->unique()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('operation_idempotency_key_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE route_cash_settlement_reversals ADD CONSTRAINT route_cash_settlement_reversals_reason_check CHECK (reason_code = 'cash_not_received_by_branch' AND btrim(explanation) <> '')");
        DB::statement("ALTER TABLE route_cash_settlement_reversals ADD CONSTRAINT route_cash_settlement_reversals_cash_correction_check CHECK ((cash_correction_type = 'ledger_only' AND compensating_cash_movement_id IS NULL) OR (cash_correction_type = 'current_session_adjustment' AND compensating_cash_movement_id IS NOT NULL))");

        Schema::table('route_cash_settlement_items', function (Blueprint $table) {
            $table->foreignId('reversed_by_route_cash_settlement_reversal_id')
                ->nullable()
                ->constrained('route_cash_settlement_reversals')
                ->restrictOnDelete();
        });
        DB::statement('ALTER TABLE route_cash_settlement_items ADD CONSTRAINT route_cash_settlement_items_reversal_release_check CHECK (reversed_by_route_cash_settlement_reversal_id IS NULL OR is_active = false)');

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_route_cash_settlement_reversal_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    settlement_id bigint;
    settlement_row record;
    reversal_count integer;
BEGIN
    IF TG_TABLE_NAME = 'route_cash_settlements' THEN
        settlement_id := COALESCE(NEW.id, OLD.id);
    ELSIF TG_TABLE_NAME = 'route_cash_settlement_reversals' THEN
        settlement_id := COALESCE(NEW.route_cash_settlement_id, OLD.route_cash_settlement_id);
    ELSE
        settlement_id := COALESCE(NEW.route_cash_settlement_id, OLD.route_cash_settlement_id);
    END IF;

    SELECT * INTO settlement_row FROM route_cash_settlements WHERE id = settlement_id;
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT count(*) INTO reversal_count FROM route_cash_settlement_reversals WHERE route_cash_settlement_id = settlement_id;
    IF settlement_row.status = 'reversed' AND reversal_count <> 1 THEN
        RAISE EXCEPTION 'Reversed settlement must have exactly one reversal ledger row';
    END IF;
    IF settlement_row.status <> 'reversed' AND reversal_count <> 0 THEN
        RAISE EXCEPTION 'Settlement reversal ledger requires reversed settlement status';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM route_cash_settlement_items item
        JOIN route_cash_settlement_reversals reversal ON reversal.id = item.reversed_by_route_cash_settlement_reversal_id
        WHERE item.route_cash_settlement_id = settlement_id
          AND (
              item.is_active
              OR reversal.route_cash_settlement_id <> item.route_cash_settlement_id
              OR reversal.business_id <> settlement_row.business_id
              OR reversal.branch_id <> settlement_row.branch_id
          )
    ) THEN
        RAISE EXCEPTION 'Settlement item reversal release must be inactive and match settlement scope';
    END IF;

    IF settlement_row.status = 'reversed' AND EXISTS (
        SELECT 1 FROM route_cash_settlement_items
        WHERE route_cash_settlement_id = settlement_id AND is_active
    ) THEN
        RAISE EXCEPTION 'Reversed settlement cannot retain active items';
    END IF;

    RETURN NULL;
END $$
SQL);

        foreach ([
            'route_cash_settlements' => 'route_cash_settlement_reversal_settlement_trigger',
            'route_cash_settlement_reversals' => 'route_cash_settlement_reversal_ledger_trigger',
            'route_cash_settlement_items' => 'route_cash_settlement_reversal_item_trigger',
        ] as $table => $trigger) {
            DB::statement("CREATE CONSTRAINT TRIGGER {$trigger} AFTER INSERT OR UPDATE OR DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_route_cash_settlement_reversal_integrity()");
        }
    }

    public function down(): void
    {
        if (DB::table('route_cash_settlement_reversals')->exists() || DB::table('route_cash_settlements')->where('status', 'reversed')->exists()) {
            throw new RuntimeException('Cannot roll back route cash settlement reversal persistence while reversal evidence exists.');
        }

        foreach ([
            'route_cash_settlements' => 'route_cash_settlement_reversal_settlement_trigger',
            'route_cash_settlement_reversals' => 'route_cash_settlement_reversal_ledger_trigger',
            'route_cash_settlement_items' => 'route_cash_settlement_reversal_item_trigger',
        ] as $table => $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS validate_route_cash_settlement_reversal_integrity()');
        DB::statement('ALTER TABLE route_cash_settlement_items DROP CONSTRAINT route_cash_settlement_items_reversal_release_check');
        Schema::table('route_cash_settlement_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by_route_cash_settlement_reversal_id');
        });
        Schema::dropIfExists('route_cash_settlement_reversals');
        DB::statement('ALTER TABLE route_cash_settlements DROP CONSTRAINT route_cash_settlements_state_check');
        DB::statement("ALTER TABLE route_cash_settlements ADD CONSTRAINT route_cash_settlements_state_check CHECK ((status = 'draft' AND cash_movement_id IS NULL AND confirmed_at IS NULL AND cancelled_at IS NULL) OR (status = 'confirmed' AND cash_movement_id IS NOT NULL AND cash_register_session_id IS NOT NULL AND received_by IS NOT NULL AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND received_amount IS NOT NULL AND received_amount > 0 AND difference_amount IS NOT NULL AND difference_amount = received_amount - expected_amount) OR (status = 'cancelled' AND cash_movement_id IS NULL AND cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND btrim(cancellation_reason) <> ''))");
    }
};
