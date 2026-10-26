<?php

namespace Tests\Feature\Admin;

use App\Models\AuthorizedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A device cannot be registered without its MAC address.
 *
 * Enforced on the server, not only by the `required` attribute on the input:
 * that is a convenience the browser offers, and a POST straight to this route
 * never sees it.
 */
class DeviceMacRequiredTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    public function test_registering_without_a_mac_address_is_refused(): void
    {
        $this->admin();

        $this->post(route('devices.store'), [
            'ip_address' => '192.168.254.40',
            'hostname' => 'HR-PC-01',
        ])->assertSessionHasErrors('mac_address');

        $this->assertDatabaseMissing('authorized_devices', ['ip_address' => '192.168.254.40']);
    }

    public function test_a_device_with_a_mac_address_still_registers(): void
    {
        $this->admin();

        $this->post(route('devices.store'), [
            'ip_address' => '192.168.254.41',
            'hostname' => 'HR-PC-02',
            'mac_address' => '00:1A:2B:3C:4D:5E',
        ])->assertSessionHasNoErrors();

        $this->assertSame('00:1A:2B:3C:4D:5E',
            AuthorizedDevice::where('ip_address', '192.168.254.41')->value('mac_address'));
    }

    /** And the form marks it, the way every other required field is marked. */
    public function test_the_field_is_marked_required_in_the_form(): void
    {
        $this->admin();

        $this->get(route('devices.create'))
            ->assertOk()
            ->assertSee('MAC address <span class="req">*</span>', false);
    }
}
