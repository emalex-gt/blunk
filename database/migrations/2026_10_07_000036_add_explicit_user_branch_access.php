<?php

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_branches_access')->default(false)->after('current_branch_id');
        });

        Schema::create('user_branch_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'branch_id']);
            $table->index(['business_id', 'branch_id']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_user_branch_access_tenant_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    user_business_id bigint;
    branch_business_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'user_branch_accesses' THEN
        SELECT business_id INTO user_business_id FROM users WHERE id = NEW.user_id;
        SELECT business_id INTO branch_business_id FROM branches WHERE id = NEW.branch_id;

        IF user_business_id IS NULL OR branch_business_id IS NULL
           OR user_business_id <> NEW.business_id OR branch_business_id <> NEW.business_id THEN
            RAISE EXCEPTION 'User branch access must belong to the same business';
        END IF;
    ELSIF TG_TABLE_NAME = 'users' THEN
        IF EXISTS (
            SELECT 1
            FROM user_branch_accesses access
            JOIN branches branch ON branch.id = access.branch_id
            WHERE access.user_id = NEW.id
              AND (
                  access.business_id IS DISTINCT FROM NEW.business_id
                  OR branch.business_id IS DISTINCT FROM NEW.business_id
              )
        ) THEN
            RAISE EXCEPTION 'User branch access must belong to the same business';
        END IF;
    ELSIF TG_TABLE_NAME = 'branches' THEN
        IF EXISTS (
            SELECT 1
            FROM user_branch_accesses access
            JOIN users "user" ON "user".id = access.user_id
            WHERE access.branch_id = NEW.id
              AND (
                  access.business_id IS DISTINCT FROM NEW.business_id
                  OR "user".business_id IS DISTINCT FROM NEW.business_id
              )
        ) THEN
            RAISE EXCEPTION 'User branch access must belong to the same business';
        END IF;
    END IF;

    RETURN NEW;
END $$;

CREATE CONSTRAINT TRIGGER user_branch_accesses_tenant_integrity
AFTER INSERT OR UPDATE ON user_branch_accesses
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validate_user_branch_access_tenant_integrity();

CREATE CONSTRAINT TRIGGER user_branch_accesses_user_tenant_integrity
AFTER UPDATE ON users
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validate_user_branch_access_tenant_integrity();

CREATE CONSTRAINT TRIGGER user_branch_accesses_branch_tenant_integrity
AFTER UPDATE ON branches
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validate_user_branch_access_tenant_integrity();
SQL);

        User::query()
            ->where('is_super_admin', false)
            ->whereNotNull('business_id')
            ->orderBy('id')
            ->each(function (User $user): void {
                if (Permissions::userHas($user, Permissions::BRANCHES_SWITCH)) {
                    $user->forceFill(['all_branches_access' => true])->save();

                    return;
                }

                if (! $user->current_branch_id) {
                    return;
                }

                $valid = DB::table('branches')
                    ->where('id', $user->current_branch_id)
                    ->where('business_id', $user->business_id)
                    ->where('is_active', true)
                    ->exists();

                if ($valid) {
                    DB::table('user_branch_accesses')->insert([
                        'business_id' => $user->business_id,
                        'user_id' => $user->id,
                        'branch_id' => $user->current_branch_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS user_branch_accesses_tenant_integrity ON user_branch_accesses');
        DB::statement('DROP TRIGGER IF EXISTS user_branch_accesses_user_tenant_integrity ON users');
        DB::statement('DROP TRIGGER IF EXISTS user_branch_accesses_branch_tenant_integrity ON branches');
        DB::statement('DROP FUNCTION IF EXISTS validate_user_branch_access_tenant_integrity()');
        Schema::dropIfExists('user_branch_accesses');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('all_branches_access'));
    }
};
