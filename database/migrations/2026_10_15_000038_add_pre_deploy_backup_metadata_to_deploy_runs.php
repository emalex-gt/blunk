<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploy_runs', function (Blueprint $table) {
            $table->string('backup_path')->nullable()->after('output_log_path');
            $table->unsignedBigInteger('backup_size_bytes')->nullable()->after('backup_path');
            $table->timestamp('backup_created_at')->nullable()->after('backup_size_bytes');
            $table->timestamp('backup_validated_at')->nullable()->after('backup_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('deploy_runs', function (Blueprint $table) {
            $table->dropColumn([
                'backup_path',
                'backup_size_bytes',
                'backup_created_at',
                'backup_validated_at',
            ]);
        });
    }
};
