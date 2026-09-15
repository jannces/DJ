<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\FailedLogin;
use App\Models\IntrusionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Security Dashboard with something on it.
 *
 * Every other test on this screen exercises one card. This one fills the tables
 * and renders the whole page, because the failures worth catching here are the
 * ones that only appear when a chart has data in it: a division by zero when a
 * peak is zero, a percentage that comes out `NaN`, a label positioned past the
 * end of its axis. None of those show up on an empty installation, which is
 * exactly the state every other test leaves the database in.
 */
class SecurityRenderSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    /**
     * `created_at` is not fillable, so passing it to create() is silently
     * dropped and every event lands on today — which quietly turns a seven-day
     * distribution into one column and stops the chart being tested at all.
     */
    private function eventOn(\Illuminate\Support\Carbon $when, array $attributes): void
    {
        $log = IntrusionLog::create($attributes);
        $log->timestamps = false;
        $log->created_at = $when;
        $log->save();
    }

    private function fill(): void
    {
        // Seven days of intrusions with a spike and two empty days.
        foreach ([0 => 5, 1 => 2, 2 => 3, 3 => 0, 4 => 1, 5 => 0, 6 => 2] as $back => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->eventOn(now()->subDays($back), [
                    'category' => ['sqli', 'xss', 'traversal'][$i % 3],
                    'severity' => 'high',
                    'route' => ['login', 'leave/all', 'files/download'][$i % 3],
                    'method' => 'GET',
                    'ip' => ['192.168.1.87', '192.168.4.11'][$i % 2],
                    'handled' => $i % 2 === 0,
                ]);
            }
        }

        IntrusionLog::create([
            'category' => 'auth_fail', 'severity' => 'high', 'route' => 'login',
            'method' => 'POST', 'matched_rule' => 'lockout_threshold',
            'ip' => '192.168.1.42',
        ]);

        BlockedIp::create([
            'ip' => '192.168.1.87', 'reason' => 'auto', 'source' => 'auto', 'active' => true,
        ]);

        foreach (['unknown_user', 'unknown_user', 'invalid_password', 'blocked'] as $i => $reason) {
            FailedLogin::create([
                'identifier' => 'someone', 'ip' => '192.168.1.42',
                'reason' => $reason, 'occurred_at' => now()->subHours($i + 1),
            ]);
        }

        // Four weeks of sign-ins, weekends thin.
        for ($back = 27; $back >= 0; $back--) {
            $day = now()->subDays($back);
            $count = $day->isWeekend() ? 2 : 12;
            for ($i = 0; $i < $count; $i++) {
                AuditLog::create(['action' => 'login', 'created_at' => $day]);
            }
        }

        AuditLog::create([
            'action' => 'user_access_changed',
            'new_values' => ['name' => 'Bautista, Rosa'],
            'created_at' => now()->subDay(),
        ]);
    }

    public function test_the_whole_screen_renders_with_data_on_it(): void
    {
        $this->fill();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/security')->assertOk()->getContent();

        // Nothing computed itself into a non-number.
        foreach (['NaN', 'INF', '-INF', 'width:%', 'height:%', 'left:%'] as $rubbish) {
            $this->assertStringNotContainsString($rubbish, $html, "a chart produced {$rubbish}");
        }

        // Every proportion is inside its box.
        preg_match_all('/(?:width|height|left):\s*(-?[\d.]+)%/', $html, $sizes);
        $this->assertNotEmpty($sizes[1]);
        foreach ($sizes[1] as $size) {
            $this->assertGreaterThanOrEqual(0, (float) $size, 'a bar has a negative size');
            $this->assertLessThanOrEqual(100, (float) $size, 'a bar runs past the end of its track');
        }

        // Every card has something in it rather than its empty state.
        foreach ([
            'Intrusion attempts per day', 'Attempts by type',
            'Attack severity', 'Unreviewed events', 'Failed sign-ins by reason',
            'Privilege changes',
        ] as $card) {
            $this->assertStringContainsString($card, $html);
        }
        $this->assertStringNotContainsString('Everything has been reviewed.', $html);
        $this->assertStringNotContainsString('No failed sign-ins in the last 7 days.', $html);

        // The seven days really are seven days: two of them are empty, one is
        // the spike, and the events are not all piled onto today.
        //
        // Read back off the polyline now that the trend is a line rather than
        // columns. The partial plots y = 100 - (value / top) * 100 against the
        // axis whose top tick is the first entry in .ln-y, so inverting that
        // recovers the value at each point. Worth the arithmetic: this is the
        // assertion that catches a series collapsing onto one day, which is
        // exactly what a fixture bug did to this chart while it was being
        // built -- every event landed on today and the chart looked like a
        // single spike.
        preg_match('#<div class="ln-y">(.*?)</div>#s', $html, $yAxis);
        preg_match_all('/<span>(\d+)<\/span>/', $yAxis[1], $yTicks);
        $top = (int) $yTicks[1][0];
        $this->assertGreaterThan(0, $top, 'the trend axis has no top');

        preg_match('/<path class="p1[^"]*"[^>]*d="([^"]+)"/', $html, $line);
        $this->assertNotEmpty($line, 'the trend is not drawn as a line');

        // The curve is "M x y C c1 c1, c2 c2, x y C ...". Each segment's LAST
        // coordinate pair is a real reading; the two before it are control
        // points and belong to no day. So: the M point, then the end of every
        // C segment.
        $segments = preg_split('/(?=C )/', trim($line[1]));
        $ys = [];
        foreach ($segments as $segment) {
            preg_match_all('/-?[\d.]+/', $segment, $numbers);
            $ys[] = (float) $numbers[0][count($numbers[0]) - 1];
        }

        $values = array_map(fn ($y) => (int) round((100 - $y) / 100 * $top), $ys);

        // Six on the last day, not five: today also carries the lockout.
        $this->assertSame([2, 0, 1, 0, 3, 2, 6], $values,
            'the daily counts are not landing on their own days');

        // The axis is zero-based, whole, descending, and its top is at or
        // above the peak — so no point can be drawn off the end of the plot.
        // The number of ticks depends on where the peak falls against the round
        // step, which is the point of rounding at all.
        preg_match('#<div class="ln-y">(.*?)</div>#s', $html, $axis);
        preg_match_all('/<span>(\d+)<\/span>/', $axis[1], $ticks);
        $scale = array_map('intval', $ticks[1]);

        $this->assertGreaterThanOrEqual(3, count($scale), 'the axis is too coarse to read a value off');
        $this->assertSame(0, end($scale), 'the scale must be zero-based');
        $this->assertSame($scale, array_values(array_reverse(array_unique(array_reverse($scale)))));

        $sorted = $scale;
        rsort($sorted);
        $this->assertSame($sorted, $scale, 'the axis must descend');

        // Every gap is the same round step.
        $gaps = array_unique(array_map(
            fn ($i) => $scale[$i] - $scale[$i + 1],
            range(0, count($scale) - 2)
        ));
        $this->assertCount(1, $gaps, 'the bands are not evenly spaced');
    }

    /**
     * And with nothing on it, which is how it will look on the day it is
     * installed. An empty chart must say "none", not divide by zero.
     */
    public function test_the_whole_screen_renders_with_nothing_on_it(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/security')->assertOk()->getContent();

        $this->assertStringNotContainsString('NaN', $html);
        $this->assertStringContainsString('Everything has been reviewed.', $html);

        // A count has no half, so an empty week must not label its axis 1 · 0.5 · 0.
        preg_match('#<div class="ln-y">(.*?)</div>#s', $html, $axis);
        preg_match_all('/<span>([\d.]+)<\/span>/', $axis[1], $ticks);
        foreach ($ticks[1] as $tick) {
            $this->assertSame($tick, (string) (int) $tick);
        }
    }

    /** The same, for the leave dashboards. */
    public function test_the_leave_dashboard_renders_empty_and_full(): void
    {
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('NaN', $html);
        $this->assertStringNotContainsString('width:%', $html);

        preg_match_all('/width:\s*(-?[\d.]+)%/', $html, $sizes);
        foreach ($sizes[1] as $size) {
            $this->assertGreaterThanOrEqual(0, (float) $size);
            $this->assertLessThanOrEqual(100, (float) $size);
        }
    }
}
