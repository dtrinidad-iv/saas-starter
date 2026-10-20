<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what in an organization. Append-only; old rows are pruned (config/audit.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64); // App\Enums\AuditAction value
            $table->string('subject_type', 64)->nullable(); // e.g. project, role, member
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable(); // name at the time, kept after the subject is deleted
            $table->jsonb('changes')->nullable(); // {field: {old, new}}
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // The list is newest first by id, optionally filtered by action or actor.
            $table->index(['organization_id', 'id']);
            $table->index(['organization_id', 'action', 'id']);
            $table->index(['organization_id', 'actor_id', 'id']);
            $table->index(['organization_id', 'created_at']); // from/to filter
            $table->index('actor_id');
            $table->index('created_at'); // pruning across organizations
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
