<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_register_sessions')) {
            Schema::create('cash_register_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status')->default('open');
                $table->decimal('opening_amount', 12, 2)->default(0);
                $table->decimal('expected_cash', 12, 2)->default(0);
                $table->decimal('counted_cash', 12, 2)->nullable();
                $table->decimal('difference', 12, 2)->nullable();
                $table->timestamp('opened_at');
                $table->timestamp('closed_at')->nullable();
                $table->text('notes')->nullable();
                $table->text('closing_notes')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'opened_at']);
                $table->index(['business_id', 'closed_at']);
            });
        }

        if (! Schema::hasTable('cash_movements')) {
            Schema::create('cash_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('cash_register_session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
                $table->string('type');
                $table->decimal('amount', 12, 2);
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->text('description')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'cash_register_session_id']);
                $table->index(['business_id', 'created_at']);
                $table->index(['reference_type', 'reference_id']);
            });
        }

        if (! Schema::hasTable('cash_expenses')) {
            Schema::create('cash_expenses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('cash_register_session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
                $table->string('category')->nullable();
                $table->string('description');
                $table->decimal('amount', 12, 2);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'cash_register_session_id']);
                $table->index(['business_id', 'created_at']);
            });
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'paid_from_cash')) {
                $table->boolean('paid_from_cash')->default(false)->after('note');
            }

            if (! Schema::hasColumn('purchases', 'cash_register_session_id')) {
                $table->foreignId('cash_register_session_id')
                    ->nullable()
                    ->after('paid_from_cash')
                    ->constrained('cash_register_sessions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'cash_register_session_id')) {
                $table->dropConstrainedForeignId('cash_register_session_id');
            }

            if (Schema::hasColumn('purchases', 'paid_from_cash')) {
                $table->dropColumn('paid_from_cash');
            }
        });

        Schema::dropIfExists('cash_expenses');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_register_sessions');
    }
};
