<?php

namespace Tests\Feature;

use App\Support\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Stylesheets and scripts carry the file's timestamp.
 *
 * They were linked bare, so a browser that had loaded the system once kept
 * serving its cached copy: every CSS change reached nobody until they thought
 * to hard-refresh, and on a LAN install nobody thinks to. This is the reason a
 * layout change could look like it had not been applied at all.
 */
class AssetVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_url_changes_when_the_file_does(): void
    {
        $first = Asset::url('css/app.css');
        $this->assertMatchesRegularExpression('#/css/app\.css\?v=\d+$#', $first);

        touch(public_path('css/app.css'), time() + 60);
        Cache::flush();

        $this->assertNotSame($first, Asset::url('css/app.css'),
            'the browser would go on serving the copy it already had');
    }

    /** A path that is not there must not produce a broken '?v=' tail. */
    public function test_a_missing_file_still_gives_a_usable_url(): void
    {
        $this->assertStringEndsWith('/css/not-here.css', Asset::url('css/not-here.css'));
    }

    public function test_both_layouts_link_versioned_assets(): void
    {
        $this->seedCore();

        $guest = $this->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*css/app\.css\?v=\d+"#', $guest);

        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);
        $app = $this->get('/positions')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#href="[^"]*css/app\.css\?v=\d+"#', $app);
        $this->assertMatchesRegularExpression('#src="[^"]*js/app\.js\?v=\d+"#', $app);
    }
}
