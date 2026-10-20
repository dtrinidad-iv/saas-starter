<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the fixed role strings on memberships and invitations with per-organization
 * roles. Existing organizations get Owner, Admin and Member with the permissions the
 * old fixed roles had (a snapshot here, so later changes to App\Enums\DefaultRole
 * don't change what this migration does).
 */
return new class extends Migration
{
    /** @var array<string, array{name: string, is_owner: bool, permissions: list<string>}> */
    private const DEFAULTS = [
        'owner' => ['name' => 'Owner', 'is_owner' => true, 'permissions' => []],
        'admin' => ['name' => 'Admin', 'is_owner' => false, 'permissions' => [
            'projects.view', 'projects.create', 'projects.update_own', 'projects.update_any', 'projects.delete',
            'members.invite', 'members.change_role', 'members.remove',
        ]],
        'member' => ['name' => 'Member', 'is_owner' => false, 'permissions' => [
            'projects.view', 'projects.create', 'projects.update_own',
        ]],
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->boolean('is_owner')->default(false); // all permissions; can't be edited or deleted
            $table->jsonb('permissions')->default('[]'); // App\Enums\Permission values
            $table->timestamps();

            // Target of the (organization_id, role_id) foreign keys below.
            $table->unique(['organization_id', 'id']);
        });

        DB::statement('CREATE UNIQUE INDEX roles_organization_id_lower_name_unique ON roles (organization_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX roles_one_owner_per_organization ON roles (organization_id) WHERE is_owner');

        Schema::table('organization_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->index();
        });
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->index();
        });

        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            foreach (self::DEFAULTS as $old => $role) {
                $roleId = DB::table('roles')->insertGetId([
                    'organization_id' => $organizationId,
                    'name' => $role['name'],
                    'is_owner' => $role['is_owner'],
                    'permissions' => json_encode($role['permissions']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach (['organization_user', 'organization_invitations'] as $table) {
                    DB::table($table)
                        ->where('organization_id', $organizationId)
                        ->where('role', $old)
                        ->update(['role_id' => $roleId]);
                }
            }
        }

        Schema::table('organization_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable(false)->change();
            $table->dropColumn('role');
        });
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        // A membership or invitation can only point at a role of its own organization.
        // Roles in use by members can't be deleted (the app checks first, with a message);
        // invitations lose their role instead and stop being usable. NO ACTION (not
        // RESTRICT) is checked at the end of the statement, so deleting an organization
        // works whichever of its cascades (members, roles) runs first.
        DB::statement('ALTER TABLE organization_user ADD CONSTRAINT organization_user_role_foreign
            FOREIGN KEY (organization_id, role_id) REFERENCES roles (organization_id, id) ON DELETE NO ACTION');
        DB::statement('ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_role_foreign
            FOREIGN KEY (organization_id, role_id) REFERENCES roles (organization_id, id) ON DELETE SET NULL (role_id)');
    }

    public function down(): void
    {
        foreach (['organization_user', 'organization_invitations'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_role_foreign");

            Schema::table($table, function (Blueprint $table) {
                $table->string('role')->nullable();
            });

            // Custom roles don't exist in the old model: map by what they can do.
            DB::statement("UPDATE {$table} t SET role = CASE
                    WHEN r.is_owner THEN 'owner'
                    WHEN r.permissions @> '[\"members.change_role\"]' THEN 'admin'
                    ELSE 'member'
                END
                FROM roles r WHERE r.id = t.role_id");
            DB::table($table)->whereNull('role')->update(['role' => 'member']);

            Schema::table($table, function (Blueprint $table) {
                $table->string('role')->nullable(false)->change();
                $table->dropColumn('role_id');
            });
        }

        Schema::dropIfExists('roles');
    }
};
