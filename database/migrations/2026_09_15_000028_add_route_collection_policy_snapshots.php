<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_delivery_batches', function (Blueprint $table) {
            $table->string('collection_workflow_mode_snapshot', 32)->nullable();
            $table->jsonb('allowed_payment_methods_snapshot')->nullable();
            $table->string('primary_payment_method_snapshot', 32)->nullable();
        });

        Schema::table('route_delivery_batch_pre_sales', function (Blueprint $table) {
            $table->string('agreed_payment_method_snapshot', 32)->nullable();
        });

        DB::statement("ALTER TABLE route_delivery_batches ADD CONSTRAINT route_delivery_batches_policy_snapshot_bundle_check CHECK ((collection_workflow_mode_snapshot IS NULL AND allowed_payment_methods_snapshot IS NULL AND primary_payment_method_snapshot IS NULL) OR (collection_workflow_mode_snapshot IS NOT NULL AND allowed_payment_methods_snapshot IS NOT NULL AND primary_payment_method_snapshot IS NOT NULL))");
        DB::statement("ALTER TABLE route_delivery_batches ADD CONSTRAINT route_delivery_batches_policy_snapshot_workflow_check CHECK (collection_workflow_mode_snapshot IS NULL OR collection_workflow_mode_snapshot IN ('immediate_paid', 'per_order_collection'))");
        DB::statement("ALTER TABLE route_delivery_batches ADD CONSTRAINT route_delivery_batches_policy_snapshot_allowed_check CHECK (allowed_payment_methods_snapshot IS NULL OR (jsonb_typeof(allowed_payment_methods_snapshot) = 'array' AND jsonb_array_length(allowed_payment_methods_snapshot) > 0 AND allowed_payment_methods_snapshot <@ '[\"cash\", \"card\", \"transfer\", \"check\"]'::jsonb))");
        DB::statement("ALTER TABLE route_delivery_batches ADD CONSTRAINT route_delivery_batches_policy_snapshot_primary_check CHECK (primary_payment_method_snapshot IS NULL OR primary_payment_method_snapshot IN ('cash', 'card', 'transfer', 'check'))");
        DB::statement("ALTER TABLE route_delivery_batches ADD CONSTRAINT route_delivery_batches_policy_snapshot_primary_allowed_check CHECK (primary_payment_method_snapshot IS NULL OR allowed_payment_methods_snapshot @> jsonb_build_array(primary_payment_method_snapshot))");
        DB::statement("ALTER TABLE route_delivery_batch_pre_sales ADD CONSTRAINT route_delivery_batch_pre_sales_agreed_method_snapshot_check CHECK (agreed_payment_method_snapshot IS NULL OR agreed_payment_method_snapshot IN ('cash', 'card', 'transfer', 'check'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_route_delivery_batch_policy_snapshot_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.collection_workflow_mode_snapshot IS DISTINCT FROM OLD.collection_workflow_mode_snapshot
                    OR NEW.allowed_payment_methods_snapshot IS DISTINCT FROM OLD.allowed_payment_methods_snapshot
                    OR NEW.primary_payment_method_snapshot IS DISTINCT FROM OLD.primary_payment_method_snapshot THEN
                    RAISE EXCEPTION 'Route delivery batch policy snapshots are immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER route_delivery_batches_policy_snapshot_immutable
            BEFORE UPDATE OF collection_workflow_mode_snapshot, allowed_payment_methods_snapshot, primary_payment_method_snapshot
            ON route_delivery_batches
            FOR EACH ROW EXECUTE FUNCTION prevent_route_delivery_batch_policy_snapshot_mutation();

            CREATE OR REPLACE FUNCTION prevent_route_delivery_batch_pre_sale_agreed_method_snapshot_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.agreed_payment_method_snapshot IS DISTINCT FROM OLD.agreed_payment_method_snapshot THEN
                    RAISE EXCEPTION 'Route delivery batch pre-sale agreed payment method snapshot is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER route_delivery_batch_pre_sales_agreed_method_snapshot_immutable
            BEFORE UPDATE OF agreed_payment_method_snapshot
            ON route_delivery_batch_pre_sales
            FOR EACH ROW EXECUTE FUNCTION prevent_route_delivery_batch_pre_sale_agreed_method_snapshot_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS route_delivery_batch_pre_sales_agreed_method_snapshot_immutable ON route_delivery_batch_pre_sales');
        DB::statement('DROP FUNCTION IF EXISTS prevent_route_delivery_batch_pre_sale_agreed_method_snapshot_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS route_delivery_batches_policy_snapshot_immutable ON route_delivery_batches');
        DB::statement('DROP FUNCTION IF EXISTS prevent_route_delivery_batch_policy_snapshot_mutation()');
        DB::statement('ALTER TABLE route_delivery_batch_pre_sales DROP CONSTRAINT IF EXISTS route_delivery_batch_pre_sales_agreed_method_snapshot_check');
        DB::statement('ALTER TABLE route_delivery_batches DROP CONSTRAINT IF EXISTS route_delivery_batches_policy_snapshot_primary_allowed_check');
        DB::statement('ALTER TABLE route_delivery_batches DROP CONSTRAINT IF EXISTS route_delivery_batches_policy_snapshot_primary_check');
        DB::statement('ALTER TABLE route_delivery_batches DROP CONSTRAINT IF EXISTS route_delivery_batches_policy_snapshot_allowed_check');
        DB::statement('ALTER TABLE route_delivery_batches DROP CONSTRAINT IF EXISTS route_delivery_batches_policy_snapshot_workflow_check');
        DB::statement('ALTER TABLE route_delivery_batches DROP CONSTRAINT IF EXISTS route_delivery_batches_policy_snapshot_bundle_check');

        Schema::table('route_delivery_batch_pre_sales', function (Blueprint $table) {
            $table->dropColumn('agreed_payment_method_snapshot');
        });

        Schema::table('route_delivery_batches', function (Blueprint $table) {
            $table->dropColumn([
                'collection_workflow_mode_snapshot',
                'allowed_payment_methods_snapshot',
                'primary_payment_method_snapshot',
            ]);
        });
    }
};
