<?php

namespace App\Models;

use App\Enums\ProjectSummaryStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property int|null $created_by
 */
#[Fillable(['name', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use Auditable, BelongsToOrganization, HasFactory;

    /** The creator is the actor of project.created already. */
    protected array $auditExclude = ['created_by'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ProjectSummary, $this>
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(ProjectSummary::class);
    }

    /**
     * @return HasOne<ProjectSummary, $this>
     */
    public function latestSummary(): HasOne
    {
        return $this->hasOne(ProjectSummary::class)->latestOfMany();
    }

    /**
     * The project's summary as shown to everyone: the approved one (at most one,
     * enforced by a unique index), which stays until a newer one is approved.
     *
     * @return HasOne<ProjectSummary, $this>
     */
    public function approvedSummary(): HasOne
    {
        return $this->hasOne(ProjectSummary::class)->where('status', ProjectSummaryStatus::Approved);
    }
}
