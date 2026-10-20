<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One audit log entry, written by RecordAudit. Entries are never changed; old ones
 * are deleted by model:prune after config('audit.retention_days').
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $actor_id
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property array<string, array{old: mixed, new: mixed}>|null $changes
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use BelongsToOrganization, MassPrunable;

    // Append-only log: no updated_at.
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted one by one.'));
    }

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'changes' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Old entries of every organization: the scheduler has no current organization.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = (int) config('audit.retention_days');

        return static::withoutGlobalScope(OrganizationScope::class)
            ->when(
                $days > 0,
                fn (Builder $query) => $query->where('created_at', '<', now()->subDays($days)),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
