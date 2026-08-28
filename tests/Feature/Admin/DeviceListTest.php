<?php

namespace Tests\Feature\Admin;

use App\Models\AuthorizedDevice;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorized Devices, after the register form moved into a panel.
 *
 * The form used to hold the left third of the page and squeeze the list into
 * what was left. What matters about the move is the same thing that mattered
 * on the other lists: a rejected registration has to come back visible. This
 * page was the worst of them — it had no old() values and no error markup at
 * all, so registering a duplicate IP gave you a blank form and no reason why.
 */
class DeviceListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        // Enforcement off: this suite is about the page, and the allow-list
        // would otherwise turn every request from the test client into a 403.
        SystemSetting::set('security.device_enforcement', false);
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    private function device(array $attributes = []): AuthorizedDevice
    {
        return AuthorizedDevice::create($attributes + [
            'ip_address' => '192.168.1.10',
            'hostname' => 'HR-PC-01',
            'status' => 'active',
        ]);
    }

    public function test_the_list_has_the_page_and_the_form_is_behind_a_button(): void
    {
        $this->device();

        $html = $this->get('/devices')->assertOk()->getContent();

        $this->assertStringContainsString('Register device', $html);
        $this->assertStringContainsString('HR-PC-01', $html);

        // The button is inside the container, above the list it adds to.
        $this->assertMatchesRegularExpression(
            '#<div class="list-toolbar">\s*<a href="[^"]*/devices/create"#',
            $html,
            'the button is not in the toolbar at the top of the container'
        );

        preg_match('#<div class="modal fade" id="device-new"[^>]*>#s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('data-open-on-load', $m[0],
            'the panel is open before anybody asked for it');
    }

    public function test_a_duplicate_address_comes_back_with_the_reason_and_the_values(): void
    {
        $this->device(['ip_address' => '192.168.1.10']);

        $html = $this->from('/devices')
            ->followingRedirects()
            ->post('/devices', [
                'ip_address' => '192.168.1.10',
                'hostname' => 'HR-PC-02',
                'description' => 'Second desk',
            ])
            ->assertOk()->getContent();

        preg_match('#<div class="modal fade" id="device-new"[^>]*>#s', $html, $m);
        $this->assertStringContainsString('data-open-on-load', $m[0],
            'the error is behind a shut panel');

        $this->assertStringContainsString('is-invalid', $html, 'the failing field is not marked');
        $this->assertStringContainsString('HR-PC-02', $html, 'the typed hostname was thrown away');
        $this->assertStringContainsString('Second desk', $html, 'the typed description was thrown away');

        $this->assertSame(1, AuthorizedDevice::count());
    }

    public function test_the_button_is_a_link_that_works_without_the_script(): void
    {
        $html = $this->get('/devices')->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '#<a href="[^"]*/devices/create"[^>]*data-bs-toggle="modal"#',
            $html
        );

        preg_match('#<div class="modal fade" id="device-new"[^>]*>#s',
            $this->get('/devices/create')->assertOk()->getContent(), $m);
        $this->assertStringContainsString('data-open-on-load', $m[0]);
    }

    public function test_registering_still_works(): void
    {
        $this->post('/devices', [
            'ip_address' => '192.168.1.44',
            'hostname' => 'TREASURY-01',
        ])->assertRedirect();

        $this->assertDatabaseHas('authorized_devices', [
            'ip_address' => '192.168.1.44',
            'status' => 'active',
        ]);
    }

    /**
     * The controller could already filter to archived devices, but nothing in
     * the page linked to it — the only way to see an archived device was to
     * know to type ?archived=1.
     */
    public function test_archived_devices_are_reachable_from_the_page(): void
    {
        $this->device(['hostname' => 'ON-FLOOR']);
        $this->device([
            'ip_address' => '192.168.1.99',
            'hostname' => 'RETIRED-PC',
            'archived_at' => now(),
            'status' => 'inactive',
        ]);

        $plain = $this->get('/devices')->assertOk()->getContent();
        $this->assertStringNotContainsString('RETIRED-PC', $plain);
        $this->assertStringContainsString('archived=1', $plain, 'nothing links to the archived list');

        $withArchived = $this->get('/devices?archived=1')->assertOk()->getContent();
        $this->assertStringContainsString('RETIRED-PC', $withArchived);
        $this->assertStringContainsString('ON-FLOOR', $withArchived);
    }

    /** Archiving twice is meaningless, so the button is not offered twice. */
    public function test_an_archived_device_offers_no_archive_button(): void
    {
        $this->device(['hostname' => 'RETIRED-PC', 'archived_at' => now(), 'status' => 'inactive']);

        $html = $this->get('/devices?archived=1')->assertOk()->getContent();

        $this->assertStringNotContainsString('/archive', $html);
    }

    public function test_the_search_survives_switching_to_archived(): void
    {
        $html = $this->get('/devices?q=TREASURY')->assertOk()->getContent();

        $this->assertStringContainsString('q=TREASURY', $html,
            'switching to archived would drop what was searched for');
    }
}
