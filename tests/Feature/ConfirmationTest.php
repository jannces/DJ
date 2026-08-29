<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anything that blocks, archives, removes or resets asks first.
 *
 * Several did not. Blocking a user account, deactivating a device, activating
 * or deactivating an account and restoring an archived one all went through on
 * the click — and blocking an account is one of the more consequential things
 * an administrator can do here, since the person cannot sign in afterwards.
 */
class ConfirmationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes whose form must carry a confirmation, and the view it lives in.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function destructiveActions(): array
    {
        return [
            'block a user' => ['admin/users/index', 'users.block'],
            'unblock a user' => ['admin/users/index', 'users.unblock'],
            'activate or deactivate a user' => ['admin/users/index', 'users.toggle-active'],
            'reset a password' => ['admin/users/index', 'users.reset-password'],
            'archive a user' => ['admin/users/index', 'users.archive'],
            'restore a user' => ['admin/users/index', 'users.restore'],
            'deactivate a device' => ['admin/devices/index', 'devices.toggle'],
            'archive a device' => ['admin/devices/index', 'devices.archive'],
            'lift an ip block' => ['admin/security/blocked-ips', 'security.unblock-ip'],
            'block an ip again' => ['admin/security/blocked-ips', 'security.reblock-ip'],
            'remove a holiday' => ['hr/holidays', 'holidays.destroy'],
            'cancel a leave request' => ['leave/show', 'leave.cancel'],
        ];
    }

    /**
     * The attributes of the form that posts to a route.
     *
     * Read by finding the route and walking back to its <form, then forward to
     * the @csrf that follows. Matching the opening tag with a regex does not
     * work here: `route('users.restore', $user->id)` contains a `>`, so any
     * pattern that stops at the first one truncates the tag halfway.
     */
    private function formAttributes(string $view, string $route): string
    {
        $source = file_get_contents(resource_path('views/'.$view.'.blade.php'));

        $at = strpos($source, $route);
        $this->assertNotFalse($at, $view.' has no form posting to '.$route);

        $opens = strrpos(substr($source, 0, $at), '<form');
        $this->assertNotFalse($opens, $route.' is not inside a form');

        $ends = strpos($source, '@csrf', $at);

        return substr($source, $opens, $ends - $opens);
    }

    /**
     * @dataProvider destructiveActions
     */
    public function test_it_asks_before_doing_it(string $view, string $route): void
    {
        $this->assertStringContainsString('data-confirm', $this->formAttributes($view, $route),
            $route.' goes through on the click, with nothing asked');
    }

    /**
     * The listener watches the document, not each form.
     *
     * Binding per form at load left every row action unguarded the moment the
     * live filter replaced the list: the rows came back from the server with
     * data-confirm still on them and nothing listening, so Lift block, Block
     * again, Archive and the rest went through silently after any search or
     * dropdown. The earlier test read the Blade source, so it proved the
     * attribute was written and never that anything was watching for it.
     */
    public function test_the_confirmation_survives_the_list_being_replaced(): void
    {
        $script = file_get_contents(public_path('js/app.js'));

        $this->assertStringContainsString("document.addEventListener('submit'", $script,
            'confirmations are not delegated, so replaced rows lose them');
        $this->assertStringNotContainsString("querySelectorAll('form[data-confirm]')", $script,
            'forms are still bound one by one at load');
        $this->assertStringNotContainsString("querySelectorAll('form:not([data-no-loader])", $script,
            'the page loader is still bound one by one at load');
    }

    /**
     * Row actions live inside the region the filter swaps, which is exactly
     * why the binding had to move. If one ever sits outside it that is fine —
     * this records which lists depend on the delegation.
     */
    public function test_the_row_actions_are_inside_the_part_that_gets_replaced(): void
    {
        foreach ([
            'admin/security/blocked-ips' => 'security.unblock-ip',
            'admin/users/index' => 'users.archive',
            'admin/devices/index' => 'devices.toggle',
        ] as $view => $route) {
            $source = file_get_contents(resource_path('views/'.$view.'.blade.php'));

            $this->assertGreaterThan(
                strpos($source, '<div data-list>'),
                strpos($source, $route),
                $route.' is outside the swapped region, so this test is stale'
            );
        }
    }

    /**
     * The question has to name what it will do. "Are you sure?" over a row of
     * identical buttons tells you nothing about which one you pressed.
     */
    public function test_the_question_says_what_will_happen(): void
    {
        $vague = [];

        foreach (glob(resource_path('views/**/*.blade.php')) as $file) {
            preg_match_all('#data-confirm="([^"]*)"#', file_get_contents($file), $found);
            foreach ($found[1] as $text) {
                if (strlen(strip_tags($text)) < 15 || ! str_contains($text, '?')) {
                    $vague[] = basename($file).': '.$text;
                }
            }
        }

        $this->assertSame([], $vague, 'these ask a question that does not say what it will do');
    }

    /**
     * Yes and No, in those words. "Yes, proceed" against "Cancel" is two
     * different kinds of answer to one question.
     */
    public function test_the_dialog_is_answerable_yes_or_no(): void
    {
        $script = file_get_contents(public_path('js/app.js'));

        $this->assertStringContainsString("confirmButtonText: 'Yes'", $script);
        $this->assertStringContainsString("cancelButtonText: 'No'", $script);
    }

    /**
     * The question floats over the page, and the page behind it is blurred.
     *
     * A dim leaves the table underneath legible, and a question about blocking
     * the wrong account should be the only thing on screen worth reading. It
     * also has to look like the system's own panels: the dialog is
     * SweetAlert2, which arrived wearing its own light theme — a white box in
     * the middle of a dark application.
     */
    public function test_the_question_floats_over_a_blurred_page(): void
    {
        $script = file_get_contents(public_path('js/app.js'));
        $css = preg_replace('/\s+/', '', file_get_contents(public_path('css/app.css')));

        $this->assertStringContainsString("popup: 'lms-ask'", $script,
            'the confirmation is not carrying the system panel styling');

        $this->assertMatchesRegularExpression('/\.lms-ask-bg\{[^}]*backdrop-filter:blur/', $css,
            'the page behind the question is not blurred');
        $this->assertMatchesRegularExpression('/\.lms-ask\{[^}]*background:var\(--surface\)/', $css,
            'the question does not use the system surface, so it will not match the theme');

        // The record panels dim instead. A confirmation is a stop and nothing
        // behind it should be readable; a form panel is work in progress, and
        // the list behind it is often what you are copying from.
        $this->assertDoesNotMatchRegularExpression(
            '/\.modal-backdrop\.show\{[^}]*backdrop-filter:blur/', $css,
            'the form panels blur away the list somebody may be reading from');
    }

    /**
     * Blur where you must not look past it, dim where you might still need to.
     *
     * Blur only means "stop" while it is rare. The page loader used to blur on
     * every ordinary navigation, which spent the signal on nothing — so the
     * confirmation is the only overlay allowed to use it.
     */
    public function test_only_a_decision_blurs_the_page(): void
    {
        $css = preg_replace('/\s+/', '', file_get_contents(public_path('css/app.css')));

        // Overlays that cover the page: what is behind them is dimmed only.
        foreach ([
            '#page-loader' => 'the page is leaving; nothing is being asked',
            '.sidebar-backdrop' => 'a menu is a choice you can walk away from',
            '.modal-backdrop.show' => 'a form panel is work in progress',
        ] as $selector => $why) {
            preg_match('/'.preg_quote($selector, '/').'\{([^}]*)\}/', $css, $m);
            $this->assertNotEmpty($m, $selector.' is gone from the stylesheet');
            $this->assertStringNotContainsString('backdrop-filter:blur', $m[1],
                $selector.' blurs, but '.$why);
        }

        $this->assertMatchesRegularExpression('/\.lms-ask-bg\{[^}]*backdrop-filter:blur/', $css,
            'the one overlay that should blur does not');
    }

    /**
     * Blocking an account is recorded against a reason, and that is asked for
     * inside the confirmation. It used to be a browser prompt — a stop that
     * does not blur, does not follow the theme, and appeared BEFORE the
     * confirmation, because an inline onsubmit binds before the listener does.
     */
    public function test_the_block_reason_is_asked_inside_the_confirmation(): void
    {
        $form = $this->formAttributes('admin/users/index', 'users.block');

        $this->assertStringContainsString('data-confirm-input="Reason for blocking"', $form);
        $this->assertStringContainsString('data-confirm-field="reason"', $form);
        $this->assertStringNotContainsString('prompt(', $form,
            'the reason is still asked by a browser prompt');

        $script = file_get_contents(public_path('js/app.js'));
        $this->assertStringContainsString('inputValidator', $script,
            'a block can be recorded with no reason against it');
    }

    /** No view anywhere reaches for a browser dialog. */
    public function test_nothing_uses_a_raw_browser_dialog(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/**/*.blade.php')) as $file) {
            $source = file_get_contents($file);
            if (preg_match('/\b(prompt|confirm|alert)\s*\(/', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders,
            'these ask with a browser dialog, which does not blur or follow the theme');
    }

    /** The toasts are the same library and must not be dressed as a panel. */
    public function test_the_toasts_are_left_alone(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        preg_match_all('/^\s*\.swal2-[a-z-]+\s*\{/m', $css, $unscoped);

        $this->assertSame([], $unscoped[0],
            'this styles SweetAlert2 globally, which catches the toasts as well');
    }

    /**
     * The Yes button is coloured after what it does, so the answer confirms
     * the button that was pressed rather than a generic proceed.
     */
    public function test_letting_someone_back_in_is_not_coloured_like_shutting_them_out(): void
    {
        $script = file_get_contents(public_path('js/app.js'));
        $this->assertMatchesRegularExpression('/CONFIRM_TONES\s*=/', $script);
        $this->assertStringContainsString('confirmButtonColor: tone', $script);

        $this->assertStringContainsString('data-confirm-tone="success"',
            $this->formAttributes('admin/users/index', 'users.unblock'),
            'unblocking a person is confirmed in the colour of shutting them out');
        $this->assertStringContainsString('data-confirm-tone="success"',
            $this->formAttributes('admin/security/blocked-ips', 'security.unblock-ip'));
        $this->assertStringContainsString('data-confirm-tone="danger"',
            $this->formAttributes('admin/security/blocked-ips', 'security.reblock-ip'));
    }
}
