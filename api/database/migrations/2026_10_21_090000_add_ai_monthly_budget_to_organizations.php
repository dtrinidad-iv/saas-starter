<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Null: the default from config/ai.php (AI_MONTHLY_BUDGET_USD). 0 turns AI off.
            $table->decimal('ai_monthly_budget_usd', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('ai_monthly_budget_usd');
        });
    }
};
