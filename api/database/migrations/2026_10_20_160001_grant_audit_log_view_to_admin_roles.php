<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing organizations' Admin roles get audit_log.view, like new organizations'
 * (App\Enums\DefaultRole). Renamed or custom roles are left for owners to decide.
 * down() removes it from every Admin role (the permission didn't exist before).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE roles
            SET permissions = permissions || '["audit_log.view"]'::jsonb, updated_at = now()
            WHERE name = 'Admin' AND NOT is_owner AND NOT permissions @> '["audit_log.view"]'::jsonb
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE roles
            SET permissions = permissions - 'audit_log.view', updated_at = now()
            WHERE name = 'Admin' AND NOT is_owner
            SQL);
    }
};
