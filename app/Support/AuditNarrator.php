<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\AuthorizedDevice;
use App\Models\BlockedIp;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\IntrusionLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Puts the audit trail into words.
 *
 * The log stores what a programmer needs -- column names, raw values, a full
 * before-image of the row -- and the page used to print that verbatim, as
 * pretty-printed JSON. The administrator reading it is an HR officer, not a
 * developer: "must_change_password": 0 and "blocked_until": null are not
 * things they should have to decode, and twenty unchanged fields buried the
 * one that actually moved.
 *
 * Nothing about what is recorded changes. This only decides how it reads:
 *
 *   - only the fields that actually changed, because a save writes the whole
 *     row into old_values and just the differences into new_values;
 *   - column names as labels ("Must change password at next sign-in");
 *   - values as words -- Yes/No rather than 1/0, "not set" rather than null,
 *     "Aug 30, 2026 6:56 AM" rather than a timestamp;
 *   - an id resolved to the name it points at, so "Department: 3" reads
 *     "Office: Municipal Engineering Office".
 *
 * Redacted values stay redacted. A password is reported as changed and never
 * shown, here or anywhere else.
 */
final class AuditNarrator
{
    /**
     * Bookkeeping the reader has no use for. `updated_at` moves on every
     * single save, so left in, every entry would claim two changes.
     */
    private const NOISE = ['created_at', 'updated_at', 'remember_token', 'uuid'];

    /** Column name => how it is written on the page. */
    private const LABELS = [
        'ip' => 'IP address',
        'ip_address' => 'IP address',
        'last_login_ip' => 'Last signed in from',
        'last_login_at' => 'Last signed in',
        'employee_no' => 'Employee number',
        'reference_no' => 'Reference number',
        'contact_no' => 'Contact number',
        'birth_date' => 'Date of birth',
        'date_hired' => 'Date hired',
        'date_filed' => 'Date filed',
        'employment_status' => 'Employment status',
        'signature_path' => 'Signature file',
        'is_solo_parent' => 'Solo parent',
        'department_id' => 'Office',
        'position_id' => 'Position',
        'leave_type_id' => 'Leave type',
        'user_id' => 'Employee',
        'approver_id' => 'Approver',
        'blocked_by' => 'Blocked by',
        'registered_by' => 'Registered by',
        'head_user_id' => 'Head of office',
        'must_change_password' => 'Must set a new password at next sign-in',
        'password_changed_at' => 'Password last changed',
        'failed_attempts' => 'Failed sign-in attempts',
        'blocked_until' => 'Blocked until',
        'blocked_reason' => 'Reason for blocking',
        'email_verified_at' => 'Email confirmed',
        'deleted_at' => 'Archived on',
        'archived_at' => 'Archived on',
        'temp_password' => 'Temporary password',
        'expires_at' => 'Expires',
        'events' => 'Attempts recorded',
        'hours' => 'Blocked for',
        'id' => 'Alerts',
        'how' => 'Lifted',
        'by' => 'Decided by',
        'working_days' => 'Working days',
        'salary_grade' => 'Salary grade',
        'is_system' => 'Built-in',
        'permissions' => 'Permissions',
        'roles' => 'Roles',
        'grant' => 'Allowed',
        'deny' => 'Denied',
    ];

    /** An id column and the record it points at. */
    private const LOOKUPS = [
        'department_id' => Department::class,
        'position_id' => Position::class,
        'leave_type_id' => LeaveType::class,
        'user_id' => User::class,
        'approver_id' => User::class,
        'blocked_by' => User::class,
        'registered_by' => User::class,
        'head_user_id' => User::class,
        'role_id' => Role::class,
    ];

    /** Model => [what to call it, which column names it]. */
    private const RECORDS = [
        User::class => ['User account', 'name'],
        Department::class => ['Office', 'name'],
        Position::class => ['Position', 'title'],
        LeaveType::class => ['Leave type', 'name'],
        Role::class => ['Role', 'name'],
        LeaveRequest::class => ['Leave application', 'reference_no'],
        BlockedIp::class => ['Blocked IP', 'ip'],
        AuthorizedDevice::class => ['Device', 'hostname'],
        SystemSetting::class => ['Setting', 'key'],
        EmployeeProfile::class => ['Employee record', 'employee_no'],
        Holiday::class => ['Holiday', 'name'],
        IntrusionLog::class => ['Intrusion alert', 'type'],
    ];

    /**
     * What happened, in plain words. Only the entries whose slug does not
     * already read as a sentence are listed; the rest are unpacked by rule.
     */
    private const ACTIONS = [
        // Written by the Auditable trait on any model it is attached to, so
        // the wording has to work for a holiday, a leave type or an employee
        // record alike. The target column says which.
        'created' => 'Record added',
        'updated' => 'Record changed',
        'deleted' => 'Record removed',
        'restored' => 'Record restored',
        'login' => 'Signed in',
        'logout' => 'Signed out',
        'otp_verified' => 'One-time code accepted',
        'otp_failed' => 'One-time code rejected',
        'password_reset_by_admin' => 'Password reset by an administrator',
        'user_status_toggled' => 'Account switched on or off',
        'user_blocked_manual' => 'Account blocked by an administrator',
        'account_blocked' => 'Account blocked by the system',
        'account_unblocked' => 'Account block lifted',
        'user_access_changed' => 'Permissions changed',
        'ip_auto_blocked' => 'IP blocked by the system',
        'ip_blocked_manual' => 'IP blocked by an administrator',
        'ip_blocked_from_evidence' => 'IP blocked from the intrusion list',
        'ip_blocked_again' => 'IP blocked again',
        'ip_unblocked' => 'IP block lifted',
        'intrusions_reviewed' => 'Intrusion alerts marked as reviewed',
        'intrusion_false_positives_purged' => 'False alarms cleared',
        'leave_submitted' => 'Leave application filed',
        'leave_resubmitted' => 'Leave application filed again',
        // Kept for history: the department step became a notification, but
        // rows recorded before that must still read as something.
        'leave_recommended' => 'Leave recommended by the head of office',
        'leave_approved' => 'Leave approved',
        'leave_disapproved' => 'Leave disapproved',
        'leave_cancelled' => 'Leave application cancelled',
        'settings_updated' => 'System settings changed',
    ];

    /** Resolved names, so ten rows pointing at one office ask once. */
    private static array $names = [];

    // ------------------------------------------------------------ the action

    public static function action(string $action): string
    {
        if (isset(self::ACTIONS[$action])) {
            return self::ACTIONS[$action];
        }

        // user_archived -> "User archived", device_registered -> "Device
        // registered".
        return self::sentence($action);
    }

    /**
     * snake_case to a sentence. The abbreviations are the only thing ucfirst
     * gets wrong on its own -- "Otp required", "Ip address".
     */
    private static function sentence(string $key): string
    {
        $words = ucfirst(str_replace('_', ' ', $key));

        return preg_replace_callback('/\b(ip|otp|csc|hr|lgu|url|id|mac)\b/i',
            fn ($m) => strtoupper($m[1]), $words) ?? $words;
    }

    /**
     * The role the actor held at the time, as it is written everywhere else.
     * The column stores slugs, comma-joined, because that is what the trail
     * has to compare against years later; "system-admin, hr" is not how the
     * role is named anywhere the reader has seen it.
     */
    public static function role(?string $snapshot): ?string
    {
        if ($snapshot === null || trim($snapshot) === '') {
            return null;
        }

        return collect(explode(',', $snapshot))
            ->map(fn (string $slug) => self::sentence(str_replace('-', '_', trim($slug))))
            ->implode(', ');
    }

    // ------------------------------------------------------------ the target

    /** "User account — Noly J. Macarubbo", rather than "User 1". */
    public static function target(?string $type, int|string|null $id): ?string
    {
        if ($type === null) {
            return null;
        }

        [$label] = self::RECORDS[$type] ?? [Str::headline(class_basename($type))];
        $name = $id === null ? null : self::nameOf($type, $id);

        return $name === null ? $label.' #'.$id : $label.' — '.$name;
    }

    // ----------------------------------------------------------- the changes

    /**
     * The fields that moved, as [label, from, to, note]. `from` is null when
     * there was no previous value to compare against; `note` is null where
     * the field speaks for itself.
     *
     * @return list<array{label: string, from: ?string, to: string, note: ?string}>
     */
    public static function changes(AuditLog $log): array
    {
        $new = $log->new_values ?? [];
        $old = $log->old_values ?? [];

        // A save records the whole row as it was and only the differences as
        // it is, so the differences are the list. When there are none the
        // entry is a record of what something was before it went -- a deleted
        // role, say -- and then the before-image is all there is to show.
        $reading = $new !== [] ? $new : $old;
        $comparing = $new !== [];

        $out = [];
        foreach ($reading as $key => $value) {
            if (in_array($key, self::NOISE, true)) {
                continue;
            }
            // An id is the row's own, and the target column already names it.
            // "all" is not an id -- it is the scope of a sweep, worth reading.
            if ($key === 'id' && is_numeric($value)) {
                continue;
            }

            // Settings are logged as key => [old, new] already.
            if (is_array($value) && array_keys($value) === ['old', 'new']) {
                $out[] = self::line($key, $value['old'], $value['new']);

                continue;
            }

            $before = $comparing && array_key_exists($key, $old) ? $old[$key] : null;

            // A redacted pair is not an unchanged pair. Both sides read
            // "[REDACTED]" because neither is stored, and dropping it as
            // identical would hide a password change completely -- the one
            // change a security log most needs to carry. The key is only
            // written when it actually moved, so its presence is the fact.
            if ($value !== '[REDACTED]'
                && $comparing && array_key_exists($key, $old) && self::same($before, $value)) {
                continue;
            }

            $out[] = self::line($key, $comparing ? $before : null, $value);
        }

        return $out;
    }

    /** @return array{label: string, from: ?string, to: string, note: ?string} */
    private static function line(string $key, mixed $from, mixed $to): array
    {
        // The value itself is never stored, so there is nothing to show and
        // nothing to compare -- only that it changed.
        if ($to === '[REDACTED]') {
            return [
                'label' => self::label($key), 'from' => null,
                'to' => 'changed (never shown)', 'note' => self::note($key, $to),
            ];
        }

        return [
            'label' => self::label($key),
            'from' => $from === null ? null : self::value($key, $from),
            'to' => self::value($key, $to),
            'note' => self::note($key, $to),
        ];
    }

    // --------------------------------------------------------- what it means

    /**
     * What the entry means, in one sentence.
     *
     * A field and its new value say what was written. They do not say what it
     * did -- and that is the question somebody opens the audit trail with.
     * "Status: active -> blocked" is a fact about a column; "they cannot sign
     * in until an administrator lifts it" is the thing the reader came for.
     *
     * These are descriptions of behaviour that is actually in the system, not
     * general advice: each one is a rule implemented somewhere else in this
     * codebase, named in the comment beside it. If a rule changes, the
     * sentence here is wrong and has to change with it.
     */
    public static function meaning(AuditLog $log): ?string
    {
        $new = $log->new_values ?? [];

        return match ($log->action) {
            // UserController::toggleActive. Deactivating is for somebody who
            // is away; blocking is for an account behaving oddly. Both stop a
            // sign-in, so which one this was is worth stating.
            'user_status_toggled' => ($new['status'] ?? null) === 'inactive'
                ? 'The account was switched off. Nobody can sign in as them until it is switched back on — this is what is used while somebody is away.'
                : 'The account was switched back on. They can sign in again.',

            // LoginSecurityService::blockAccount, reached from recordFailure.
            'account_blocked' => 'The system blocked the account itself after too many wrong passwords in a row. It unblocks on its own when the block expires.',
            'user_blocked_manual' => 'An administrator blocked the account, which is what is used when something is wrong with its activity. They cannot sign in until it is lifted.',
            'account_unblocked' => 'The block was lifted. They can sign in again, and the failed-attempt count starts from zero.',

            // UserController::archive -- soft delete. Nothing is destroyed.
            'user_archived' => 'The account was archived, which is what happens when somebody leaves the LGU. It drops out of the list, but nothing about it is deleted: their leave record, their filed CSC Form 6 copies and their employee number all stay, and the account can be restored.',
            'user_restored' => 'The archived account was brought back. It appears in the list again, with everything it had.',

            // UserController::resetPassword + ForcePasswordChange middleware.
            'password_reset_by_admin' => 'An administrator put the account back to the first-time password. The employee is held on the change-password screen at their next sign-in until they set their own.',
            'password_changed' => 'The employee set a new password themselves. The password is never stored in a form anyone can read, here or in the database.',
            'password_reset' => 'The password was set again through the reset link sent to their email address.',

            'user_access_changed' => 'What this person is allowed to open was changed by hand, on top of what their role already gives them.',
            'user_created' => 'A new account was opened, on the first-time password. The employee number was issued by the system and cannot be typed in or reused.',
            // Worth saying even though it is obvious, because the trail keeps
            // the whole row and the reader cannot otherwise tell that the two
            // lines below are all that moved.
            'user_updated' => 'An administrator changed this account. Only the fields below moved; everything else on it is as it was.',
            'updated' => 'This record was changed. Only the fields below moved.',
            'created' => 'This record was added, with the values below.',
            'deleted' => 'This record was removed. What it held is listed below, because the trail is now the only copy of it.',
            'restored' => 'A removed record was brought back.',

            // RoleController. A role is held by several people at once.
            'role_updated' => 'What a role is allowed to do was changed. This applies to everybody holding that role, not to one person.',

            // OtpService mails the code; OtpController checks it.
            'otp_failed' => 'The one-time code entered after the password was wrong. On its own this is usually a mistyped code.',
            'otp_verified' => 'The one-time code sent to their email address was correct, so the sign-in completed.',

            // IntrusionDetectionService::block -- automatic, on repeated events.
            'ip_auto_blocked' => 'The system blocked this address on its own, after repeated attempts from it in a short time. No administrator decided this.',
            'ip_blocked_manual' => 'An administrator blocked this address by hand. Nothing from it reaches the system until the block is lifted or expires.',
            'ip_blocked_from_evidence' => 'An administrator blocked this address from the intrusion list, on the attempts recorded against it.',
            'ip_blocked_again' => 'A block that had been lifted was put back, as a fresh decision by the administrator named above rather than by the system.',
            'ip_unblocked' => 'The block was lifted, so this address can reach the system again. This is what is used when the block caught somebody who should not have been caught.',
            'intrusions_reviewed' => 'An administrator marked intrusion alerts as seen. The alerts themselves stay in the log; only the "needs attention" flag was cleared.',
            'intrusion_false_positives_purged' => 'Entries recorded as attacks that were not were removed from the intrusion log, so the counts reflect real attempts.',

            // ApprovalWorkflowService -- CSC Form No. 6, parts 7.B and 7.C.
            'leave_submitted' => 'A leave application was filed. The employee\'s department head was notified, and it now waits on HR to validate and decide.',
            'leave_resubmitted' => 'A returned application was corrected and filed again. It goes back to HR for a decision.',
            'leave_recommended' => 'The head of office recommended the application — part 7.B of the CSC form. Recorded before the department step became a notification; heads no longer act on applications.',
            'leave_approved' => 'The application was approved. The days are deducted from the employee\'s credits and the CSC form is complete.',
            'leave_disapproved' => 'The application was disapproved. No credits are deducted, and the reason is recorded on the form.',
            'leave_cancelled' => 'The employee withdrew the application before it was decided. Nothing is deducted.',

            'settings_updated' => 'A system-wide setting was changed. It applies to everyone from now on.',
            'device_registered' => 'A computer was added to the list allowed to reach the system on the LGU network.',
            'device_deactivated' => 'A registered computer was switched off. The system stops accepting it.',

            'login' => null,   // The user, time and address already say it.
            'logout' => null,

            default => null,
        };
    }

    /**
     * What one changed field means. Null where the label and value already
     * say everything -- a name is a name, and explaining it is noise.
     */
    private static function note(string $key, mixed $to): ?string
    {
        // `events` and the like arrive as arrays, and a note that reads a
        // value has to survive one.
        $plain = is_scalar($to) ? (string) $to : null;
        $yes = $to === true || $plain === '1';

        return match (Str::afterLast($key, '.')) {
            // LoginSecurityService::recordFailure blocks at this count.
            'failed_attempts' => $plain === '0'
                ? 'The count was cleared, so the account is no longer near locking itself.'
                : 'Wrong passwords in a row. At '.SystemSetting::get('auth.lockout_attempts', 3)
                    .' the account blocks itself.',

            // ForcePasswordChange middleware.
            'must_change_password' => $yes
                ? 'They are held on the change-password screen at their next sign-in until they set one.'
                : 'They set a password, so the hold is gone.',

            // UnblockExpired, on the schedule.
            'blocked_until', 'expires_at' => 'It lifts itself at this time; nobody has to remember to.',
            'blocked_reason' => 'Kept with the account so whoever looks next can see why.',

            'password' => 'The password is never stored in a readable form, so the trail can record that it changed and nothing more.',
            'temp_password' => 'The same word for every new account, and replaced by the employee before they can go any further.',

            // OtpService mails the code to this address.
            'email' => 'Sign-in codes and notices go to this address from now on.',

            // ApprovalWorkflowService notifies departments.head_user_id, and
            // box 7.B of the printed form carries that name.
            'department_id' => 'When they file leave, the head of this office is the one notified, and the one named on their CSC form.',
            'head_user_id' => 'This person is now notified whenever somebody in this office files leave, and is named in part 7.B of their form. They do not approve it — HR does.',

            // EmployeeProfile::nextEmployeeNo.
            'employee_no' => 'Issued once and never handed out again, even after the account is archived.',

            'status' => match ($plain) {
                'blocked' => 'They cannot sign in until the block is lifted or expires.',
                'inactive' => 'They cannot sign in while the account is switched off.',
                'active' => 'They can sign in.',
                default => null,
            },
            'deleted_at' => $to === null
                ? 'The account is no longer archived.'
                : 'Archived. It leaves the list; nothing about it is deleted.',

            'roles' => 'Changes which pages and actions they can reach.',
            'events' => 'How many attempts were recorded before this was raised.',
            'hours' => 'How long the block lasts before it lifts itself.',

            default => null,
        };
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return is_scalar($a) && is_scalar($b) ? (string) $a === (string) $b : $a === $b;
    }

    // ------------------------------------------------------------- wording

    private static function label(string $key): string
    {
        if (isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }

        // security.rate_limit_per_minute -> "Rate limit per minute"
        $key = str_contains($key, '.') ? Str::afterLast($key, '.') : $key;

        return self::sentence(preg_replace('/_id$/', '', $key) ?: $key);
    }

    private static function value(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'not set';
        }
        if ($value === '[REDACTED]') {
            return 'hidden';
        }
        if ($value === '[GENERATED]') {
            return 'generated by the system';
        }
        if ($value === '[STANDARD]') {
            return 'the first-time password';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return self::listOf($value);
        }

        if (self::isYesNo($key)) {
            return ((string) $value === '1' || $value === true) ? 'Yes' : 'No';
        }
        if (isset(self::LOOKUPS[$key]) && is_numeric($value)) {
            return self::nameOf(self::LOOKUPS[$key], $value) ?? ('#'.$value);
        }
        if ($key === 'hours') {
            return $value.' '.Str::plural('hour', (int) $value);
        }

        $text = (string) $value;

        if (self::looksLikeATime($text)) {
            return self::time($key, $text);
        }

        return Str::limit($text, 90);
    }

    /**
     * 0 and 1 are stored; Yes and No are read. A system setting carries its
     * group in the key -- security.otp_required -- so the group comes off
     * before the name is judged.
     */
    private static function isYesNo(string $key): bool
    {
        $key = Str::afterLast($key, '.');

        return in_array($key, ['active', 'is_system', 'deductible', 'handled',
            'must_change_password', 'deadline_is_hard', 'enabled'], true)
            || str_starts_with($key, 'is_')
            || str_ends_with($key, '_required')
            || str_ends_with($key, '_enabled');
    }

    private static function listOf(array $value): string
    {
        $flat = array_filter($value, 'is_scalar');

        if ($flat !== $value || $value === []) {
            // Nested -- a set of recorded attempts, not something to spell out.
            $n = count($value);

            return $n.' '.Str::plural('entry', $n);
        }

        return count($flat) > 6
            ? implode(', ', array_slice($flat, 0, 6)).' and '.(count($flat) - 6).' more'
            : implode(', ', $flat);
    }

    private static function looksLikeATime(string $text): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', $text);
    }

    private static function time(string $key, string $text): string
    {
        try {
            $when = Carbon::parse($text);
        } catch (\Throwable) {
            return $text;
        }

        // A date with no time of day is a date; printing "12:00 AM" onto it
        // invents a precision that was never recorded. A `date` cast stores
        // midnight into a datetime column, so the column's shape cannot
        // settle it -- a birth date arrives as "1980-05-09 00:00:00". The
        // name of the field can: date_hired and birth_date are days.
        $name = Str::afterLast($key, '.');
        $isADay = strlen(trim($text)) <= 10
            || str_ends_with($name, '_date')
            || str_starts_with($name, 'date_');

        return $isADay ? $when->format('M d, Y') : $when->format('M d, Y g:i A');
    }

    // ------------------------------------------------------------- lookups

    /** @param class-string<Model> $type */
    private static function nameOf(string $type, int|string $id): ?string
    {
        $cache = $type.':'.$id;
        if (array_key_exists($cache, self::$names)) {
            return self::$names[$cache];
        }

        [, $column] = self::RECORDS[$type] ?? [null, 'name'];

        $name = null;
        if (class_exists($type) && is_subclass_of($type, Model::class)) {
            $query = $type::query();
            // The point of archiving is that the record is still readable. A
            // log entry about an archived employee must still say their name.
            if (in_array(SoftDeletes::class, class_uses_recursive($type), true)) {
                $query->withTrashed();
            }
            $name = $query->find($id)?->getAttribute($column);
        }

        return self::$names[$cache] = $name === null ? null : (string) $name;
    }

    /** Between requests nothing is shared; this only helps within one page. */
    public static function forget(): void
    {
        self::$names = [];
    }
}
