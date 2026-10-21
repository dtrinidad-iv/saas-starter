<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Cashier\Billable;

/**
 * @property string|null $ai_monthly_budget_usd Null uses the plan's budget; otherwise a cap below it.
 * @property string|null $stripe_id Set once the organization is a Stripe customer (Cashier).
 * @property-read Membership|null $membership Set when loaded through User::organizations().
 */
#[Fillable(['name'])]
#[Hidden(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at'])]
class Organization extends Model
{
    // The organization, not the user, is the Stripe customer (Cashier::useCustomerModel).
    use Billable;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ai_monthly_budget_usd' => 'decimal:2',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('role_id')
            ->withTimestamps();
    }
}
