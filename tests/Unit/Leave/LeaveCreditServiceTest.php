<?php

namespace Tests\Unit\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LeaveCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_monthly_accrual_is_1_25_and_idempotent(): void
    {
        $user = User::factory()->create();
        $vl = LeaveType::where('code', 'VL')->first();
        $credits = app(LeaveCreditService::class);

        $this->assertTrue($credits->accrue($user, $vl, '2026-07'));
        $this->assertFalse($credits->accrue($user, $vl, '2026-07')); // same period → no double credit

        $balance = LeaveBalance::where('user_id', $user->id)->where('leave_type_id', $vl->id)->first();
        $this->assertEquals(1.25, (float) $balance->balance);
        $this->assertDatabaseCount('leave_history', 1);
    }

    public function test_deduction_reduces_source_balance_and_writes_ledger(): void
    {
        $user = User::factory()->create();
        $vl = LeaveType::where('code', 'VL')->first();
        LeaveBalance::create(['user_id' => $user->id, 'leave_type_id' => $vl->id, 'earned' => 10, 'used' => 0, 'balance' => 10]);

        $request = LeaveRequest::factory()->create([
            'user_id' => $user->id, 'leave_type_id' => $vl->id, 'working_days' => 3,
        ]);

        app(LeaveCreditService::class)->deductForApproval($request);

        $balance = LeaveBalance::where('user_id', $user->id)->where('leave_type_id', $vl->id)->first();
        $this->assertEquals(7, (float) $balance->balance);
        $this->assertEquals(3, (float) $balance->used);
        $this->assertDatabaseHas('leave_history', ['leave_request_id' => $request->id, 'entry_type' => 'deduction']);
    }

    public function test_it_never_allows_a_negative_balance(): void
    {
        $user = User::factory()->create();
        $vl = LeaveType::where('code', 'VL')->first();
        LeaveBalance::create(['user_id' => $user->id, 'leave_type_id' => $vl->id, 'earned' => 2, 'used' => 0, 'balance' => 2]);

        $request = LeaveRequest::factory()->create([
            'user_id' => $user->id, 'leave_type_id' => $vl->id, 'working_days' => 5,
        ]);

        $this->expectException(RuntimeException::class);
        app(LeaveCreditService::class)->deductForApproval($request);
    }
}
