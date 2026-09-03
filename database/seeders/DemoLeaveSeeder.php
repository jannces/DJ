<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveHistory;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * OPTIONAL demo leave history, for evaluation and defence. Never on live data.
 *
 * DemoDataSeeder created accounts and credited opening balances, and stopped
 * there -- not one application. So every figure on the Leave management
 * dashboard was honestly zero even after seeding the demo, and the page looked
 * broken when it was only empty. The charts were never the missing part.
 *
 * This fills the year behind today so each panel has something to show:
 *
 *   Applications filed per month  · applications spread across every month
 *   Outcome of this year          · approved, rejected and still-waiting
 *   Most applied leave type       · weighted to VL and SL, as an office is
 *   Applications by office        · uneven between offices, as an office is
 *   Waiting longest               · open applications filed weeks ago
 *   Decided this month            · decisions dated inside this month
 *   On leave today / Coverage     · approved leave covering today and the
 *                                   fortnight ahead, one office deliberately
 *                                   pushed over the 40% line
 *   Mandatory Leave not yet filed · FL credited to everyone, spent by some
 *
 * Deterministic: the same seed produces the same figures every run, so a
 * screenshot taken today still matches the system next week.
 *
 * To remove it, rebuild: `php artisan migrate:fresh --seed`. DatabaseSeeder
 * carries the LGU's real configuration only -- roles, leave types, holidays,
 * settings, offices, plantilla -- and none of this.
 */
class DemoLeaveSeeder extends Seeder
{
    /** Roughly what an LGU files in a year, by leave type. */
    private const MIX = [
        'VL' => 34, 'SL' => 30, 'FL' => 12, 'SPL' => 8,
        'ML' => 4, 'PL' => 4, 'SOLO' => 3, 'SEL' => 3, 'STL' => 2,
    ];

    public function run(): void
    {
        if (LeaveRequest::withTrashed()->exists()) {
            $this->command?->warn('Leave applications already exist — DemoLeaveSeeder skipped.');
            $this->command?->warn('Rebuild first if you meant to replace them: php artisan migrate:fresh --seed');

            return;
        }

        $types = LeaveType::whereIn('code', array_keys(self::MIX))->get()->keyBy('code');
        if ($types->isEmpty()) {
            $this->command?->error('No leave types found. Run LeaveTypeSeeder first.');

            return;
        }

        $staff = EmployeeProfile::with('user', 'department', 'position')
            ->whereHas('user')->get();

        if ($staff->count() < 2) {
            $this->command?->warn('Fewer than two employee records — seed accounts first (DemoDataSeeder).');

            return;
        }

        // Deterministic, so the figures on a screenshot still match the system
        // a week later. A demo that reshuffles itself on every run cannot be
        // pointed at during a defence.
        mt_srand(20260101);

        $pool = [];
        foreach (self::MIX as $code => $weight) {
            if ($types->has($code)) {
                $pool = array_merge($pool, array_fill(0, $weight, $code));
            }
        }

        $filed = $this->history($staff, $types, $pool);
        $filed += $this->backlog($staff, $types);
        $filed += $this->comingFortnight($staff, $types);
        $this->ledger($staff, $types);

        mt_srand();

        $this->command?->info("Seeded {$filed} demo leave applications across ".$staff->count().' employees.');
    }

    /**
     * Credits, and the ledger they come from.
     *
     * Built from the applications rather than made up beside them. A demo whose
     * balance says nine days used while the credit history below it is empty is
     * the first thing a panel will ask about, and the answer would be that the
     * two were written by different lines of a seeder.
     *
     * So: 1.25 VL and 1.25 SL accrue each month, as the CSC prescribes, and
     * every approved application deducts its own days. The balance is what is
     * left after both, which is what the ledger says.
     */
    private function ledger($staff, $types): void
    {
        $months = collect();
        for ($m = Carbon::now()->startOfYear(); $m->lte(Carbon::now()); $m->addMonth()) {
            $months->push($m->copy());
        }

        foreach ($staff as $profile) {
            // Mandatory and Special Privilege Leave are granted whole at the
            // start of the year rather than accrued, so they have no ledger.
            foreach (['FL' => 5.0, 'SPL' => 3.0] as $code => $earned) {
                if ($types->has($code)) {
                    $used = (float) LeaveRequest::where('user_id', $profile->user_id)
                        ->where('leave_type_id', $types[$code]->id)
                        ->where('status', 'approved')->sum('working_days');
                    $used = min($used, $earned);

                    LeaveBalance::updateOrCreate(
                        ['user_id' => $profile->user_id, 'leave_type_id' => $types[$code]->id],
                        ['earned' => $earned, 'used' => $used, 'balance' => $earned - $used],
                    );
                }
            }

            foreach (['VL', 'SL'] as $code) {
                if (! $types->has($code)) {
                    continue;
                }
                $type = $types[$code];

                // Credits carry over under the CSC rules, so the year does not
                // start at nothing -- and without an opening balance the first
                // approved application in February would drive the ledger
                // negative.
                $entries = collect([[
                    'at' => Carbon::now()->startOfYear(),
                    'kind' => 'adjustment', 'days' => 10.0, 'period' => null,
                    'remarks' => 'Balance carried over from '.(Carbon::now()->year - 1),
                    'request' => null,
                ]]);

                // `period` is what makes an accrual idempotent -- one per
                // employee, per type, per month, enforced by uq_accrual_period.
                // A deduction is not periodic and leaves it null, exactly as
                // LeaveCreditService does.
                foreach ($months as $m) {
                    $entries->push([
                        'at' => $m->copy()->endOfMonth()->min(Carbon::now()),
                        'kind' => 'accrual', 'days' => 1.25, 'period' => $m->format('Y-m'),
                        'remarks' => 'Monthly accrual for '.$m->format('F Y'), 'request' => null,
                    ]);
                }

                $approved = LeaveRequest::where('user_id', $profile->user_id)
                    ->where('leave_type_id', $type->id)
                    ->where('status', 'approved')->whereNotNull('decided_at')
                    ->orderBy('decided_at')->get();

                foreach ($approved as $request) {
                    $entries->push([
                        'at' => $request->decided_at,
                        'kind' => 'deduction', 'days' => -(float) $request->working_days,
                        'period' => null,
                        'remarks' => "Approved {$type->name} ({$request->reference_no})",
                        'request' => $request->id,
                    ]);
                }

                $running = 0.0;
                $earned = 0.0;
                $used = 0.0;

                foreach ($entries->sortBy('at') as $entry) {
                    // Nobody goes below zero. The real service refuses the
                    // approval; here the application stays and the deduction
                    // does not, which is the same balance either way.
                    if ($entry['days'] < 0 && $running + $entry['days'] < 0) {
                        continue;
                    }

                    $running += $entry['days'];
                    $entry['days'] > 0 ? $earned += $entry['days'] : $used += abs($entry['days']);

                    LeaveHistory::create([
                        'user_id' => $profile->user_id,
                        'leave_type_id' => $type->id,
                        'leave_request_id' => $entry['request'],
                        'entry_type' => $entry['kind'],
                        'days' => $entry['days'],
                        'balance_after' => $running,
                        'period' => $entry['period'],
                        'remarks' => $entry['remarks'],
                    ])->forceFill(['created_at' => $entry['at'], 'updated_at' => $entry['at']])->save();
                }

                LeaveBalance::updateOrCreate(
                    ['user_id' => $profile->user_id, 'leave_type_id' => $type->id],
                    ['earned' => $earned, 'used' => $used, 'balance' => $running],
                );
            }
        }
    }

    /** The year behind today: decided applications, and some still waiting. */
    private function history($staff, $types, array $pool): int
    {
        $start = Carbon::now()->startOfYear();
        $today = Carbon::today();
        $span = max(1, (int) $start->diffInDays($today));
        $filed = 0;

        foreach ($staff as $profile) {
            foreach (range(1, mt_rand(2, 5)) as $ignored) {
                $dateFiled = $start->copy()->addDays(mt_rand(0, $span));
                $age = (int) $dateFiled->diffInDays($today);

                // Anything filed more than three weeks ago has been decided;
                // what is left is what an office is genuinely still sitting on.
                [$status, $decidedAt] = $age > 21
                    ? [mt_rand(1, 10) <= 7 ? 'approved' : 'rejected',
                        $dateFiled->copy()->addDays(mt_rand(1, 9))]
                    : ['pending', null];

                $filed += $this->file($profile, $types[$pool[mt_rand(0, count($pool) - 1)]],
                    $dateFiled, $dateFiled->copy()->addDays(mt_rand(5, 25)),
                    mt_rand(0, 4), $status, $decidedAt) ? 1 : 0;
            }
        }

        return $filed;
    }

    /**
     * A backlog somebody is actually sitting on.
     *
     * Left to chance, whether anything is still open depends on how the random
     * dates fell, and a demo where "Waiting on a decision" reads 1 cannot show
     * the queue or the older-than-five-days counter. These are filed
     * deliberately, at ages that put some of them past the stale line.
     *
     * All of them sit at `pending`: there is one open status now, because the
     * department step is a notification rather than a stage to wait at.
     */
    private function backlog($staff, $types): int
    {
        $today = Carbon::today();
        $ages = [2, 4, 8, 13, 21, 34];
        $filed = 0;

        foreach ($ages as $i => $age) {
            $profile = $staff[$i % $staff->count()];
            $dateFiled = $today->copy()->subDays($age);

            $filed += $this->file($profile, $types[['VL', 'SL', 'SPL'][$i % 3]] ?? $types->first(),
                $dateFiled, $dateFiled->copy()->addDays(mt_rand(10, 30)),
                mt_rand(0, 3), 'pending', null) ? 1 : 0;
        }

        return $filed;
    }

    /**
     * Approved leave covering today and the next fortnight.
     *
     * "On leave today" and Coverage risk read approved applications whose dates
     * span the day in question, and nothing in a random year-long history is
     * likely to land on this particular fortnight. One office is deliberately
     * pushed past the 40% line so the red state can be seen rather than only
     * described.
     */
    private function comingFortnight($staff, $types): int
    {
        $today = Carbon::today();
        $filed = 0;

        // Filtered here rather than with HAVING: the count is a subquery, and
        // SQLite refuses to HAVING on one without a GROUP BY.
        $smallest = Department::withCount('employees')->get()
            ->filter(fn ($d) => $d->employees_count >= 2)
            ->sortBy('employees_count')
            ->first();

        if ($smallest !== null) {
            $away = $staff->where('department_id', $smallest->id)
                ->take((int) ceil($smallest->employees_count * 0.5));

            foreach ($away as $profile) {
                $filed += $this->file($profile, $types['VL'] ?? $types->first(),
                    $today->copy()->subDays(9), $today->copy()->subDay(),
                    3, 'approved', $today->copy()->subDays(6)) ? 1 : 0;
            }
        }

        // A few more, elsewhere, so the fortnight is not one office alone.
        foreach ($staff->shuffle()->take(max(1, (int) ($staff->count() / 4))) as $profile) {
            $offset = mt_rand(0, 11);
            $filed += $this->file($profile, $types['SL'] ?? $types->first(),
                $today->copy()->subDays(mt_rand(3, 12)),
                $today->copy()->addDays($offset), mt_rand(0, 3),
                'approved', $today->copy()->subDays(mt_rand(1, 3))) ? 1 : 0;
        }

        return $filed;
    }

    /** One application, with the snapshots the printed CSC form is built from. */
    private function file(
        EmployeeProfile $profile,
        LeaveType $type,
        Carbon $dateFiled,
        Carbon $startDate,
        int $extraDays,
        string $status,
        ?Carbon $decidedAt,
    ): bool {
        $endDate = $startDate->copy()->addDays($extraDays);

        LeaveRequest::create([
            'reference_no' => sprintf('LV-%d-%05d', $dateFiled->year, LeaveRequest::max('id') + 1001),
            'user_id' => $profile->user_id,
            'leave_type_id' => $type->id,
            'date_filed' => $dateFiled->toDateString(),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'working_days' => $extraDays + 1,
            'purpose' => 'Demo record — seeded for evaluation.',
            'commutation' => false,
            'status' => $status,
            'current_step' => 0,
            'days_with_pay' => $extraDays + 1,
            'days_without_pay' => 0,
            // Where they were when they filed. The dashboard reads their
            // current office instead; this is what the printed form carries.
            'office_snapshot' => $profile->department?->name ?? 'Unassigned',
            'position_snapshot' => $profile->position?->title ?? 'Unassigned',
            'salary_snapshot' => $profile->salary ?? 0,
            'applicant_signature' => trim(($profile->first_name ?? '').' '.($profile->last_name ?? '')),
            'decided_at' => $decidedAt,
        ]);

        return true;
    }
}
