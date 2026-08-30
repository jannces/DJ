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
     * The fields that moved, as [label, from, to]. `from` is null when there
     * was no previous value to compare against.
     *
     * @return list<array{label: string, from: ?string, to: string}>
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

    /** @return array{label: string, from: ?string, to: string} */
    private static function line(string $key, mixed $from, mixed $to): array
    {
        // The value itself is never stored, so there is nothing to show and
        // nothing to compare -- only that it changed.
        if ($to === '[REDACTED]') {
            return ['label' => self::label($key), 'from' => null, 'to' => 'changed (never shown)'];
        }

        return [
            'label' => self::label($key),
            'from' => $from === null ? null : self::value($key, $from),
            'to' => self::value($key, $to),
        ];
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
            return self::time($text);
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

    private static function time(string $text): string
    {
        try {
            $when = Carbon::parse($text);
        } catch (\Throwable) {
            return $text;
        }

        // A date with no time of day is a date; printing "12:00 AM" onto it
        // invents a precision that was never recorded.
        return strlen(trim($text)) <= 10
            ? $when->format('M d, Y')
            : $when->format('M d, Y g:i A');
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
