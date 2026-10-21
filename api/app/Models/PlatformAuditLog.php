<?php

namespace App\Models;

use App\Enums\PlatformAuditAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One platform audit log entry, written by RecordPlatformAudit. Not tenant-owned.
 * Entries are never changed; old ones are deleted by model:prune after
 * config('audit.retention_days').
 *
 * @property int $id
 * @property int|null $actor_id
 * @property PlatformAuditAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class PlatformAuditLog extends Model
{
    use MassPrunable;

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
            'action' => PlatformAuditAction::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = (int) config('audit.retention_days');

        return static::query()->when(
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
