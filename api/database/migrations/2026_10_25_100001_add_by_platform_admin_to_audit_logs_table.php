<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A change a platform admin made in the organization (e.g. MakeMemberOwner). The
 * organization sees that support did it, but not who: those entries have no actor or IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->boolean('by_platform_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('by_platform_admin');
        });
    }
};
