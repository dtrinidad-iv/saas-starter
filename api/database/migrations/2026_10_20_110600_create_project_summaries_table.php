<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->text('summary')->nullable();
            $table->jsonb('key_points')->nullable();
            $table->string('model', 128)->nullable();
            $table->string('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // project_id leads so cascading deletes from projects don't scan the table.
            $table->index(['project_id', 'status']);
            $table->index('organization_id');
            $table->index('requested_by');
            $table->index('reviewed_by');
        });

        // At most one approved and one pending summary per project, even if a
        // future code path skips the actions' locks.
        DB::statement("create unique index project_summaries_one_approved on project_summaries (project_id) where status = 'approved'");
        DB::statement("create unique index project_summaries_one_pending on project_summaries (project_id) where status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_summaries');
    }
};
