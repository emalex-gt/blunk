<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('guatemala_departments')) {
            Schema::create('guatemala_departments', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('guatemala_municipalities')) {
            Schema::create('guatemala_municipalities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('guatemala_department_id')
                    ->constrained('guatemala_departments')
                    ->cascadeOnDelete();
                $table->string('name');
                $table->timestamps();

                $table->unique(['guatemala_department_id', 'name'], 'gt_municipalities_department_name_unique');
                $table->index('name');
            });
        }

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (! Schema::hasColumn('branches', 'department')) {
                    $table->string('department')->nullable()->after('address');
                }

                if (! Schema::hasColumn('branches', 'municipality')) {
                    $table->string('municipality')->nullable()->after('department');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (Schema::hasColumn('branches', 'municipality')) {
                    $table->dropColumn('municipality');
                }

                if (Schema::hasColumn('branches', 'department')) {
                    $table->dropColumn('department');
                }
            });
        }

        Schema::dropIfExists('guatemala_municipalities');
        Schema::dropIfExists('guatemala_departments');
    }
};
