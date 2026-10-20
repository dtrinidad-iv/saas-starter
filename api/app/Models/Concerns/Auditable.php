<?php

namespace App\Models\Concerns;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Support\CurrentOrganization;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Records created, updated and deleted events of a tenant-owned model in the audit log,
 * with the changed attributes. The model needs AuditAction cases "{auditName()}.created",
 * ".updated" and ".deleted". Hidden attributes, keys and timestamps are never recorded;
 * list other columns to leave out in $auditExclude.
 *
 * @property list<string> $auditExclude
 */
trait Auditable
{
    private static bool $auditingDisabled = false;

    private const AUDIT_ALWAYS_EXCLUDED = ['id', 'organization_id', 'created_at', 'updated_at'];

    private const AUDIT_MAX_LENGTH = 500;

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->recordAudit('created', fn ($key) => [null, $model->getAttribute($key)], array_keys($model->getAttributes())));
        static::updated(fn (Model $model) => $model->recordAudit('updated', fn ($key) => [$model->getOriginal($key), $model->getAttribute($key)], array_keys($model->getChanges())));
        static::deleted(fn (Model $model) => $model->recordAudit('deleted', fn ($key) => [$model->getAttribute($key), null], array_keys($model->getAttributes())));
    }

    /**
     * Run code without recording changes of any model of this class (e.g. setting up
     * a new organization). Applies to every save of the class inside the callback.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutAuditing(Closure $callback): mixed
    {
        $previous = self::$auditingDisabled;
        self::$auditingDisabled = true;

        try {
            return $callback();
        } finally {
            self::$auditingDisabled = $previous;
        }
    }

    /**
     * The subject type in audit entries, e.g. "project".
     */
    public function auditName(): string
    {
        return strtolower(class_basename($this));
    }

    public function auditLabel(): ?string
    {
        return $this->getAttribute('name');
    }

    /**
     * @param  Closure(string): array{0: mixed, 1: mixed}  $values  old and new value of an attribute
     * @param  list<string>  $keys
     */
    private function recordAudit(string $event, Closure $values, array $keys): void
    {
        if (self::$auditingDisabled) {
            return;
        }

        // The entry is written to the current organization: never let another
        // organization's record end up in its log (e.g. a model saved inside runAs()).
        if ($this->getAttribute('organization_id') !== app(CurrentOrganization::class)->id()) {
            throw new LogicException('An audited model can only be changed while acting in its organization.');
        }

        $excluded = [
            ...self::AUDIT_ALWAYS_EXCLUDED,
            ...$this->getHidden(),
            ...(property_exists($this, 'auditExclude') ? $this->auditExclude : []),
        ];
        $changes = [];

        foreach (array_diff($keys, $excluded) as $key) {
            [$old, $new] = array_map($this->auditValue(...), $values($key));

            // Compare full values; only what is stored is shortened.
            if ($old !== $new) {
                $changes[$key] = ['old' => $this->auditShorten($old), 'new' => $this->auditShorten($new)];
            }
        }

        if ($event === 'updated' && $changes === []) {
            return;
        }

        app(RecordAudit::class)->handle(
            AuditAction::from("{$this->auditName()}.{$event}"),
            ['type' => $this->auditName(), 'id' => $this->getKey(), 'label' => $this->auditLabel()],
            $changes,
        );
    }

    private function auditValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Arrayable => array_map($this->auditValue(...), $value->toArray()),
            is_array($value) => array_map($this->auditValue(...), $value),
            default => $value,
        };
    }

    private function auditShorten(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map($this->auditShorten(...), $value),
            is_string($value) && mb_strlen($value) > self::AUDIT_MAX_LENGTH => mb_substr($value, 0, self::AUDIT_MAX_LENGTH).'…',
            default => $value,
        };
    }
}
