<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Who has used the most of each leave type, this year.
 *
 * The dashboard answers this by office and by type; it does not answer it by
 * person, and that is the question behind most of what HR is actually asked --
 * who is close to spending their credits, who has not touched their Mandatory
 * Leave, whether the Treasurer's Office clerk really is out as often as the
 * head says.
 *
 * It is a ranking of days used, not a league table of people. Vacation days
 * are earned and spending them is not a fault, so nothing here is coloured as
 * a problem; what IS coloured is the remaining balance, because running out is
 * a fact the employee needs to know before they file again.
 *
 * The page is gated on employees.view: it names every employee and how much
 * leave they take, which is HR's business and nobody else's. A department head
 * with leave.review.department sees only their own office.
 */
class RankingController extends Controller
{
    /** A page of a ranking is still a ranking; twenty is what fits. */
    private const PER_TYPE = 20;

    public function index(Request $request): View
    {
        $year = (int) now()->year;

        // Only types worth ranking: the ones with credits behind them, plus
        // any type actually filed this year. Ranking Terminal Leave, which is
        // filed once when somebody retires, is a list of one name.
        $types = LeaveType::query()
            ->where(fn ($q) => $q->whereIn('code', ['VL', 'SL', 'FL', 'SPL'])
                ->orWhereIn('id', LeaveRequest::whereYear('date_filed', $year)
                    ->select('leave_type_id')))
            ->orderBy('id')
            ->get(['id', 'code', 'name']);

        $scope = $this->scope($request);

        $rankings = [];
        foreach ($types as $type) {
            $rankings[] = [
                'type' => $type,
                'rows' => $this->rank($type, $year, $scope, $request->string('q')->toString()),
            ];
        }

        return view('hr.rankings', [
            'rankings' => $rankings,
            'year' => $year,
            'scope' => $scope,
        ]);
    }

    /**
     * The office a reader is confined to, or null for all of them.
     *
     * A department head is given this page for their own office. The scope is
     * taken from the department record, never from the request -- ?department=3
     * would otherwise be every office in the LGU.
     */
    private function scope(Request $request): ?int
    {
        $user = $request->user();

        if ($user->hasPermission('employees.view')) {
            return null;
        }

        return Department::where('head_user_id', $user->id)->value('id');
    }

    /**
     * One type's ranking: days approved this year, against the credit if the
     * type has one.
     *
     * Approved only. A pending application is a request, not leave taken, and
     * counting it would rank somebody for asking.
     */
    private function rank(LeaveType $type, int $year, ?int $office, string $search): array
    {
        $used = LeaveRequest::query()
            ->join('users', 'users.id', '=', 'leave_requests.user_id')
            ->leftJoin('employee_profiles', 'employee_profiles.user_id', '=', 'leave_requests.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'employee_profiles.department_id')
            ->leftJoin('positions', 'positions.id', '=', 'employee_profiles.position_id')
            ->where('leave_requests.leave_type_id', $type->id)
            ->where('leave_requests.status', 'approved')
            ->whereYear('leave_requests.start_date', $year)
            ->whereNull('users.deleted_at')
            ->when($office !== null, fn ($q) => $q->where('employee_profiles.department_id', $office))
            ->when($search !== '', fn ($q) => $q->where('users.name', 'like', '%'.$search.'%'))
            ->groupBy('users.id', 'users.name', 'departments.name', 'positions.title')
            ->orderByRaw('sum(leave_requests.working_days) desc')
            ->orderBy('users.name')
            ->limit(self::PER_TYPE)
            ->get([
                'users.id as user_id', 'users.name as name',
                'departments.name as office', 'positions.title as position',
                DB::raw('sum(leave_requests.working_days) as days'),
            ]);

        if ($used->isEmpty()) {
            return [];
        }

        $balances = LeaveBalance::where('leave_type_id', $type->id)
            ->whereIn('user_id', $used->pluck('user_id'))
            ->get()->keyBy('user_id');

        $peak = max(1, (float) $used->max('days'));

        return $used->values()->map(function ($row, $i) use ($balances, $peak) {
            $balance = $balances[$row->user_id] ?? null;
            $left = $balance ? (float) $balance->balance : null;

            return [
                'rank' => $i + 1,
                'user_id' => $row->user_id,
                'name' => $row->name,
                'initials' => self::initials($row->name),
                'office' => $row->office ?? '—',
                'position' => $row->position ?? '—',
                'days' => self::trim((float) $row->days),
                'pct' => round((float) $row->days / $peak * 100, 1),
                'earned' => $balance ? self::trim((float) $balance->earned) : null,
                'left' => $left === null ? null : self::trim($left),
                // Only two states, and neither is about the ranking: out of
                // credits, or nearly. Taking leave you have earned is not a
                // fault and is not coloured as one.
                'state' => match (true) {
                    $left === null => 'none',
                    $left <= 0 => 'spent',
                    $left <= 3 => 'low',
                    default => 'ok',
                },
            ];
        })->all();
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
