<?php

namespace Tests\Feature\Security;

use App\Models\AuthorizedDevice;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_it_flags_a_secure_only_session_cookie_when_serving_over_http(): void
    {
        config(['session.secure' => true]);

        $this->artisan('lms:lan')
            ->expectsOutputToContain('SESSION_SECURE_COOKIE=true')
            ->assertExitCode(1);
    }

    public function test_it_passes_the_cookie_check_over_plain_http_when_not_secure_only(): void
    {
        config(['session.secure' => false]);

        $this->artisan('lms:lan')
            ->doesntExpectOutputToContain('SESSION_SECURE_COOKIE=true')
            ->run();
    }

    public function test_it_wants_a_secure_cookie_for_an_https_deployment(): void
    {
        config(['session.secure' => false, 'app.url' => 'https://lms.alicia.local']);

        $this->artisan('lms:lan', ['--https' => true])
            ->expectsOutputToContain('SESSION_SECURE_COOKIE is false')
            ->run();
    }

    public function test_it_prints_the_bind_all_interfaces_serve_command(): void
    {
        $this->artisan('lms:lan', ['--port' => 8080])
            ->expectsOutputToContain('php artisan serve --host=0.0.0.0 --port=8080')
            ->run();
    }

    public function test_port_80_points_at_apache_rather_than_the_dev_server(): void
    {
        $this->artisan('lms:lan', ['--port' => 80])
            ->doesntExpectOutputToContain('artisan serve')
            ->expectsOutputToContain('apache-vhost-ip.conf')
            ->run();
    }

    public function test_it_fails_when_enforcement_is_on_with_only_loopback_authorized(): void
    {
        SystemSetting::set('security.device_enforcement', true);
        AuthorizedDevice::query()->where('ip_address', '!=', '127.0.0.1')->delete();

        $this->artisan('lms:lan')
            ->expectsOutputToContain('only loopback is authorized')
            ->assertExitCode(1);
    }

    public function test_it_lists_registered_workstations_when_enforcement_is_on(): void
    {
        SystemSetting::set('security.device_enforcement', true);
        AuthorizedDevice::create([
            'ip_address' => '192.168.1.25',
            'hostname' => 'HR-Laptop',
            'status' => 'active',
        ]);

        $this->artisan('lms:lan')
            ->expectsOutputToContain('192.168.1.25')
            ->expectsOutputToContain('HR-Laptop')
            ->run();
    }

    public function test_it_warns_when_app_url_is_a_hostname_the_lan_cannot_resolve(): void
    {
        config(['session.secure' => false, 'app.url' => 'https://lms.alicia.local']);

        $this->artisan('lms:lan')
            ->expectsOutputToContain('lms.alicia.local')
            ->run();
    }
}
