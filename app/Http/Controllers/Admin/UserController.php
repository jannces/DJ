<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Archive;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\Auth\LoginSecurityService;
use App\Services\Rbac\RbacService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(
        private readonly RbacService $rbac,
        private readonly LoginSecurityService $loginSecurity,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $query = User::with(['roles', 'employeeProfile.department']);

        if ($search = $request->string('q')->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%"));
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($request->boolean('archived')) {
            $query->onlyTrashed();
        }

        $users = $query->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User,
            'roles' => Role::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
            'positions' => Position::orderBy('title')->get(),
            'assignedRoles' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'alpha_dash', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'roles' => ['array'],
            'roles.*' => ['exists:roles,id'],
            'employee_no' => ['required', 'string', 'max:50', 'unique:employee_profiles,employee_no'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', 'in:male,female'],
            'civil_status' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date'],
            'contact_no' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'salary' => ['required', 'numeric', 'min:0'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'employment_status' => ['required', 'string', 'max:30'],
            'date_hired' => ['nullable', 'date'],
        ]);

        $tempPassword = Str::password(14);
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => Hash::make($tempPassword),
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => true,
            'email_verified_at' => now(),
        ]);

        $user->employeeProfile()->create($data + ['user_id' => $user->id]);
        $this->rbac->syncUserRoles($user, $data['roles'] ?? []);
        $this->audit->log('user_created', $user, [], ['email' => $user->email, 'temp_password' => '[GENERATED]']);

        return redirect()->route('users.index')
            ->with('status', "User created. Temporary password: {$tempPassword} (share securely; the user must change it on first login).");
    }

    public function edit(User $user): View
    {
        $user->load('employeeProfile', 'roles', 'directPermissions');

        return view('admin.users.form', [
            'user' => $user,
            'roles' => Role::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
            'positions' => Position::orderBy('title')->get(),
            'permissions' => Permission::orderBy('module')->get()->groupBy('module'),
            'assignedRoles' => $user->roles->pluck('id')->all(),
            'directAllow' => $user->directPermissions->where('pivot.type', 'allow')->pluck('id')->all(),
            'directDeny' => $user->directPermissions->where('pivot.type', 'deny')->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'salary' => ['required', 'numeric', 'min:0'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'employment_status' => ['required', 'string', 'max:30'],
            'contact_no' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $old = $user->getAttributes();
        $user->update(['name' => $data['name'], 'email' => $data['email']]);
        $user->employeeProfile?->update($data);
        $this->audit->log('user_updated', $user, $old, $user->getChanges());

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function assignRoles(Request $request, User $user): RedirectResponse
    {
        // Users cannot edit their own access (privilege-escalation guard).
        if ($request->user()->id === $user->id) {
            return back()->with('error', 'You cannot change your own roles or permissions.');
        }

        $data = $request->validate([
            'roles' => ['array'], 'roles.*' => ['exists:roles,id'],
            'allow' => ['array'], 'allow.*' => ['exists:permissions,id'],
            'deny' => ['array'], 'deny.*' => ['exists:permissions,id'],
        ]);

        $this->rbac->syncUserRoles($user, $data['roles'] ?? []);

        $pivot = [];
        foreach ($data['allow'] ?? [] as $id) {
            $pivot[$id] = ['type' => 'allow'];
        }
        foreach ($data['deny'] ?? [] as $id) {
            $pivot[$id] = ['type' => 'deny'];
        }
        $user->directPermissions()->sync($pivot);
        $this->rbac->bumpVersion();
        $this->audit->log('user_access_changed', $user, [], $data);

        return back()->with('status', 'Access updated.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $temp = Str::password(14);
        $user->update([
            'password' => Hash::make($temp),
            'must_change_password' => true,
        ]);
        $this->audit->log('password_reset_by_admin', $user);

        return back()->with('status', "New temporary password: {$temp} (must be changed on next login).");
    }

    public function block(Request $request, User $user): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $old = ['status' => $user->status];
        $user->update([
            'status' => User::STATUS_BLOCKED,
            'blocked_until' => null, // manual block = indefinite until unblocked
            'blocked_reason' => $request->string('reason'),
        ]);
        $this->audit->log('user_blocked_manual', $user, $old, ['reason' => $request->string('reason')->toString()]);

        return back()->with('status', 'User blocked.');
    }

    public function unblock(User $user): RedirectResponse
    {
        $this->loginSecurity->unblockAccount($user, request()->user(), 'manual');

        return back()->with('status', 'User unblocked.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        $new = $user->status === User::STATUS_INACTIVE ? User::STATUS_ACTIVE : User::STATUS_INACTIVE;
        $user->update(['status' => $new]);
        $this->audit->log('user_status_toggled', $user, [], ['status' => $new]);

        return back()->with('status', "User is now {$new}.");
    }

    public function archive(User $user): RedirectResponse
    {
        Archive::create([
            'archivable_type' => User::class,
            'archivable_id' => $user->id,
            'snapshot' => $user->toArray(),
            'archived_by' => request()->user()->id,
        ]);
        $user->delete(); // soft delete
        $this->audit->log('user_archived', $user);

        return back()->with('status', 'User archived.');
    }

    public function restore(int $id): RedirectResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();
        Archive::where('archivable_type', User::class)->where('archivable_id', $id)
            ->whereNull('restored_at')->update(['restored_at' => now()]);
        $this->audit->log('user_restored', $user);

        return back()->with('status', 'User restored.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        if ($user->id === request()->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        $this->audit->log('user_deleted_permanent', null, ['user' => $user->only(['id', 'email'])], []);
        $user->forceDelete();

        return back()->with('status', 'User permanently deleted.');
    }

    public function history(User $user): View
    {
        $logins = $user->failedLogins()->latest('occurred_at')->limit(50)->get();
        $audits = \App\Models\AuditLog::where('user_id', $user->id)->latest()->limit(50)->get();
        $activity = \App\Models\ActivityLog::where('user_id', $user->id)->latest()->limit(50)->get();

        return view('admin.users.history', compact('user', 'logins', 'audits', 'activity'));
    }
}
