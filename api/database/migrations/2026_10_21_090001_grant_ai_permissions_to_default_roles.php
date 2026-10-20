<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing organizations' default roles get the new AI permissions, like new
 * organizations' (App\Enums\DefaultRole): ai.use for Admin and Member, and
 * ai_usage.view for Admin. Without this, AI would stop working for them.
 * Renamed or custom roles are left for owners to decide. ai_budget.manage stays
 * with the owner. down() removes them from those roles.
 */
return new class extends Migration
{
    private const GRANTS = [
        'Admin' => ['ai.use', 'ai_usage.view'],
        'Member' => ['ai.use'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $role => $permissions) {
            foreach ($permissions as $permission) {
                DB::update(<<<'SQL'
                    UPDATE roles
                    SET permissions = permissions || jsonb_build_array(?::text), updated_at = now()
                    WHERE name = ? AND NOT is_owner AND NOT permissions @> jsonb_build_array(?::text)
                    SQL, [$permission, $role, $permission]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $role => $permissions) {
            foreach ($permissions as $permission) {
                DB::update(<<<'SQL'
                    UPDATE roles
                    SET permissions = permissions - ?::text, updated_at = now()
                    WHERE name = ? AND NOT is_owner
                    SQL, [$permission, $role]);
            }
        }
    }
};
