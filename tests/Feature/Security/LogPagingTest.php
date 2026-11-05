<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three logs page by cursor, not by offset.
 *
 * They are the one place in the system where rows arrive at the top of the
 * list while somebody is reading it — an attack in progress writes intrusion
 * events, and every request anybody makes writes an activity row. With OFFSET,
 * each arrival pushes the list down, so ?page=2 re-shows rows already read on
 * page 1 and skips others past the boundary entirely.
 *
 * On a security log being read through to review, silently skipping an event
 * is the outcome that matters.
 */
class LogPagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        SystemSetting::set('security.device_enforcement', false);
        SystemSetting::set('security.ids_enabled', false);
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    private function intrusion(int $n): IntrusionLog
    {
        return IntrusionLog::create([
            // route is a rendered column; payload_excerpt is not, so the
            // marker has to live somewhere the page actually prints.
            'category' => 'sqli', 'severity' => 'high', 'route' => 'event-'.$n,
            'method' => 'GET', 'payload_excerpt' => 'x',
            'matched_rule' => 'sqli_signature', 'ip' => '203.0.113.9',
        ]);
    }

    /**
     * The defect, reproduced end to end: read page one, let new events land,
     * then follow the link the page gave you. Nothing already read may repeat,
     * and nothing between the two pages may be lost.
     */
    public function test_new_events_arriving_do_not_shift_rows_between_pages(): void
    {
        for ($n = 1; $n <= 20; $n++) {
            $this->intrusion($n);
        }

        $first = $this->get('/security/intrusions')->assertOk()->getContent();
        preg_match('#href="([^"]*cursor=[^"]*)"[^>]*rel="next"#', $first, $m);
        $this->assertNotEmpty($m, 'the log offers no way to the next page');

        // Five more attacks while the first page is on screen.
        for ($n = 21; $n <= 25; $n++) {
            $this->intrusion($n);
        }

        $second = $this->get(html_entity_decode($m[1]))->assertOk()->getContent();

        $onFirst = $this->eventsIn($first);
        $onSecond = $this->eventsIn($second);

        $this->assertCount(10, $onFirst);
        $this->assertSame([], array_intersect($onFirst, $onSecond),
            'a row already read came back on the next page');

        // The ten below the first page are exactly what the second page holds.
        $this->assertSame(
            array_map(fn ($n) => 'event-'.$n, range(10, 1)),
            $onSecond,
            'rows were skipped between the two pages'
        );
    }

    /** @return array<int,string> */
    private function eventsIn(string $html): array
    {
        preg_match_all('/event-\d+/', $html, $found);

        return array_values(array_unique($found[0]));
    }

    public function test_each_log_pages_by_cursor(): void
    {
        for ($n = 1; $n <= 15; $n++) {
            $this->intrusion($n);
            AuditLog::create(['user_id' => null, 'action' => 'thing_'.$n, 'ip' => '127.0.0.1']);
            ActivityLog::create([
                'user_id' => null, 'method' => 'GET', 'path' => 'p'.$n,
                'route_name' => 'r', 'ip' => '127.0.0.1',
            ]);
        }

        foreach (['/security/intrusions', '/audit-logs', '/activity-logs'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('cursor=', $html, $url.' is still paging by offset');
            $this->assertStringContainsString('Older', $html, $url.' offers no way onward');
            // A cursor cannot count what it has not walked, so no page numbers.
            $this->assertDoesNotMatchRegularExpression('/[?&]page=\d/', $html,
                $url.' is offering numbered pages a cursor cannot honour');
        }
    }

    /** Filters have to survive the jump to the next page. */
    public function test_a_filter_is_carried_across_pages(): void
    {
        for ($n = 1; $n <= 15; $n++) {
            $this->intrusion($n);
        }
        $this->intrusion(99)->update(['category' => 'xss']);

        $html = $this->get('/security/intrusions?category=sqli')->assertOk()->getContent();
        preg_match('#href="([^"]*cursor=[^"]*)"[^>]*rel="next"#', $html, $m);

        $this->assertStringContainsString('category=sqli', html_entity_decode($m[1]),
            'the filter is dropped on the way to the next page');
    }

    /** The lists that do not move under the reader keep their numbers. */
    public function test_the_ordinary_lists_still_offer_page_numbers(): void
    {
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);

        for ($n = 1; $n <= 25; $n++) {
            \App\Models\Position::create(['title' => 'Position '.$n, 'salary_grade' => 'SG 1']);
        }

        $this->get('/positions')->assertOk()->assertSee('page=2', false);
    }
}
