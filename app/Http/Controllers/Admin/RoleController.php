<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Rbac\RbacService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleController extends Controller
{
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

    public function create(): View
    {
        return view('admin.roles.form', [
            'role' => new Role,
            'roles' => Role::orderBy('name')->get(),
            'permissions' => Permission::orderBy('module')->orderBy('name')->get()->groupBy('module'),
            'assigned' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'alpha_dash', 'max:100', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:roles,id'],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);

        $role = Role::create($data);
        $this->rbac->syncRolePermissions($role, $data['permissions'] ?? []);
        $this->audit->log('role_created', $role, [], $role->getAttributes());

        return redirect()->route('roles.index')->with('status', 'Role created.');
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'role' => $role,
            'roles' => Role::where('id', '!=', $role->id)->orderBy('name')->get(),
            'permissions' => Permission::orderBy('module')->orderBy('name')->get()->groupBy('module'),
            'assigned' => $role->permissions->pluck('id')->all(),
            'inherited' => $role->parent ? $role->parent->effectivePermissionSlugs() : [],
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:roles,id', 'different:'.$role->id],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);

        $old = $role->getAttributes();
        $role->update($data);
        $this->rbac->syncRolePermissions($role, $data['permissions'] ?? []);
        $this->audit->log('role_updated', $role, $old, $role->getChanges());

        return redirect()->route('roles.index')->with('status', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'System roles cannot be deleted.');
        }
        $this->audit->log('role_deleted', $role, $role->getAttributes(), []);
        $role->delete();
        $this->rbac->bumpVersion();

        return redirect()->route('roles.index')->with('status', 'Role deleted.');
    }
}
