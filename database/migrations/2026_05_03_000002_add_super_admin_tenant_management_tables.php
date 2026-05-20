<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_super_admin')) {
                $table->boolean('is_super_admin')->default(false)->after('role');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('name');
            }

            if (! Schema::hasColumn('businesses', 'country')) {
                $table->string('country')->nullable()->after('currency');
            }

            if (! Schema::hasColumn('businesses', 'phone')) {
                $table->string('phone')->nullable()->after('country');
            }

            if (! Schema::hasColumn('businesses', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }

            if (! Schema::hasColumn('businesses', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('email');
            }
        });

        if (! Schema::hasTable('tenant_settings')) {
            Schema::create('tenant_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('use_product_images')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('plan_name');
                $table->string('status');
                $table->decimal('price_amount', 12, 2)->default(0);
                $table->string('currency', 3)->default('GTQ');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('paused_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');

        Schema::table('businesses', function (Blueprint $table) {
            foreach (['slug', 'country', 'phone', 'email', 'is_active'] as $column) {
                if (Schema::hasColumn('businesses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_super_admin')) {
                $table->dropColumn('is_super_admin');
            }
        });
    }
};
