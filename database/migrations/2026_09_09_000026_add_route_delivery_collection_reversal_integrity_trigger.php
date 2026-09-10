<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_route_delivery_collection_reversal_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; c record; p record; s record; pc record; event_count integer; captured_collection_id bigint; captured_payment_count integer;
BEGIN
    FOR r IN SELECT * FROM route_delivery_collection_reversals LOOP
        SELECT * INTO c FROM route_delivery_collections WHERE id = r.route_delivery_collection_id;
        SELECT * INTO p FROM sale_payments WHERE id = r.sale_payment_id;
        SELECT * INTO s FROM sales WHERE id = c.sale_id;
        IF NOT FOUND OR c.status <> 'reversed' OR p.status <> 'reversed' OR p.route_delivery_collection_id <> c.id
           OR c.business_id <> r.business_id OR c.branch_id <> r.branch_id OR p.business_id <> r.business_id
           OR s.business_id <> r.business_id OR s.branch_id <> r.branch_id THEN
            RAISE EXCEPTION 'Route delivery collection reversal financial post-state mismatch';
        END IF;
        SELECT other.id INTO captured_collection_id FROM route_delivery_collections other WHERE other.sale_id = c.sale_id AND other.status = 'captured' ORDER BY other.id LIMIT 1;
        IF captured_collection_id IS NULL THEN
            IF s.payment_status <> 'unpaid' OR s.amount_paid <> 0 OR s.payment_method IS NOT NULL THEN
                RAISE EXCEPTION 'Reversed collection without replacement must leave the sale unpaid';
            END IF;
        ELSE
            SELECT count(*) INTO captured_payment_count FROM sale_payments replacement_payment WHERE replacement_payment.route_delivery_collection_id = captured_collection_id AND replacement_payment.sale_id = c.sale_id AND replacement_payment.business_id = r.business_id AND replacement_payment.status = 'captured';
            IF s.payment_status <> 'paid' OR s.amount_paid <> s.total OR captured_payment_count <> 1 THEN
                RAISE EXCEPTION 'Replacement captured collection does not restore a complete paid sale';
            END IF;
        END IF;
        IF EXISTS (SELECT 1 FROM route_cash_settlement_items i JOIN route_cash_settlements st ON st.id = i.route_cash_settlement_id WHERE i.route_delivery_collection_id = c.id AND i.is_active AND st.status IN ('draft', 'confirmed')) THEN
            RAISE EXCEPTION 'Reversed collection cannot retain an active cash settlement item';
        END IF;
        IF r.route_pending_collection_case_id IS NULL THEN
            IF r.previous_case_resolved_by IS NOT NULL OR r.previous_case_resolved_at IS NOT NULL THEN RAISE EXCEPTION 'Reversal without case cannot retain case snapshot'; END IF;
        ELSE
            SELECT * INTO pc FROM route_pending_collection_cases WHERE id = r.route_pending_collection_case_id;
            IF NOT FOUND OR pc.business_id <> r.business_id OR pc.branch_id <> r.branch_id OR pc.sale_id <> c.sale_id OR pc.pre_sale_id <> c.pre_sale_id
               OR r.previous_case_resolved_by IS NULL OR r.previous_case_resolved_at IS NULL THEN
                RAISE EXCEPTION 'Route pending collection case reversal post-state mismatch';
            END IF;
            IF captured_collection_id IS NULL AND (pc.status <> 'open' OR pc.resolved_by IS NOT NULL OR pc.resolved_at IS NOT NULL OR pc.resolution_route_delivery_collection_id IS NOT NULL) THEN
                RAISE EXCEPTION 'Unreplaced reversal case must remain open';
            END IF;
            IF captured_collection_id IS NOT NULL AND (pc.status <> 'resolved' OR pc.resolved_by IS NULL OR pc.resolved_at IS NULL OR pc.resolution_route_delivery_collection_id <> captured_collection_id) THEN
                RAISE EXCEPTION 'Replacement captured collection must resolve the reopened case';
            END IF;
            SELECT count(*) INTO event_count FROM route_pending_collection_events e WHERE e.route_pending_collection_case_id = pc.id AND e.type = 'collection_reversed' AND e.business_id = r.business_id AND e.branch_id = r.branch_id AND e.recorded_by = r.reversed_by AND e.occurred_at = r.reversed_at;
            IF event_count <> 1 THEN RAISE EXCEPTION 'Route collection reversal requires exactly one matching internal event'; END IF;
        END IF;
        IF r.cash_correction_type = 'none' AND (r.compensating_cash_movement_id IS NOT NULL OR (c.payment_method = 'cash' AND c.custody_status = 'posted_to_branch_cash')) THEN
            RAISE EXCEPTION 'None cash correction is incompatible with posted cash';
        END IF;
        IF r.cash_correction_type = 'historical_closed_session_ledger' AND (r.compensating_cash_movement_id IS NOT NULL OR c.cash_movement_id IS NULL OR NOT EXISTS (SELECT 1 FROM cash_register_sessions cs JOIN cash_movements cm ON cm.cash_register_session_id = cs.id WHERE cm.id = c.cash_movement_id AND cs.status = 'closed')) THEN
            RAISE EXCEPTION 'Historical cash reversal requires original closed-session movement only';
        END IF;
        IF r.cash_correction_type = 'current_open_session_adjustment' AND NOT EXISTS (SELECT 1 FROM cash_movements cm JOIN cash_register_sessions cs ON cs.id = cm.cash_register_session_id WHERE cm.id = r.compensating_cash_movement_id AND cm.amount = -c.amount AND cm.type = 'route_delivery_collection_reversal_current_session' AND cm.reference_type = 'route_delivery_collection_reversal' AND cm.reference_id = r.id AND cs.status = 'open' AND cm.cash_register_session_id = c.cash_register_session_id) THEN
            RAISE EXCEPTION 'Current-session cash reversal adjustment mismatch';
        END IF;
    END LOOP;
    IF EXISTS (SELECT 1 FROM route_delivery_collections collection_row WHERE collection_row.status = 'reversed' AND NOT EXISTS (SELECT 1 FROM route_delivery_collection_reversals reversal_row WHERE reversal_row.route_delivery_collection_id = collection_row.id)) THEN
        RAISE EXCEPTION 'Reversed collection requires reversal ledger';
    END IF;
    IF EXISTS (SELECT 1 FROM route_delivery_collections collection_row WHERE collection_row.status = 'captured' AND EXISTS (SELECT 1 FROM route_delivery_collection_reversals reversal_row WHERE reversal_row.route_delivery_collection_id = collection_row.id)) THEN
        RAISE EXCEPTION 'Captured collection cannot have reversal ledger';
    END IF;
    RETURN NULL;
END $$
SQL);
        foreach ([
            'route_delivery_collections' => 'route_delivery_reversal_collection_trigger',
            'sale_payments' => 'route_delivery_reversal_payment_trigger',
            'route_delivery_collection_reversals' => 'route_delivery_reversal_ledger_trigger',
            'route_cash_settlement_items' => 'route_delivery_reversal_settlement_item_trigger',
            'route_cash_settlements' => 'route_delivery_reversal_settlement_trigger',
        ] as $table => $trigger) {
            DB::statement("CREATE CONSTRAINT TRIGGER {$trigger} AFTER INSERT OR UPDATE OR DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_route_delivery_collection_reversal_integrity()");
        }
    }

    public function down(): void
    {
        if (DB::table('route_delivery_collection_reversals')->exists()) {
            throw new RuntimeException('Cannot roll back 3E-B1 reversal integrity trigger while reversals exist.');
        }
        foreach ([
            'route_delivery_collections' => 'route_delivery_reversal_collection_trigger',
            'sale_payments' => 'route_delivery_reversal_payment_trigger',
            'route_delivery_collection_reversals' => 'route_delivery_reversal_ledger_trigger',
            'route_cash_settlement_items' => 'route_delivery_reversal_settlement_item_trigger',
            'route_cash_settlements' => 'route_delivery_reversal_settlement_trigger',
        ] as $table => $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS validate_route_delivery_collection_reversal_integrity()');
    }
};
