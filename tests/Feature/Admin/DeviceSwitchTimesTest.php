<?php

namespace Tests\Feature\Admin;

use App\Models\AuthorizedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When a device was last switched on and last switched off.
 *
 * audit_logs already records every activation and deactivation and remains the
 * record of truth -- append-only, and holding the whole history. These two
 * columns are the latest of each, shown on the device list so "since when?"
 * does not require opening the trail.
 *
 * The one thing that can silently break them is $fillable: leave the two names
 * out of it and Eloquent drops them on every update without raising anything,
 * so the columns simply stay empty forever. Several of these tests exist to
 * catch exactly that.
 */
class DeviceSwitchTimesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): \App\Models\User
    {
        $this->seedCore();
        $user = $this->makeUser('system-admin');
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    /** Registering a device is an activation. */
    public function test_registering_stamps_the_activation_time(): void
    {
        $this->admin();

        $this->post(route('devices.store'), [
            'ip_address' => '192.168.254.30',
            'hostname' => 'HR-PC-01',
        ])->assertRedirect();

        $device = AuthorizedDevice::where('ip_address', '192.168.254.30')->first();

        $this->assertNotNull($device, 'the device was not registered');
        $this->assertNotNull($device->activated_at,
            'a device registered active has no activation time -- check $fillable');
        $this->assertNull($device->deactivated_at,
            'a brand new device is recorded as having been switched off');
    }

    /** Switching it off stamps the other column, and leaves the first alone. */
    public function test_deactivating_stamps_the_deactivation_time(): void
    {
        $this->admin();

        $device = AuthorizedDevice::create([
            'ip_address' => '192.168.254.31',
            'hostname' => 'HR-PC-02',
            'status' => 'active',
            'activated_at' => now()->subDays(3),
        ]);

        $activated = $device->activated_at;

        $this->post(route('devices.toggle', $device))->assertRedirect();
        $device->refresh();

        $this->assertSame('inactive', $device->status);
        $this->assertNotNull($device->deactivated_at, 'no deactivation time was recorded');
        $this->assertTrue($activated->equalTo($device->activated_at),
            'switching a device off rewrote when it was switched on');
    }

    /**
     * And switching it back on updates only the activation time.
     *
     * The earlier deactivation has to survive, or the pair stops reading as
     * "last on / last off" and becomes whichever happened most recently.
     */
    public function test_reactivating_keeps_the_previous_deactivation_time(): void
    {
        $this->admin();

        $device = AuthorizedDevice::create([
            'ip_address' => '192.168.254.32',
            'hostname' => 'HR-PC-03',
            'status' => 'inactive',
            'activated_at' => now()->subDays(5),
            'deactivated_at' => now()->subDays(2),
        ]);

        $deactivated = $device->deactivated_at;

        $this->post(route('devices.toggle', $device))->assertRedirect();
        $device->refresh();

        $this->assertSame('active', $device->status);
        $this->assertTrue($device->activated_at->gt($deactivated),
            'the activation time was not moved forward');
        $this->assertTrue($deactivated->equalTo($device->deactivated_at),
            'switching a device on erased when it was last switched off');
    }

    /**
     * Archiving counts as switching off.
     *
     * The active() scope excludes archived rows, so an archived device stops
     * being served immediately. Recording nothing would show it as never
     * having been switched off.
     */
    public function test_archiving_stamps_the_deactivation_time(): void
    {
        $this->admin();

        $device = AuthorizedDevice::create([
            'ip_address' => '192.168.254.33',
            'hostname' => 'HR-PC-04',
            'status' => 'active',
            'activated_at' => now()->subDay(),
        ]);

        $this->post(route('devices.archive', $device))->assertRedirect();
        $device->refresh();

        $this->assertNotNull($device->archived_at);
        $this->assertNotNull($device->deactivated_at,
            'an archived device shows as never having been switched off');
    }

    /** Both times appear on the list. */
    public function test_the_list_shows_both_times(): void
    {
        $this->admin();

        AuthorizedDevice::create([
            'ip_address' => '192.168.254.34',
            'hostname' => 'HR-PC-05',
            'status' => 'inactive',
            'activated_at' => \Illuminate\Support\Carbon::parse('2026-09-01 08:30:00'),
            'deactivated_at' => \Illuminate\Support\Carbon::parse('2026-09-20 17:05:00'),
        ]);

        $this->get(route('devices.index'))
            ->assertOk()
            ->assertSee('01 Sep 2026, 8:30 am')
            ->assertSee('20 Sep 2026, 5:05 pm');
    }
}
