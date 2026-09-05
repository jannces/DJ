<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveStatusNotification;
use App\Support\NotificationUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notification links survive the server moving.
 *
 * They did not. `route()` returns an absolute url, so the address the system
 * answered on that day was written into the notification row and frozen there:
 *
 *     http://192.168.254.103:8000/leave/24
 *
 * The server then moved to another address and to HTTPS on 443 under a
 * hostname, and every notification created before that still pointed at a
 * machine and a port that no longer answer. Clicking one inside a perfectly
 * working system gave ERR_CONNECTION_REFUSED.
 *
 * A path is resolved by the browser against whatever host it is already on,
 * so it cannot go stale -- on this router, at the LGU, or on 127.0.0.1.
 */
class NotificationLinkTest extends TestCase
{
    use RefreshDatabase;

    /** Nothing absolute is written into a new notification. */
    public function test_a_stored_link_is_a_path_not_an_address(): void
    {
        $r = LeaveRequest::factory()->create();

        $data = (new LeaveStatusNotification($r, 'approved'))->toArray($r->user);

        $this->assertArrayHasKey('url', $data);
        $this->assertStringStartsWith('/', $data['url'],
            'the notification stored an absolute url, which freezes today\'s address into the row');
        $this->assertStringNotContainsString('://', $data['url']);
        $this->assertStringNotContainsString(':8000', $data['url']);
    }

    /**
     * Rows written before the fix still work.
     *
     * There are real ones in the database with the old address in them, and a
     * data migration would only fix the rows that exist today.
     */
    public function test_an_old_absolute_link_is_reduced_to_its_path(): void
    {
        $this->assertSame('/leave/24',
            NotificationUrl::path('http://192.168.254.103:8000/leave/24', '/fallback'));

        $this->assertSame('/leave/24',
            NotificationUrl::path('https://onealicialms.local/leave/24', '/fallback'));

        $this->assertSame('/security/intrusions?q=csrf#row-3',
            NotificationUrl::path('https://lms.alicia.local/security/intrusions?q=csrf#row-3', '/fallback'));
    }

    /** A path is left exactly as it is. */
    public function test_a_path_is_passed_through(): void
    {
        $this->assertSame('/leave/24', NotificationUrl::path('/leave/24', '/fallback'));
        $this->assertSame('/a/b?c=1', NotificationUrl::path('/a/b?c=1', '/fallback'));
    }

    /** Nothing usable means the notifications page, not a broken link. */
    public function test_an_empty_or_unusable_link_falls_back(): void
    {
        $this->assertSame('/fallback', NotificationUrl::path(null, '/fallback'));
        $this->assertSame('/fallback', NotificationUrl::path('', '/fallback'));
        $this->assertSame('/fallback', NotificationUrl::path('   ', '/fallback'));
        $this->assertSame('/fallback', NotificationUrl::path('javascript:alert(1)', '/fallback'));
        $this->assertSame('/fallback', NotificationUrl::path('mailto:a@b.c', '/fallback'));
    }

    /**
     * A protocol-relative link does not read as a path.
     *
     * "//evil.example/x" starts with a slash and is not relative at all -- the
     * browser would leave for that host. It has to be parsed like any other
     * absolute url, and only its path kept.
     */
    public function test_a_protocol_relative_link_does_not_escape(): void
    {
        $this->assertSame('/x', NotificationUrl::path('//evil.example/x', '/fallback'));
    }

    /** And the rendered bell uses it. */
    public function test_the_notification_bell_renders_a_relative_link(): void
    {
        $this->seedCore();
        $user = $this->makeUser('employee');
        session(['otp_verified' => true]);

        $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => LeaveStatusNotification::class,
            'data' => ['title' => 'Leave approved', 'url' => 'http://192.168.254.103:8000/leave/24'],
            'read_at' => null,
        ]);

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertStringNotContainsString('192.168.254.103:8000', $html,
            'the bell is still rendering the dead address stored in the row');
        $this->assertStringContainsString('href="/leave/24"', $html);
    }
}
