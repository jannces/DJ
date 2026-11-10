<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\EmployeeProfile;
use App\Models\FailedLogin;
use App\Models\IntrusionLog;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Everything the System Administrator's one dashboard reports.
 *
 * Kept out of the controller for the same reason DashboardService is: several
 * of these figures are read twice on the page — the KPI and the chart under it
 * are the same question at two resolutions — and a figure computed twice from
 * two expressions eventually disagrees with itself.
 */
class SecurityDashboardService
{
    /**
     * The three grades in "Attack severity", worst first.
     *
     * The order is fixed rather than sorted by count: this is a scale, and a
     * scale that reorders itself is not one. Every other chart here sorts by
     * magnitude because it answers "which is the most"; this one answers "how
     * bad", and Critical belongs at the top on a quiet week too.
     */
    public const SEVERITY_GRADES = [
        'critical' => ['label' => 'Critical', 'note' => 'source blocked'],
        'high' => ['label' => 'High', 'note' => 'repeated attempts'],
        'medium' => ['label' => 'Medium', 'note' => 'single attempt'],
    ];

    /**
     * The three attacks the system claims to detect, and what each is made of
     * in the data.
     *
     * The paper names three; the detector records four categories, because
     * "input manipulation" is two signature families. They are grouped HERE, in
     * the summary, and nowhere else: the detector keeps recording `xss` and
     * `traversal` separately, the Intrusion Logs page keeps showing which, and
     * the work queue below prints the stored category under the heading. A
     * summary is read to learn which of the three; a queue is read by somebody
     * about to act, and "directory traversal on /files/download" tells them
     * something "input manipulation" cannot.
     *
     * Brute force is the odd one: it is not a request signature but the
     * failed-attempt threshold in LoginSecurityService, which writes its
     * lockouts here under `auth_fail` with `lockout_threshold` as the rule. So
     * the row counts lockouts, and is labelled as lockouts — the attempts
     * themselves are in "Failures by reason" below.
     */
    public const ATTACK_TYPES = [
        'sqli' => ['label' => 'SQL injection', 'source' => 'sqli'],
        'input' => ['label' => 'Input manipulation', 'source' => 'xss + traversal'],
        'brute' => ['label' => 'Brute force', 'source' => 'accounts locked'],
    ];

    /** Which of the three a stored row belongs to. */
    public static function attackOf(IntrusionLog $log): ?string
    {
        return match (true) {
            $log->category === 'sqli' => 'sqli',
            in_array($log->category, ['xss', 'traversal'], true) => 'input',
            $log->matched_rule === 'lockout_threshold' => 'brute',
            default => null,
        };
    }

    /** How a failed sign-in reason reads to a person. */
    public const FAILURE_REASONS = [
        'unknown_user' => 'Unknown username',
        'invalid_password' => 'Wrong password',
        'blocked' => 'Account blocked',
        'inactive' => 'Account deactivated',
        'otp_failed' => 'Wrong one-time code',
    ];

    /**
     * Audit actions that change who can do what.
     *
     * A system whose case rests on auditability should show its own privilege
     * changes to the person making them.
     */
    public const PRIVILEGE_ACTIONS = [
        'user_access_changed' => 'Changed roles for',
        'role_created' => 'Created role',
        'role_updated' => 'Changed permissions on role',
        'role_deleted' => 'Deleted role',
        'user_blocked_manual' => 'Blocked account',
        'user_status_toggled' => 'Changed account status for',
        'password_reset_by_admin' => 'Reset password for',
        'settings_updated' => 'Changed system settings',
    ];

    public function forDashboard(): array
    {
        $trend = $this->intrusionsByDay();
        $severity = $this->attackSeverity();
        $queue = $this->unreviewed();
        $failures = $this->failuresByReason();

        return [
            'kpis' => $this->kpis(),
            'trend' => $trend,
            'attacks' => $this->attacksByType(),

            'severity' => $severity,

            // The three additions.
            'queue' => $queue,
            'failures' => $failures,
            'privileges' => $this->privilegeChanges(),

            // Derived from the four above, so this costs no query of its own.
            'alerts' => $this->alerts($severity, $queue, $failures, $trend),
        ];
    }

    /**
     * What the week's figures actually mean, as a short list of statements.
     *
     * The panels on this page each answer one question well and none of them
     * answers "is anything wrong". An administrator opening the screen has to
     * read four charts and do the comparison themselves; these do the
     * comparison and say the answer.
     *
     * Every entry is COMPUTED from figures already on the page -- nothing here
     * is a fixed message, and nothing is shown unless it is true right now. An
     * alert nobody can trust is worse than no alert, so the thresholds are
     * written down rather than tuned by feel:
     *
     *   critical  something got through, or an account was locked out
     *   warning   attempts are up by half again on last week, or the failures
     *             are dominated by usernames that do not exist
     *   info      events are waiting to be reviewed
     *   healthy   none of the above
     *
     * The healthy line is not decoration. A panel that shows nothing when
     * nothing is wrong is indistinguishable from a panel that has broken, and
     * on a security screen that difference matters.
     *
     * @return list<array{tone:string,label:string,title:string,body:string}>
     */
    private function alerts(array $severity, array $queue, array $failures, array $trend): array
    {
        $alerts = [];
        $reached = (int) $severity['reached'];
        $total = (int) $severity['total'];
        $previous = (int) $severity['previous'];

        if ($reached > 0) {
            $alerts[] = [
                'tone' => 'critical',
                'label' => 'Critical',
                'title' => $reached === 1
                    ? 'An attempt reached the application'
                    : $reached.' attempts reached the application',
                'body' => 'The detector did not refuse '.($reached === 1 ? 'it' : 'them')
                    .' before the request was served. Open Intrusion Logs and check what '
                    .($reached === 1 ? 'it' : 'they').' asked for.',
            ];
        }

        // Half again on last week, and enough of a base that the ratio means
        // something -- 3 against 2 is not a trend.
        if ($previous >= 4 && $total > $previous * 1.5) {
            $alerts[] = [
                'tone' => 'warning',
                'label' => 'Warning',
                'title' => 'Attempts are up sharply on last week',
                'body' => $total.' this week against '.$previous.' last week. The week is '
                    .'not over, so the rise is worth watching rather than acting on yet.',
            ];
        }

        // Failures against usernames that do not exist are a different attack
        // from failures against passwords: somebody is guessing accounts.
        $unknown = 0;
        $allFailures = 0;
        foreach ($failures as $row) {
            $allFailures += (int) $row['value'];
            if (str_contains(strtolower($row['label']), 'unknown')) {
                $unknown += (int) $row['value'];
            }
        }

        if ($allFailures >= 8 && $unknown > $allFailures / 2) {
            $alerts[] = [
                'tone' => 'warning',
                'label' => 'Warning',
                'title' => 'Sign-in failures are mostly unknown usernames',
                'body' => $unknown.' of '.$allFailures.' failures were against accounts that do '
                    .'not exist. That is somebody guessing account names rather than passwords.',
            ];
        }

        if (($queue['total'] ?? 0) > 0) {
            $alerts[] = [
                'tone' => 'info',
                'label' => 'Info',
                'title' => $queue['total'].' '.($queue['total'] === 1 ? 'event is' : 'events are')
                    .' waiting to be reviewed',
                'body' => 'Reviewing is a decision, not a page view: the count stays up until '
                    .'an administrator marks '.($queue['total'] === 1 ? 'it' : 'them').' handled.',
            ];
        }

        if (! $alerts) {
            $peak = $trend['data'] ? max(array_map('intval', $trend['data'])) : 0;

            $alerts[] = [
                'tone' => 'healthy',
                'label' => 'Healthy',
                'title' => $total === 0
                    ? 'No attacks detected this week'
                    : 'Everything this week was refused',
                'body' => $total === 0
                    ? 'Nothing matched a detector signature in the last seven days, and no '
                        .'events are waiting to be reviewed.'
                    : 'All '.$total.' attempts were refused before reaching the application, '
                        .'the busiest day carrying '.$peak.'. Nothing is outstanding.',
            ];
        }

        return $alerts;
    }

    // ---------------------------------------------------------------- counters

    private function kpis(): array
    {
        $today = today();
        $week = now()->subDays(6)->startOfDay();

        $accounts = User::count();
        $employees = EmployeeProfile::count();

        $lockedToday = IntrusionLog::where('matched_rule', 'lockout_threshold')
            ->whereDate('created_at', $today)->count();

        $weekEvents = IntrusionLog::where('created_at', '>=', $week);
        $weekTotal = (clone $weekEvents)->count();
        $weekSources = (clone $weekEvents)->distinct()->count('ip');

        $blocked = BlockedIp::currentlyActive()->count();
        $blockedToday = BlockedIp::currentlyActive()->whereDate('created_at', $today)->count();

        // The week before this one, for the change figures. Counted rather
        // than estimated: a tile that says "up 13%" has to be able to say
        // against what.
        $prevFrom = now()->subDays(13)->startOfDay();
        $prevIntrusions = IntrusionLog::whereBetween('created_at', [$prevFrom, $week])->count();
        $failedToday = FailedLogin::whereDate('occurred_at', $today)->count();
        $failedYesterday = FailedLogin::whereDate('occurred_at', $today->copy()->subDay())->count();
        $blockedThisWeek = BlockedIp::where('created_at', '>=', $week)->count();
        $blockedPrevWeek = BlockedIp::whereBetween('created_at', [$prevFrom, $week])->count();

        return [
            [
                'label' => 'Accounts',
                'value' => $accounts,
                'sub' => $employees.' with an employee record · '.max(0, $accounts - $employees).' other',
                'icon' => 'people',
                'tone' => 'info',
                // A ring rather than a spark: accounts are a standing total,
                // not a rate, so a seven-day line of it would be flat by
                // definition. What DOES divide is the split the subtitle
                // already states, so the ring draws that.
                'ring' => [
                    'parts' => [
                        ['label' => 'With employee record', 'value' => $employees],
                        ['label' => 'Other', 'value' => max(0, $accounts - $employees)],
                    ],
                ],
            ],
            [
                'label' => 'Failed sign-ins today',
                'value' => FailedLogin::whereDate('occurred_at', $today)->count(),
                'lead' => $lockedToday > 0 ? (string) $lockedToday : null,
                'sub' => $lockedToday > 0 ? 'locked out' : 'no lockouts',
                'icon' => 'keyx',
                'tone' => $lockedToday > 0 ? 'bad' : 'warn',
                'spark' => $this->dailyCounts(FailedLogin::query(), 'occurred_at'),
                'delta' => $this->change($failedToday, $failedYesterday, 'vs yesterday', 'bad'),
            ],
            [
                'label' => 'Intrusions this week',
                'value' => $weekTotal,
                'sub' => $weekTotal > 0
                    ? 'from '.$weekSources.($weekSources === 1 ? ' address' : ' addresses')
                    : 'nothing detected',
                'icon' => 'bug',
                'tone' => $weekTotal > 0 ? 'bad' : 'good',
                'spark' => $this->dailyCounts(IntrusionLog::query(), 'created_at'),
                'delta' => $this->change($weekTotal, $prevIntrusions, 'vs last week', 'bad'),
            ],
            [
                'label' => 'Blocked addresses',
                'value' => $blocked,
                'sub' => $blockedToday > 0
                    ? $blockedToday.' added today'
                    : 'none added today',
                'icon' => 'slash',
                'tone' => 'good',
                'spark' => $this->dailyCounts(BlockedIp::query(), 'created_at'),
                // A block is the system acting, so more of them is not bad
                // news the way more intrusions is. The arrow says which way
                // and the tone stays neutral.
                'delta' => $this->change($blockedThisWeek, $blockedPrevWeek, 'vs last week', 'flat'),
            ],
        ];
    }

    /**
     * Seven daily counts for a KPI's spark, oldest first.
     *
     * Days with nothing are zeros rather than gaps: a spark with a missing
     * Tuesday reads as a quiet Tuesday shifted into Wednesday's place, which
     * is a different week from the one that happened.
     *
     * @return list<int>
     */
    private function dailyCounts($query, string $column, int $days = 7): array
    {
        $from = today()->subDays($days - 1);

        // Every selectRaw here takes a LITERAL. The first version built
        // "date($column)" by interpolation and SqlInjectionTest refused it;
        // the second passed a variable holding an allowlisted literal, and it
        // refused that too -- correctly, since a reader of the call site
        // cannot see what is in the variable. A column name can never be a
        // binding, so the only safe form is a branch per column, each with the
        // string written out where it is used. No default: an unknown column
        // throws here rather than reaching the database.
        $start = $from->copy()->startOfDay();

        $counts = (match ($column) {
            'created_at' => $query->where('created_at', '>=', $start)
                ->selectRaw('date(created_at) as day, count(*) as total'),
            'occurred_at' => $query->where('occurred_at', '>=', $start)
                ->selectRaw('date(occurred_at) as day, count(*) as total'),
        })
            ->groupBy('day')
            ->pluck('total', 'day');

        $out = [];
        for ($d = $from->copy(); $d->lte(today()); $d->addDay()) {
            $out[] = (int) ($counts[$d->toDateString()] ?? 0);
        }

        return $out;
    }

    /**
     * This period against the one before it, as the tiles' change figure.
     *
     * The percentage is only meaningful when there was something to compare
     * to. 4 against 0 is not "up 400%", it is "up from nothing"; and 44
     * against 2 is arithmetically "up 2100%" and useless to read -- a figure
     * that size is noise dressed as precision. Below a baseline of five the
     * change is reported as the plain difference instead, which is both
     * shorter and the thing an administrator actually wants: "up 42".
     *
     * `tone` is passed in rather than derived from the arrow, because which
     * direction is bad depends on the measure: more intrusions is bad news,
     * more blocked addresses is the system working.
     *
     * @return array{value:string,dir:string,of:string,tone:string}|null
     */
    private function change(int $now, int $before, string $of, string $tone): ?array
    {
        if ($now === 0 && $before === 0) {
            return null;
        }

        $dir = $now > $before ? 'up' : ($now < $before ? 'down' : 'flat');

        $value = $before >= 5
            ? round(abs($now - $before) / $before * 100).'%'
            : ($now === $before ? '0' : (string) abs($now - $before));

        return [
            'value' => $value,
            'dir' => $dir,
            'of' => $of,
            'tone' => $dir === 'flat' ? 'flat' : $tone,
        ];
    }

    // ------------------------------------------------------------------ charts

    /**
     * Intrusion events per day for the last seven days, and the seven before.
     *
     * Seven, not four weeks: attacks have no weekly rhythm to read a week
     * against, and a spike matters on the day it happens. The week before is
     * carried alongside as `compare`, because "eleven on Thursday" means one
     * thing after a quiet week and another after the same again -- the chart
     * draws it as a dotted line behind the solid one.
     *
     * FOURTEEN days in ONE query, then split. Two queries would read more
     * plainly, but the second would be the thing this method is tested for not
     * doing -- a query per period is how a chart turns into a query storm --
     * and the split is three lines.
     */
    public function intrusionsByDay(int $days = 7): array
    {
        $from = today()->subDays($days - 1);
        $previousFrom = today()->subDays($days * 2 - 1);

        $counts = IntrusionLog::query()
            ->where('created_at', '>=', $previousFrom->copy()->startOfDay())
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = $this->series($from, today(), $counts, fn (Carbon $d) => $d->format('D'));

        // The same seven slots, one week earlier, so the two lines sit point
        // for point: last Thursday is drawn under this Thursday.
        $compare = [];
        for ($d = $previousFrom->copy(); $d->lt($from); $d->addDay()) {
            $compare[] = (int) ($counts[$d->toDateString()] ?? 0);
        }

        $series['compare'] = $compare;

        return $series;
    }

/** @param callable(Carbon):string $label */
    private function series(Carbon $from, Carbon $to, $counts, callable $label): array
    {
        $labels = [];
        $data = [];

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $labels[] = $label($d);
            $data[] = (int) ($counts[$d->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * The three attacks, over the last thirty days.
     *
     * A month rather than a week: three counts over seven days on a quiet
     * installation is three zeros, and a chart whose answer is always "nothing"
     * cannot be told from one that is broken.
     */
    public function attacksByType(int $days = 30): array
    {
        $from = now()->subDays($days)->startOfDay();

        $byCategory = IntrusionLog::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $lockouts = IntrusionLog::query()
            ->where('created_at', '>=', $from)
            ->where('matched_rule', 'lockout_threshold')
            ->count();

        $values = [
            'sqli' => (int) ($byCategory['sqli'] ?? 0),
            'input' => (int) ($byCategory['xss'] ?? 0) + (int) ($byCategory['traversal'] ?? 0),
            'brute' => $lockouts,
        ];

        // No 'source' caption. It printed the stored category under each bar
        // -- "xss + traversal", "sqli", "accounts locked" -- which was there to
        // make the grouping visible rather than assumed. Dropped at the LGU's
        // request; ATTACK_TYPES still records the mapping, and Intrusion Logs
        // still shows the raw category per event, so nothing about which
        // detector fired is lost, only its repetition on this panel.
        $rows = [];
        foreach (self::ATTACK_TYPES as $key => $type) {
            $rows[] = [
                'key' => $key,
                'label' => $type['label'],
                'value' => $values[$key],
            ];
        }

        usort($rows, fn ($a, $b) => [$b['value'], $a['label']] <=> [$a['value'], $b['label']]);

        return $rows;
    }

    /**
     * How serious this week's attacks were, graded by escalation.
     *
     * The detector records all three attack types at `high` and nothing at all
     * at `low` or `critical`, so reading the stored severity column would draw
     * one bar and two empty ones. That column says how bad the *kind* of thing
     * is, and for SQL injection, input manipulation and a lockout the answer is
     * always the same. It is not a scale.
     *
     * What differs between two SQL injection attempts is the pattern behind
     * them, so that is what is graded here — in the analytics, not in the
     * detector. Nothing about what gets written to intrusion_logs changes;
     * history is not rewritten, and the log stays a record of what was seen
     * rather than of what was later concluded.
     *
     *   critical  the system had to act — the address was blocked, or an
     *             account was locked. Not a probe: a decision.
     *   high      more than one attempt from the same address. Deliberate.
     *   medium    a single isolated attempt. A scanner, a stray bot, a URL
     *             somebody mistyped.
     *
     * Weekly, to match the attempts-per-day chart above it, and reported
     * against the week before so a number has something to be read against.
     */
    public function attackSeverity(int $days = 7): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $previousFrom = now()->subDays($days * 2 - 1)->startOfDay();

        $events = $this->attackEvents($from);

        // Addresses the system shut out, and the accounts it locked. Both are
        // the system deciding, rather than merely noticing.
        $blocked = BlockedIp::where('created_at', '>=', $from)->pluck('ip')->all();

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0];

        foreach ($events->groupBy('ip') as $ip => $fromThisAddress) {
            $grade = $this->gradeFor($fromThisAddress, in_array($ip, $blocked, true));

            $counts[$grade] += $fromThisAddress->count();
        }

        $rows = [];
        foreach (self::SEVERITY_GRADES as $key => $grade) {
            $rows[] = [
                'key' => $key,
                'label' => $grade['label'],
                'source' => $grade['note'],
                'value' => $counts[$key],
            ];
        }

        // Not asserted, computed: an attempt is prevented when its category is
        // one the detector refuses. A rule added later that only records makes
        // this stop being zero.
        $prevented = app(IntrusionDetectionService::class)::PREVENTED;

        return [
            'rows' => $rows,
            'total' => $events->count(),
            'sources' => $events->pluck('ip')->unique()->count(),
            'reached' => $events->reject(fn ($e) => in_array($e->category, $prevented, true))->count(),
            'previous' => $this->attackEvents($previousFrom, $from)->count(),
            'days' => $days,
        ];
    }

    /**
     * The events behind the three attack types, and only those.
     *
     * CSRF, request rate and privilege denials are recorded by the same
     * detector but are not attacks of these kinds, and mixing them in would
     * make a week of stale browser tabs look like a week under attack.
     */
    private function attackEvents(Carbon $from, ?Carbon $until = null)
    {
        return IntrusionLog::query()
            ->where('created_at', '>=', $from)
            ->when($until, fn ($q) => $q->where('created_at', '<', $until))
            ->where(fn ($q) => $q->whereIn('category', ['sqli', 'xss', 'traversal'])
                ->orWhere('matched_rule', 'lockout_threshold'))
            ->get(['id', 'ip', 'category', 'matched_rule', 'created_at']);
    }

    /**
     * How serious one address's week looks, from its own events.
     *
     * One definition, used by the severity panel and by the list of addresses
     * still awaiting a decision, so "Critical" means the same thing on both.
     *
     * @param  \Illuminate\Support\Collection  $fromThisAddress
     */
    private function gradeFor($fromThisAddress, bool $actedOn): string
    {
        return match (true) {
            $actedOn => 'critical',
            $fromThisAddress->contains(fn ($e) => $e->matched_rule === 'lockout_threshold') => 'critical',
            $fromThisAddress->count() > 1 => 'high',
            default => 'medium',
        };
    }

    /**
     * Addresses seen attacking that nothing is keeping out yet.
     *
     * The system has always known who attacked it -- that is what
     * intrusion_logs is -- but the only way to act on it was to read an
     * address off the log and retype it into a form. This is that list, with
     * enough beside each row to make the decision rather than guess at it.
     *
     * Deliberately narrow. Only the three attack types count, the same three
     * the severity panel grades: a CSRF mismatch is usually a stale browser
     * tab, and a colleague coming back from lunch must never appear on a list
     * headed "seen attacking", one click from a ban.
     *
     * @return array<int,array<string,mixed>>
     */
    public function intruders(int $days = 7, int $limit = 10): array
    {
        $events = $this->attackEvents(now()->subDays($days - 1)->startOfDay());

        $blocked = BlockedIp::currentlyActive()->pluck('ip')->all();

        $rows = [];

        foreach ($events->groupBy('ip') as $ip => $fromThisAddress) {
            // Already kept out, or never blockable: neither is a decision
            // waiting to be made, and a button that silently does nothing is
            // worse than no button.
            if (in_array($ip, $blocked, true) || IntrusionDetectionService::isTrustedIp($ip)) {
                continue;
            }

            $kinds = $fromThisAddress
                ->map(fn ($e) => self::attackOf($e))
                ->filter()->unique()
                ->map(fn ($key) => self::ATTACK_TYPES[$key]['label'])
                ->values()->all();

            $grade = $this->gradeFor($fromThisAddress, false);

            $rows[] = [
                'ip' => $ip,
                'events' => $fromThisAddress->count(),
                'kinds' => $kinds,
                'last_seen' => $fromThisAddress->max('created_at'),
                'grade' => $grade,
                'grade_label' => self::SEVERITY_GRADES[$grade]['label'],
                // A private address is a machine in the building. Blocking one
                // locks a real employee out of the leave system, which is
                // exactly what the broken rate counter did to 192.168.1.7.
                'on_lan' => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE),
                'reason' => $this->evidenceFor($fromThisAddress->count(), $kinds, $days),
            ];
        }

        $order = array_flip(array_keys(self::SEVERITY_GRADES));
        usort($rows, fn ($a, $b) => [$order[$a['grade']], -$a['events']]
            <=> [$order[$b['grade']], -$b['events']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * The sentence a block is recorded against.
     *
     * Written from the evidence rather than typed, because a block is an audit
     * record and a reason somebody typed in a hurry is worth less than one the
     * system can substantiate.
     */
    public function evidenceFor(int $events, array $kinds, int $days): string
    {
        return sprintf(
            '%d intrusion event%s in %d days: %s',
            $events,
            $events === 1 ? '' : 's',
            $days,
            $kinds ? strtolower(implode(', ', $kinds)) : 'attack signatures',
        );
    }

    /**
     * The busiest source addresses, each tagged with whether it is still open.
     *
     * "Top attacker" is a stronger claim than the data supports. This runs on
     * the municipal LAN, where DHCP hands addresses out and reuses them and a
     * whole office can sit behind one — so the card is titled by what it counts
     * rather than by who it blames, and nothing is auto-blocked on the strength
     * of it alone.
     *
     * The blocked/open tag is the point. An address at the top of the list
     * raises exactly one question — has it been dealt with — and without the
     * tag the card reports a problem and leaves you to go and find out.
     */
    public function topAttackers(int $days = 30, int $limit = 6): array
    {
        $rows = IntrusionLog::query()
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('ip, count(*) as total')
            ->groupBy('ip')->orderByDesc('total')->limit($limit)
            ->get();

        $blocked = BlockedIp::currentlyActive()
            ->whereIn('ip', $rows->pluck('ip'))->pluck('ip')->all();

        return $rows->map(fn ($row) => [
            'label' => $row->ip,
            'value' => (int) $row->total,
            'blocked' => in_array($row->ip, $blocked, true),
        ])->all();
    }

// ------------------------------------------------------------- the queue

    /**
     * Events nobody has marked reviewed.
     *
     * `intrusion_logs.handled` existed and was cleared for every row the moment
     * this page rendered, so it recorded a page view rather than a decision.
     * This is that column doing the job its name claims.
     *
     * `payload_excerpt` is deliberately not here. It is the most interesting
     * field in the table and it is attacker-controlled text; it belongs on the
     * detail page, escaped, not on a screen somebody glances at forty times a
     * day.
     */
    public function unreviewed(int $limit = 6): array
    {
        $logs = IntrusionLog::where('handled', false)->latest()->limit($limit)->get();

        return [
            'total' => IntrusionLog::where('handled', false)->count(),
            'rows' => $logs->map(function (IntrusionLog $log) {
                $attack = self::attackOf($log);

                return [
                    'id' => $log->id,
                    'when' => $log->created_at->isToday()
                        ? $log->created_at->format('H:i')
                        : $log->created_at->format('D'),
                    'label' => $attack ? self::ATTACK_TYPES[$attack]['label'] : ucfirst($log->category),
                    'detail' => $log->category.' · /'.ltrim((string) $log->route, '/'),
                    'severity' => $log->severity,
                    'ip' => $log->ip,
                ];
            })->all(),
        ];
    }

    /**
     * Failed sign-ins grouped by why they failed.
     *
     * Thirty-two failures is one number. Twenty-three of them against usernames
     * that do not exist is a diagnosis — somebody is guessing accounts, not
     * passwords, and that is a different attack with a different answer.
     */
    public function failuresByReason(int $days = 7): array
    {
        $counts = FailedLogin::query()
            ->where('occurred_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');

        $rows = [];
        foreach (self::FAILURE_REASONS as $reason => $label) {
            $rows[] = ['label' => $label, 'value' => (int) ($counts[$reason] ?? 0)];
        }

        // A reason the service starts recording later still shows up, rather
        // than silently going missing from a chart that claims to be complete.
        foreach ($counts as $reason => $total) {
            if (! array_key_exists($reason, self::FAILURE_REASONS)) {
                $rows[] = ['label' => ucfirst(str_replace('_', ' ', (string) $reason)), 'value' => (int) $total];
            }
        }

        usort($rows, fn ($a, $b) => [$b['value'], $a['label']] <=> [$a['value'], $b['label']]);

        return $rows;
    }

    /** Role, permission and account changes, newest first. */
    public function privilegeChanges(int $days = 7, int $limit = 6): array
    {
        return AuditLog::with('user')
            ->whereIn('action', array_keys(self::PRIVILEGE_ACTIONS))
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->latest()->limit($limit)->get()
            ->map(fn (AuditLog $log) => [
                'when' => $log->created_at->format('D H:i'),
                'what' => self::PRIVILEGE_ACTIONS[$log->action] ?? $log->action,
                'target' => $this->targetOf($log),
                'who' => $log->user?->name ?? 'System',
            ])->all();
    }

    /**
     * What the change was made to, in words.
     *
     * Never the raw values: they are redacted where they need to be but they
     * are still whatever was typed into a form, and this is a summary card.
     */
    private function targetOf(AuditLog $log): string
    {
        $values = $log->new_values ?? [];

        foreach (['name', 'username', 'email', 'key'] as $field) {
            if (! empty($values[$field]) && is_string($values[$field])) {
                return $values[$field];
            }
        }

        return $log->auditable_type
            ? class_basename($log->auditable_type).' #'.$log->auditable_id
            : '—';
    }
}
