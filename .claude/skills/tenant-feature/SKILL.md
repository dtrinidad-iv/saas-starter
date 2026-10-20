---
name: tenant-feature
description: Step-by-step recipe for adding a tenant-owned (organization-scoped) feature across api/ and web/, modeled on Projects. Use when building any new model or resource that belongs to an organization (e.g. tasks, clients, documents).
argument-hint: "[feature, e.g. clients]"
---

# Add a tenant-owned feature

Feature: $ARGUMENTS

Projects is the reference implementation. Open each file named below and follow its shape rather than inventing a new one. If the change touches more than 3 files (it will), run the `plan-task` skill first and wait for approval.

## API (`api/`)
1. **Migration** (`php artisan make:migration create_<things>_table`). Reference: `database/migrations/*_create_projects_table.php`.
   - `foreignId('organization_id')->constrained()->cascadeOnDelete()`.
   - Unique constraints are per organization: `unique(['organization_id', 'name'])`. Put `organization_id` first in composite indexes.
   - Users are referenced with `nullable()->constrained('users')->nullOnDelete()`, so a removed member doesn't delete the data.
   - Never edit a migration that has been committed; add a new one.
2. **Model + factory.** Reference: `app/Models/Project.php`, `database/factories/ProjectFactory.php`.
   - `use BelongsToOrganization, HasFactory;`. This trait is what isolates organizations: a global scope that fails closed, `organization_id` forced on create, and a LogicException if it changes.
   - `#[Fillable([...])]`. Never make `organization_id` fillable.
   - In tests and seeders, create records inside `inOrganization($org, fn () => ...)`.
   - If users create, edit or delete it, also `use Auditable;` and add `<thing>.created|updated|deleted` cases (with labels) to `App\Enums\AuditAction`. List columns that shouldn't be logged in `$auditExclude`.
3. **Policy.** Reference: `app/Policies/ProjectPolicy.php`. Decide by `CurrentOrganization::role()` (owner, admin or member) and ownership fields. Never check `organization_id` here; the scope already handles that.
4. **Actions.** Reference: `app/Actions/Projects/`. One class per write operation. It sets server-side fields such as `created_by` and dispatches domain events (`app/Events/ProjectCreated.php`).
5. **Form Requests.** Reference: `app/Http/Requests/Projects/`.
   - Unique rules: `Rule::unique('<table>', 'name')->where('organization_id', app(CurrentOrganization::class)->id())`, plus `->ignore(...)` on update.
   - `exists:` rules pointing at another tenant-owned table must also be scoped to the organization. Otherwise a user can link to another organization's record.
6. **Resource.** Reference: `app/Http/Resources/ProjectResource.php`. Include `can: {update, delete}` from the policy so the frontend never duplicates the rules. Load relations with `whenLoaded`.
7. **Controller.** Reference: `app/Http/Controllers/Api/ProjectController.php`. Keep it thin: `Gate::authorize`, call the action, return the resource. For lists, eager load (`with`), paginate (`paginate(20)->withQueryString()`), and escape `%` and `_` in search.
8. **Route.** Put it in `routes/api.php` inside the `Route::middleware('organization')` group: `Route::apiResource('<things>', ...)->whereNumber('<thing>')`.
9. **Queued jobs and listeners.** Models come back without global scopes, but any *other* scoped query inside the job returns nothing until you call `app(CurrentOrganization::class)->set($organization)`.

## Tests (`api/tests/Feature/<Things>/`)
Use the helpers from `tests/Pest.php`: `userInOrganization()`, `addMember()`, `inOrganization()`.
- **`<Thing>IsolationTest.php`** is required (see `ProjectIsolationTest.php`). Org A's user must:
  - not see B's records in lists or search;
  - get 404 when reading, updating or deleting one of B's records, with B's data unchanged;
  - still be isolated when they belong to both organizations but haven't switched.
- **`<Thing>PermissionsTest.php`:** the full role × action table.
- **`<Thing>CrudTest.php`:**
  - CRUD, with an `organization_id` in the request being ignored;
  - validation;
  - uniqueness per organization;
  - pagination and search;
  - events (`Event::fake`);
  - audit entries for create, update and delete (`auditLogOf($org)`);
  - 401 without login and 403 without a current organization.
- Prove the tests catch mistakes: temporarily remove the trait or make the policy allow everything, confirm tests fail, then restore it.

## Web (`web/app/`)
1. **Types** in `types/api.ts`: `<Thing>` (with `can`), `<Thing>Payload`, and `Paginated<T>`.
2. **Form component:** reference `components/ProjectForm.vue`. It emits `submit` and `cancel`, and exposes `setErrors` for 422 responses via `toFormErrors`.
3. **List page:** reference `pages/<things>/index.vue` (from `pages/projects/index.vue`).
   - `useAsyncData` with a stable key, watching page and debounced search.
   - Reset to page 1 when the organization changes. `refreshNuxtData()` already runs on switch.
   - Use UTable with an empty slot, UPagination, and a create modal.
4. **Detail page:** reference `pages/<things>/[id].vue`.
   - Return a result object with ok or error, so a 404 shows "not found" (another organization's record shows the same thing).
   - Show Edit and Delete only according to `can`. Confirm deletes in a UModal.
   - Go back to the list when the organization changes.
5. **Navigation:** add a link in `layouts/default.vue` inside the `auth.user.current_organization` block.
6. Make every API call through `useApi()`. Never send `organization_id` from the frontend.

## Finish
- Run the `verify` skill, then `e2e-smoke` for the flow.
- Run the reviewer subagents on the diff: `code-reviewer`, `security-auditor`, `tenant-isolation-reviewer` and `database-reviewer`, plus `ui-reviewer` for the pages.
- Report to the user and wait for approval before committing (the `ship-plan` skill).
