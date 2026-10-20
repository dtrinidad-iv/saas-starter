<?php

namespace App\Http\Requests\Roles;

use App\Enums\Permission;
use App\Models\Role;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating and updating a role: a name unique in the organization (ignoring case)
 * and the full list of permissions it should have.
 */
class RoleRequest extends FormRequest
{
    // Permission depends on the permissions being granted; checked in the controller.
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Role|null $role */
        $role = $this->route('role');

        return [
            'name' => ['required', 'string', 'max:50', function (string $attribute, mixed $value, Closure $fail) use ($role) {
                // Role queries are limited to the current organization.
                $taken = Role::query()
                    // lower() on both sides, like the unique index.
                    ->whereRaw('lower(name) = lower(?)', [trim((string) $value)])
                    ->when($role, fn ($query) => $query->whereKeyNot($role->id))
                    ->exists();

                if ($taken) {
                    $fail('There is already a role with this name.');
                }
            }],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['distinct', Rule::enum(Permission::class)],
        ];
    }

    public function roleName(): string
    {
        return trim($this->validated('name'));
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_map(Permission::from(...), $this->validated('permissions'));
    }
}
