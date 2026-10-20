<?php

namespace App\Http\Requests\Organizations\Concerns;

use App\Models\Role;
use App\Support\CurrentOrganization;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A role_id from the client is only a reference: it must be one of the current
 * organization's roles (another organization's id fails validation).
 */
trait ReferencesRole
{
    /**
     * @return list<mixed>
     */
    protected function roleIdRules(): array
    {
        return ['required', 'integer', $this->roleOfCurrentOrganization()];
    }

    /**
     * The validated role, loaded through the organization scope.
     */
    public function role(): Role
    {
        return Role::findOrFail($this->validated('role_id'));
    }

    private function roleOfCurrentOrganization(): Exists
    {
        return Rule::exists('roles', 'id')->where('organization_id', app(CurrentOrganization::class)->id());
    }
}
