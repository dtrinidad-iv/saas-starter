<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only "view as user" tokens (StartImpersonation): the platform admin who
 * opened it, the organization the session is looking at (switching changes this,
 * never the user's own current organization), and the organizations whose audit
 * log already has the "viewed as" entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->index()->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->jsonb('viewed_organization_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('viewed_organization_ids');
        });
    }
};
