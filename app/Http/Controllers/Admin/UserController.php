<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Archive;
use App\Models\Department;
use App\Models\EmployeeProfile;
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
use Illuminate\Validation\Rule;
use Illuminate\Support\Arr;
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
        // "Who are the department heads?" was unanswerable: roles were shown
        // in the list but there was no way to ask by one.
        if ($role = $request->string('role')->toString()) {
            $query->whereHas('roles', fn ($q) => $q->where('slug', $role));
        }
        // Archived was a checkbox that could only be on or off, so there was
        // no way to see current and archived accounts together.
        match ($request->string('show')->toString()) {
            'archived' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => null,
        };

        $users = $query->orderBy('name')->paginate(config('lists.per_page'))->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::assignable()->pluck('name', 'slug'),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User,
            'roles' => Role::assignable()->get(),
            'departments' => Department::orderBy('name')->get(),
            'positions' => Position::orderBy('title')->get(),
            'assignedRoles' => [],
            // So that adding an account does not begin with opening the
            // employee list and reading the last number off the bottom.
            'suggestedEmployeeNo' => EmployeeProfile::nextEmployeeNo(),
        ]);
    }

    /** Civil status and employment status are closed lists, not free text. */
    public const CIVIL_STATUSES = ['single', 'married', 'widowed', 'separated', 'annulled'];

    public const EMPLOYMENT_STATUSES = ['permanent', 'casual', 'contractual', 'coterminous'];

    /**
     * Every field the CSC Form 6 header is built from, and the shape each one
     * has to be in.
     *
     * These were nearly all `nullable` before, so an account could be created
     * with no department, no position and no hire date — and the leave form it
     * later produces prints those straight onto the sheet. A blank there is not
     * caught until somebody is holding the paper.
     */
    private function profileRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'in:male,female'],
            'civil_status' => ['required', 'in:'.implode(',', self::CIVIL_STATUSES)],
            // Nobody in service was born this century's last few years, and
            // nobody was hired before they were born.
            'birth_date' => ['required', 'date', 'before:-15 years'],
            'contact_no' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'address' => ['required', 'string', 'max:255'],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'employment_status' => ['required', 'in:'.implode(',', self::EMPLOYMENT_STATUSES)],
            'salary' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'date_hired' => ['required', 'date', 'before_or_equal:today', 'after:birth_date'],
        ];
    }

    /** Only the five roles the form offers are accepted from a submission. */
    private function roleRules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')->where(
                fn ($q) => $q->whereIn('slug', Role::ASSIGNABLE)
            )],
        ];
    }

    private static function messages(): array
    {
        return [
            'roles.required' => 'Choose at least one role for this account.',
            'roles.*.exists' => 'That role cannot be assigned from this form.',
            'contact_no.regex' => 'Use digits, spaces, brackets, + or - only.',
            'birth_date.before' => 'The birth date does not look like an employee of working age.',
            'date_hired.after' => 'The hire date cannot be before the birth date.',
            'date_hired.before_or_equal' => 'The hire date cannot be in the future.',
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'alpha_dash', 'min:3', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ], $this->roleRules(), $this->profileRules()), self::messages());

        // The employee number is the system's to issue, not the form's to
        // send. The field is shown read-only, but readonly is a hint to the
        // browser and nothing more -- so the number is taken here and whatever
        // arrived in the request is discarded.
        //
        // That is what makes it permanent. A number is assigned once, never
        // edited, and never reissued: archiving keeps the employee_profiles
        // row, so a resigned or dismissed employee's number stays counted and
        // cannot come back to somebody else.
        $data['employee_no'] = EmployeeProfile::nextEmployeeNo();

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

        // Only profile columns, rather than everything that came off the form.
        $user->employeeProfile()->create(
            Arr::only($data, array_merge(['employee_no'], array_keys($this->profileRules())))
        );
        $this->rbac->syncUserRoles($user, $this->keepUnassignable($user, $data['roles']));
        $this->audit->log('user_created', $user, [], ['email' => $user->email, 'temp_password' => '[GENERATED]']);

        return redirect()->route('users.index')
            ->with('status', "User created. Temporary password: {$tempPassword} (share securely; the user must change it on first login).");
    }

    public function edit(User $user): View
    {
        $user->load('employeeProfile', 'roles', 'directPermissions');

        return view('admin.users.form', [
            'user' => $user,
            'roles' => Role::assignable()->get(),
            'departments' => Department::orderBy('name')->get(),
            'positions' => Position::orderBy('title')->get(),
            'assignedRoles' => $user->roles->pluck('id')->all(),
            // Only the count: the overrides themselves live on their own page,
            // and the edit form says how many are in effect rather than
            // carrying thirty-five rows of them.
            'overrides' => $user->directPermissions->count(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // The same rules as create, minus the fields that are set once. Gender,
        // civil status, birth date and hire date used to be missing here
        // entirely, so the form collected them and the update silently dropped
        // them — they could never be corrected after the account was made.
        // Roles belong to this form and always did on screen -- but update()
        // never read them, so ticking Department Head and pressing Save
        // reported "User updated." and changed nothing. The only thing that
        // saved a role was the other button at the bottom of the same page.
        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ], $this->roleRules(), $this->profileRules()), self::messages());

        // Editing your own roles is a way to grant yourself authority. The
        // guard came with the roles from the form that used to own them.
        $ownAccount = $request->user()->id === $user->id;

        $old = $user->getAttributes();
        $user->update(['name' => $data['name'], 'email' => $data['email']]);
        $user->employeeProfile?->update(Arr::only($data, array_keys($this->profileRules())));

        if (! $ownAccount) {
            $this->rbac->syncUserRoles($user, $this->keepUnassignable($user, $data['roles']));
        }
        $this->audit->log('user_updated', $user, $old, $user->getChanges());

        return redirect()->route('users.index')->with('status', $ownAccount
            ? 'User updated. Your own roles were left alone.'
            : 'User updated.');
    }

    /**
     * The per-permission overrides for one account.
     *
     * Its own page, beside /edit and /history, rather than a second form
     * stacked under the edit form. Two forms on one page both submitted the
     * roles, so whichever was pressed second decided them -- and the edit
     * form's own roles were being dropped on the floor besides.
     */
    public function access(User $user): View
    {
        $user->load('roles', 'directPermissions');

        return view('admin.users.access', [
            'user' => $user,
            'permissions' => Permission::orderBy('module')->orderBy('name')->get()->groupBy('module'),
            'directAllow' => $user->directPermissions->where('pivot.type', 'allow')->pluck('id')->all(),
            'directDeny' => $user->directPermissions->where('pivot.type', 'deny')->pluck('id')->all(),
        ]);
    }

    public function updateAccess(Request $request, User $user): RedirectResponse
    {
        // Users cannot edit their own access (privilege-escalation guard).
        if ($request->user()->id === $user->id) {
            return back()->with('error', 'You cannot change your own access.');
        }

        // Allow and deny are two checkboxes for a value with three states, so
        // both can be ticked -- and the save used to apply deny and drop the
        // allow without saying so. It is refused now, and says which one.
        $data = $request->validate([
            'allow' => ['array'],
            'allow.*' => [
                'exists:permissions,id',
                Rule::notIn($request->input('deny', [])),
            ],
            'deny' => ['array'],
            'deny.*' => ['exists:permissions,id'],
        ], [
            'allow.*.not_in' => 'A permission cannot be both allowed and denied. '
                .'Leave both unticked to inherit it from the role.',
        ]);

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

        return redirect()->route('users.access', $user)->with('status', 'Access updated.');
    }

    /**
     * Keep any role the account already holds that this form cannot offer.
     *
     * Without this, opening an existing Super Admin in the editor and pressing
     * Save would quietly demote them: the picker cannot show the role, so it is
     * absent from the submission, and a plain sync would remove it.
     *
     * @param  array<int>  $submitted
     * @return array<int>
     */
    private function keepUnassignable(User $user, array $submitted): array
    {
        $hidden = $user->roles()
            ->whereNotIn('slug', Role::ASSIGNABLE)
            ->pluck('roles.id')
            ->all();

        return array_values(array_unique(array_merge($submitted, $hidden)));
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

    public function history(User $user): View
    {
        $logins = $user->failedLogins()->latest('occurred_at')->limit(50)->get();
        $audits = \App\Models\AuditLog::where('user_id', $user->id)->latest()->limit(50)->get();
        $activity = \App\Models\ActivityLog::where('user_id', $user->id)->latest()->limit(50)->get();

        return view('admin.users.history', compact('user', 'logins', 'audits', 'activity'));
    }
}
