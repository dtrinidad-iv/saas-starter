<?php

namespace App\Models;

use App\Enums\ProjectSummaryStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An AI-generated summary of a project. Starts pending, becomes a draft when the
 * job finishes, and is only shown as the project's summary once a user approves it.
 * All columns are set by actions and the job (no mass assignment).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property ProjectSummaryStatus $status
 * @property string|null $summary
 * @property list<string>|null $key_points
 * @property string|null $model
 * @property string|null $error
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class ProjectSummary extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'status' => ProjectSummaryStatus::class,
            'key_points' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
