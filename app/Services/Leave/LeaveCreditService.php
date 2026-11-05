<?php

namespace App\Services\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveHistory;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns leave-credit arithmetic and the invariant that a balance can never go
 * negative. Deductions run inside a transaction with row locks so concurrent
 * approvals cannot both spend the same credits (race-safe, FR-L6).
 */
class LeaveCreditService
{
    public function monthlyAccrualRate(string $source): float
    {
        return $source === LeaveType::SOURCE_VACATION
            ? (float) SystemSetting::get('leave.monthly_vl_accrual', 1.25)
            : (float) SystemSetting::get('leave.monthly_sl_accrual', 1.25);
    }

    public function balanceFor(User $user, LeaveType $type): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'leave_type_id' => $type->id],
            ['earned' => 0, 'used' => 0, 'balance' => 0],
        );
    }

    /** The credit-source balance a deductible request draws down. */
    public function sourceBalance(User $user, LeaveType $type): ?LeaveBalance
    {
        if (! $type->deductible || ! $type->credit_source) {
            return null;
        }
        $sourceType = $this->creditSourceType($type);

        return $sourceType ? $this->balanceFor($user, $sourceType) : null;
    }

    public function creditSourceType(LeaveType $type): ?LeaveType
    {
        return match ($type->credit_source) {
            LeaveType::SOURCE_VACATION => LeaveType::where('code', 'VL')->first(),
            LeaveType::SOURCE_SICK => LeaveType::where('code', 'SL')->first(),
            default => null,
        };
    }

    /**
     * The balances terminal leave commutes: vacation AND sick, together.
     *
     * Terminal leave pays the money value of every accumulated credit, not of
     * one kind of them. The leave type carries credit_source 'vacation', which
     * is what every other deductible type uses and is wrong here -- it would
     * pay out the vacation credits and leave the sick ones unpaid, and would
     * refuse the application altogether for somebody whose credits are mostly
     * sick leave.
     *
     * @return \Illuminate\Support\Collection<int, LeaveBalance>
     */
    public function commutableBalances(User $user): \Illuminate\Support\Collection
    {
        return LeaveType::whereIn('code', ['VL', 'SL'])->get()
            ->map(fn (LeaveType $type) => $this->balanceFor($user, $type));
    }

    /** Their sum, which is D in the terminal leave computation. */
    public function commutableCredits(User $user): float
    {
        return (float) $this->commutableBalances($user)->sum(fn ($b) => (float) $b->balance);
    }

    public function hasSufficientCredits(User $user, LeaveType $type, float $days): bool
    {
        if (! $type->deductible) {
            return true;
        }

        // Terminal leave commutes what is there, across both balances, so the
        // single credit_source balance is the wrong thing to compare against.
        if ($type->category === LeaveType::CATEGORY_TERMINAL) {
            return $this->commutableCredits($user) >= $days;
        }

        $balance = $this->sourceBalance($user, $type);

        return $balance ? (float) $balance->balance >= $days : true;
    }

    /**
     * Deduct credits for an approved request. Throws if credits are insufficient.
     * Writes a leave_history ledger row. Idempotent guard: a request is deducted once.
     */
    public function deductForApproval(LeaveRequest $request, ?User $actor = null): void
    {
        $type = $request->leaveType;
        if (! $type->deductible || ! $type->credit_source) {
            return;
        }

        // Terminal leave empties both balances rather than taking a number of
        // days off one, so it has its own path below.
        if ($type->category === LeaveType::CATEGORY_TERMINAL) {
            $this->commuteAllCredits($request, $actor);

            return;
        }

        DB::transaction(function () use ($request, $type, $actor) {
            $sourceType = $this->creditSourceType($type);
            if (! $sourceType) {
                return;
            }

            $balance = LeaveBalance::where('user_id', $request->user_id)
                ->where('leave_type_id', $sourceType->id)
                ->lockForUpdate()
                ->first();

            $balance ??= $this->balanceFor($request->user, $sourceType);

            // Idempotency: skip if a deduction ledger row already exists.
            $already = LeaveHistory::where('leave_request_id', $request->id)
                ->where('entry_type', 'deduction')->exists();
            if ($already) {
                return;
            }

            $days = (float) $request->working_days;
            if ((float) $balance->balance < $days) {
                throw new RuntimeException('Insufficient leave credits; cannot approve.');
            }

            $balance->used = (float) $balance->used + $days;
            $balance->balance = (float) $balance->balance - $days;
            $balance->save();

            LeaveHistory::create([
                'user_id' => $request->user_id,
                'leave_type_id' => $sourceType->id,
                'leave_request_id' => $request->id,
                'entry_type' => 'deduction',
                'days' => -$days,
                'balance_after' => $balance->balance,
                'remarks' => "Approved {$type->name} ({$request->reference_no})",
                'actor_id' => $actor?->id,
            ]);
        });
    }

    /**
     * Pay out every accumulated credit, vacation and sick, and leave both at
     * zero.
     *
     * One ledger row per balance, so the trail says which credits were
     * commuted and in what quantity -- the figures an accountant has to be
     * able to reconstruct the payment from. A single combined row would show
     * the total and lose the split.
     *
     * No sufficiency check: there is no amount to be short of. Whatever is on
     * the ledger on the day of separation is what is paid.
     */
    private function commuteAllCredits(LeaveRequest $request, ?User $actor): void
    {
        DB::transaction(function () use ($request, $actor) {
            // Idempotency, on the same terms as the ordinary path: a request
            // that already has deduction rows is not deducted twice.
            if (LeaveHistory::where('leave_request_id', $request->id)
                ->where('entry_type', 'deduction')->exists()) {
                return;
            }

            foreach (LeaveType::whereIn('code', ['VL', 'SL'])->get() as $sourceType) {
                $balance = LeaveBalance::where('user_id', $request->user_id)
                    ->where('leave_type_id', $sourceType->id)
                    ->lockForUpdate()
                    ->first() ?? $this->balanceFor($request->user, $sourceType);

                $days = (float) $balance->balance;
                if ($days <= 0) {
                    continue;
                }

                $balance->used = (float) $balance->used + $days;
                $balance->balance = 0;
                $balance->save();

                LeaveHistory::create([
                    'user_id' => $request->user_id,
                    'leave_type_id' => $sourceType->id,
                    'leave_request_id' => $request->id,
                    'entry_type' => 'deduction',
                    'days' => -$days,
                    'balance_after' => 0,
                    'remarks' => "Commuted on terminal leave ({$request->reference_no})",
                    'actor_id' => $actor?->id,
                ]);
            }
        });
    }

    /**
     * Reverse a deduction (e.g. cancellation after approval).
     *
     * Every deduction row, not the first: terminal leave writes one per
     * balance, and reversing only the first would hand back the vacation
     * credits and keep the sick ones spent.
     */
    public function reverseDeduction(LeaveRequest $request, ?User $actor = null): void
    {
        $deductions = LeaveHistory::where('leave_request_id', $request->id)
            ->where('entry_type', 'deduction')->get();

        foreach ($deductions as $deduction) {
            $this->reverseOne($request, $deduction, $actor);
        }
    }

    private function reverseOne(LeaveRequest $request, LeaveHistory $deduction, ?User $actor): void
    {
        DB::transaction(function () use ($request, $deduction, $actor) {

            $balance = LeaveBalance::where('user_id', $request->user_id)
                ->where('leave_type_id', $deduction->leave_type_id)
                ->lockForUpdate()->first();

            $days = abs((float) $deduction->days);
            $balance->used = max(0, (float) $balance->used - $days);
            $balance->balance = (float) $balance->balance + $days;
            $balance->save();

            LeaveHistory::create([
                'user_id' => $request->user_id,
                'leave_type_id' => $deduction->leave_type_id,
                'leave_request_id' => $request->id,
                'entry_type' => 'reversal',
                'days' => $days,
                'balance_after' => $balance->balance,
                'remarks' => "Reversed {$request->reference_no}",
                'actor_id' => $actor?->id,
            ]);
        });
    }

    /** Manual HR adjustment with mandatory remark. */
    public function adjust(User $user, LeaveType $type, float $days, string $remarks, ?User $actor = null): LeaveBalance
    {
        return DB::transaction(function () use ($user, $type, $days, $remarks, $actor) {
            $balance = LeaveBalance::where('user_id', $user->id)
                ->where('leave_type_id', $type->id)->lockForUpdate()->first()
                ?? $this->balanceFor($user, $type);

            $newBalance = (float) $balance->balance + $days;
            if ($newBalance < 0) {
                // With the figures in it. "Would make the balance negative"
                // tells an officer their number is wrong but not what a right
                // one would be, so they go and look the balance up -- which is
                // the round trip the dialog exists to save.
                throw new RuntimeException(sprintf(
                    'cannot deduct %s days, only %s available.',
                    number_format(abs($days), 2),
                    number_format((float) $balance->balance, 2),
                ));
            }
            $balance->earned = (float) $balance->earned + max(0, $days);
            $balance->balance = $newBalance;
            $balance->save();

            LeaveHistory::create([
                'user_id' => $user->id,
                'leave_type_id' => $type->id,
                'entry_type' => 'adjustment',
                'days' => $days,
                'balance_after' => $balance->balance,
                'remarks' => $remarks,
                'actor_id' => $actor?->id,
            ]);

            return $balance;
        });
    }

    /** Monthly accrual for one user/type/period; idempotent via the period guard. */
    public function accrue(User $user, LeaveType $type, string $period, ?User $actor = null): bool
    {
        if (! $type->credit_source && ! in_array($type->code, ['VL', 'SL'], true)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($user, $type, $period, $actor) {
            $exists = LeaveHistory::where('user_id', $user->id)
                ->where('leave_type_id', $type->id)
                ->where('entry_type', 'accrual')
                ->where('period', $period)->exists();
            if ($exists) {
                return false;
            }

            $rate = $this->monthlyAccrualRate($type->code === 'SL' ? LeaveType::SOURCE_SICK : LeaveType::SOURCE_VACATION);
            $balance = $this->balanceFor($user, $type);
            $balance->earned = (float) $balance->earned + $rate;
            $balance->balance = (float) $balance->balance + $rate;
            $balance->last_accrued_period = $period;
            $balance->save();

            LeaveHistory::create([
                'user_id' => $user->id,
                'leave_type_id' => $type->id,
                'leave_request_id' => null,
                'entry_type' => 'accrual',
                'days' => $rate,
                'balance_after' => $balance->balance,
                'period' => $period,
                'remarks' => "Monthly accrual {$period}",
                'actor_id' => $actor?->id,
            ]);

            return true;
        });
    }
}
