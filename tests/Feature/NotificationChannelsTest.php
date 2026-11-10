<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Receipts and alarms are two channels, not one.
 *
 * "User updated." and "Intrusion alert: sqli from 203.0.113.9" shared a toast:
 * same corner, same 3.8-second timer. One confirms something you just did and
 * is harmless to miss, because the page behind it already shows the result.
 * The other says an attack is happening, and missing it means missing the
 * attack — on a system whose whole premise is real-time intrusion alerts.
 */
class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    private function script(): string
    {
        return file_get_contents(public_path('js/app.js'));
    }

    private function css(): string
    {
        return preg_replace('/\s+/', '', file_get_contents(public_path('css/app.css')));
    }

    // --------------------------------------------------------------- receipts

    /**
     * Top-right is where this application keeps the search box, the alert
     * bell, the theme toggle and the user menu, so a toast there covers the
     * controls. Bottom-right is empty on every page — and it is beside the
     * button that caused the message, since form actions sit below-right and
     * row actions are right-aligned in the last column.
     */
    public function test_a_receipt_appears_where_nothing_else_is(): void
    {
        $this->assertMatchesRegularExpression("/position: 'bottom-end'/", $this->script(),
            'the receipt is back on top of the topbar controls');
    }

    /**
     * A receipt goes away on its own, and how long it stays depends on what it
     * says.
     *
     * One fixed 3.8 seconds for everything was wrong at both ends: "User
     * updated." does not need it, because the list behind the toast already
     * shows the change, and a warning is a caveat somebody has to read and
     * weigh, which four seconds does not allow. Anything that must be
     * acknowledged has no timer at all -- that is lmsAlert, below.
     */
    public function test_how_long_a_receipt_stays_follows_what_it_says(): void
    {
        $js = $this->script();

        $this->assertMatchesRegularExpression(
            '/TOAST_MS = \{ success: 4000, info: 4000, warning: 7000 \}/', $js);
        $this->assertMatchesRegularExpression('/timer: TOAST_MS\[icon\]/', $js);
    }

    /**
     * And there is always a way out that does not depend on the timer.
     *
     * A timer is a guess about reading speed, and it is wrong for anybody who
     * looked away. The countdown also stops while the pointer is on the toast,
     * so reaching for the close button is not a race against it.
     */
    public function test_a_receipt_can_be_closed_and_pauses_while_read(): void
    {
        $js = $this->script();

        $this->assertStringContainsString('showCloseButton: true', $js);
        $this->assertStringContainsString("addEventListener('mouseenter', Swal.stopTimer)", $js);
        $this->assertStringContainsString("addEventListener('mouseleave', Swal.resumeTimer)", $js);
        $this->assertStringContainsString("addEventListener('focusin', Swal.stopTimer)", $js,
            'a keyboard user cannot pause the countdown');
    }

    /** The tone matches the event rather than being one colour for everything. */
    public function test_a_receipt_is_coloured_by_what_happened(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.lms-toast-success[^{]*\{[^}]*--k-good-f/', $css);
        $this->assertMatchesRegularExpression('/\.lms-toast-warning[^{]*\{[^}]*--k-warn-f/', $css);
        $this->assertMatchesRegularExpression('/\.lms-toast\.swal2-toast\{[^}]*background:var\(--surface\)/', $css,
            'the receipt is a white box on a dark application');
    }

    // ----------------------------------------------------------------- alarms

    public function test_an_alarm_appears_top_centre(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/#lms-alerts\{[^}]*position:fixed/', $css);
        $this->assertMatchesRegularExpression('/#lms-alerts\{[^}]*left:50%/', $css);
        $this->assertMatchesRegularExpression('/#lms-alerts\{[^}]*top:calc\(var\(--topbar-h\)/', $css,
            'the alarm sits under the topbar rather than below it');
    }

    /** The one thing that must not happen to an alarm. */
    public function test_an_alarm_does_not_disappear_on_its_own(): void
    {
        $script = $this->script();

        $alert = substr($script, strpos($script, 'window.lmsAlert'),
            strpos($script, 'var CONFIRM_TONES') - strpos($script, 'window.lmsAlert'));

        $this->assertStringNotContainsString('timer', $alert,
            'an intrusion alert can vanish before anybody reads it');
        $this->assertStringContainsString('lms-alert-close', $alert,
            'there is no way to dismiss it either');
    }

    /**
     * A routine receipt must not close an alarm that has not been read.
     * SweetAlert2 shows one thing at a time, which is why these are not it.
     */
    public function test_an_alarm_is_not_the_same_mechanism_as_a_receipt(): void
    {
        $script = $this->script();

        $alert = substr($script, strpos($script, 'window.lmsAlert'),
            strpos($script, 'var CONFIRM_TONES') - strpos($script, 'window.lmsAlert'));

        $this->assertStringNotContainsString('Swal.fire', $alert,
            'a "User updated." would close an unread intrusion alert');
        $this->assertStringContainsString('host.appendChild', $alert,
            'alarms replace one another instead of stacking');
    }

    /** Act now, against look at it when you can. */
    public function test_an_alarm_is_coloured_by_the_severity_recorded(): void
    {
        $css = $this->css();
        $script = $this->script();

        $this->assertMatchesRegularExpression('/\.lms-alert-high\{[^}]*--k-bad-f/', $css);
        $this->assertMatchesRegularExpression('/\.lms-alert-medium\{[^}]*--k-warn-f/', $css);

        // From the record, not decided here: a rule graded critical later
        // paints itself without another change in this file.
        $this->assertStringContainsString("data.latest.severity === 'medium'", $script);
    }

    /** An address with no way to see what it did is a dead end. */
    public function test_an_intrusion_alarm_links_to_its_events(): void
    {
        $this->seedCore();
        SystemSetting::set('security.device_enforcement', false);
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/users')->assertOk()->getContent();

        $this->assertStringContainsString('data-log-url="'.route('security.intrusions').'?q="', $html,
            'the bell cannot tell an alert where the events are');
        $this->assertStringContainsString('bell.dataset.logUrl', $this->script());
    }

    // ------------------------------------------------------------- the split

    /**
     * A refusal says something did not happen. It used to be a receipt, and
     * disappeared in under four seconds.
     */
    public function test_a_refused_action_is_an_alarm_not_a_receipt(): void
    {
        $script = $this->script();

        $this->assertMatchesRegularExpression(
            '/flash\.dataset\.error\) lmsAlert\(/', $script,
            'a refusal still fades away before it can be read');
        $this->assertMatchesRegularExpression(
            '/flash\.dataset\.success\) lmsToast\(/', $script,
            'a confirmation was promoted to an alarm, which spends the signal');
    }

    /** Nothing routine may use the channel that means "attend to this". */
    public function test_only_an_intrusion_or_a_refusal_raises_an_alarm(): void
    {
        // Two call sites and no more: the intrusion the bell found, and an
        // action the server refused. The definition itself reads
        // `window.lmsAlert = function`, so it is not one of these.
        preg_match_all('/(?<!window\.)lmsAlert\(/', $this->script(), $calls);

        $this->assertCount(2, $calls[0],
            'lmsAlert is called somewhere new — top-centre only means something while it stays rare');
    }
}
