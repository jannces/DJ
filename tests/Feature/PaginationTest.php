<?php

namespace Tests\Feature;

use App\Models\AuthorizedDevice;
use App\Models\Position;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every list pages at the same size.
 *
 * It used to be per controller — 12 on one screen, 15 on the next, 30 on the
 * logs — so the same office machine did a different amount of scrolling
 * depending which page you were on. The size lives in config/lists.php now,
 * and nothing should hard-code its own.
 */
class PaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_size_is_ten(): void
    {
        $this->assertSame(10, config('lists.per_page'));
    }

    /**
     * The rule is only worth anything if it is the only rule. A literal here
     * is how the sizes drifted apart in the first place.
     */
    public function test_no_controller_hard_codes_its_own_page_size(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path()) as $file) {
            if (preg_match_all('/paginate\(\s*(\d+)/', file_get_contents($file), $m)) {
                $offenders[] = basename($file).': paginate('.implode(', ', $m[1]).')';
            }
        }

        $this->assertSame([], $offenders,
            'these set their own page size instead of reading config(\'lists.per_page\')');
    }

    public function test_a_list_longer_than_a_page_is_cut_at_ten_and_offers_the_next(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);

        for ($i = 1; $i <= 12; $i++) {
            Position::create(['title' => sprintf('Administrative Aide %02d', $i), 'salary_grade' => 'SG 1']);
        }

        $first = $this->get('/positions')->assertOk();
        $first->assertSee('Administrative Aide 10');
        $first->assertDontSee('Administrative Aide 11');
        $first->assertSee('page=2', false);

        $this->get('/positions?page=2')->assertOk()->assertSee('Administrative Aide 11');
    }

    /**
     * An employee's older requests were capped at thirty and simply
     * unreachable past that; there was no second page to go to.
     */
    public function test_the_devices_list_pages_too(): void
    {
        $this->seedCore();
        SystemSetting::set('security.device_enforcement', false);
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        for ($i = 1; $i <= 12; $i++) {
            AuthorizedDevice::create([
                'ip_address' => '192.168.1.'.$i,
                'hostname' => sprintf('PC-%02d', $i),
                'status' => 'active',
            ]);
        }

        $this->get('/devices')->assertOk()->assertSee('page=2', false);
    }

    /** @return iterable<string> */
    private function phpFilesIn(string $dir): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
