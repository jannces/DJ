<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Rbac\RbacService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The five roles are fixed by the LGU's structure, so this controller edits
 * them and does not create or delete them.
 *
 * There is no `create`/`store` any more: a sixth role invented from a form
 * would hold permissions nothing in the organisation answers for, and the
 * five that exist are seeded. Deleting was already refused — every one of them
 * is `is_system`.
 */
class RoleController extends Controller
{
    /**
     * The wildcard is never assignable from this form.
     *
     * `*` satisfies every permission check in the application. It was rendered
     * as an ordinary checkbox on the role form, so one click could grant any
     * role unrestricted access to users, security, settings and every
     * employee's leave record — permanently, with an audit line as the only
     * trace. No role holds it now and none can be given it here.
     *
     * Refused on the way in as well as hidden on the way out: this form can be
     * replayed with any permission id in it, and hiding a control is not access
     * control.
     */
    public const NEVER_ASSIGNABLE = ['*'];
    public function __construct(
        private readonly RbacService $rbac,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): View
    {
        $roles = Role::withCount(['permissions', 'users'])->with('parent')->orderBy('name')->get();

        return view('admin.roles.index', compact('roles'));
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'role' => $role,
            'roles' => Role::where('id', '!=', $role->id)->orderBy('name')->get(),
            'permissions' => $this->assignablePermissions(),
            'assigned' => $role->permissions->pluck('id')->all(),
            'inherited' => $role->parent ? $role->parent->effectivePermissionSlugs() : [],
        ]);
    }

    /** Every permission the form may offer, grouped by module. */
    private function assignablePermissions()
    {
        return Permission::whereNotIn('slug', self::NEVER_ASSIGNABLE)
            ->orderBy('module')->orderBy('name')->get()->groupBy('module');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:roles,id', 'different:'.$role->id],
            'permissions' => ['array'],
            'permissions.*' => [
                'integer',
                Rule::exists('permissions', 'id')->whereNotIn('slug', self::NEVER_ASSIGNABLE),
            ],
        ]);

        $old = $role->getAttributes();
        $role->update($data);
        $this->rbac->syncRolePermissions($role, $data['permissions'] ?? []);
        $this->audit->log('role_updated', $role, $old, $role->getChanges());

        return redirect()->route('roles.index')->with('status', 'Role updated.');
    }

    /**
     * Kept, and kept refusing.
     *
     * All five roles are `is_system`, so in practice this always refuses — but
     * the check is what makes that true rather than the seeding, and the route
     * is what a replayed form would hit.
     */
    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'The five LGU roles cannot be deleted.');
        }
        $this->audit->log('role_deleted', $role, $role->getAttributes(), []);
        $role->delete();
        $this->rbac->bumpVersion();

        return redirect()->route('roles.index')->with('status', 'Role deleted.');
    }
}
