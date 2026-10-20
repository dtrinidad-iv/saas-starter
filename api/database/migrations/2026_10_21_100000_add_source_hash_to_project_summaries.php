<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_summaries', function (Blueprint $table) {
            // sha256 of the project fields the summary was written from (ProjectSummaryPrompt::sourceHash).
            // Null for summaries from before this column, which are never shown as stale.
            $table->char('source_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_summaries', function (Blueprint $table) {
            $table->dropColumn('source_hash');
        });
    }
};
