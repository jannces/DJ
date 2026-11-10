<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dates can be picked or typed.
 *
 * <input type="date"> hands its control to the operating system: Android opens
 * a calendar dialog, iOS a wheel, and neither can be made to offer a keyboard
 * -- inputmode is ignored on a date input. So the form carries both pairs of
 * inputs and a switch that reveals one, through CSS :has().
 *
 * Both pairs post, because a hidden input is still submitted. The switch is
 * what decides which pair is read, and anything other than "type" means the
 * calendar pair -- so a request that omits the switch behaves exactly as it
 * did before any of this existed.
 */
class DateEntryModeTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private LeaveType $vl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id,
            'department_id' => Department::factory()->create()->id,
        ]);

        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->vl->id,
            'earned' => 15, 'used' => 0, 'balance' => 15,
        ]);
    }

    private function asEmployee(): self
    {
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        return $this;
    }

    /** @param array<string,mixed> $dates */
    private function file(array $dates): \Illuminate\Testing\TestResponse
    {
        return $this->asEmployee()->post('/leave', array_merge([
            'leave_type_id' => [$this->vl->id],
            'date_filed' => now()->toDateString(),
            'commutation' => '0',
            'applicant_signature' => $this->employee->name,
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
        ], $dates));
    }

    private function monday(int $weeks = 1): string
    {
        return now()->addWeeks($weeks)->next('Monday')->toDateString();
    }

    // ------------------------------------------------------------- the switch

    /** Picked dates file, as they always have. */
    public function test_the_calendar_pair_files(): void
    {
        $day = $this->monday();

        $this->file(['date_mode' => 'calendar', 'start_date' => $day, 'end_date' => $day])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($day, LeaveRequest::firstOrFail()->start_date->toDateString());
    }

    /** Typed dates file too. */
    public function test_the_typed_pair_files(): void
    {
        $day = $this->monday();

        $this->file([
            'date_mode' => 'type',
            'start_date_typed' => $day,
            'end_date_typed' => $day,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($day, LeaveRequest::firstOrFail()->start_date->toDateString());
    }

    /**
     * A request with no switch at all still works.
     *
     * The printed-sheet path and every pre-existing test post the plain fields
     * and nothing else; "type" has to be asked for explicitly.
     */
    public function test_a_request_without_the_switch_reads_the_plain_fields(): void
    {
        $day = $this->monday();

        $this->file(['start_date' => $day, 'end_date' => $day])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($day, LeaveRequest::firstOrFail()->start_date->toDateString());
    }

    /**
     * The switch decides, not whichever box happens to be filled.
     *
     * Somebody types a date, switches back to Calendar and picks a different
     * one: both boxes now hold a value, and the one they can see must win.
     */
    public function test_the_switch_decides_when_both_pairs_are_filled(): void
    {
        $picked = $this->monday(1);
        $abandoned = $this->monday(3);

        $this->file([
            'date_mode' => 'calendar',
            'start_date' => $picked, 'end_date' => $picked,
            'start_date_typed' => $abandoned, 'end_date_typed' => $abandoned,
        ])->assertSessionHasNoErrors();

        $this->assertSame($picked, LeaveRequest::firstOrFail()->start_date->toDateString(),
            'the hidden typed box overrode the date the employee could see');
    }

    // ----------------------------------------------------- the typed format

    /**
     * A slashed date is refused, not guessed at.
     *
     * This is the whole reason the typed box asks for YYYY-MM-DD. PHP reads
     * "10/12/2026" as 12 October, so somebody meaning 10 December would file
     * two months early -- silently, on a form that deducts credits and is
     * signed by three officers.
     */
    public function test_a_slashed_date_is_refused(): void
    {
        $this->file([
            'date_mode' => 'type',
            'start_date_typed' => '10/12/2026',
            'end_date_typed' => '10/12/2026',
        ])->assertSessionHasErrors('start_date');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    /** And the message says the shape to use. */
    public function test_the_format_message_shows_the_shape(): void
    {
        $this->file([
            'date_mode' => 'type',
            'start_date_typed' => '13-10-2026',
            'end_date_typed' => '13-10-2026',
        ])->assertSessionHasErrors([
            'start_date' => 'Write the first day of leave as Year-Month-Day, for example 2026-10-13.',
        ]);
    }

    /** An empty typed box is refused like any missing date. */
    public function test_an_empty_typed_date_is_refused(): void
    {
        $this->file(['date_mode' => 'type', 'start_date_typed' => '', 'end_date_typed' => ''])
            ->assertSessionHasErrors('start_date');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    /** The end-before-start rule still applies to typed dates. */
    public function test_a_typed_end_before_the_start_is_refused(): void
    {
        $this->file([
            'date_mode' => 'type',
            'start_date_typed' => $this->monday(3),
            'end_date_typed' => $this->monday(1),
        ])->assertSessionHasErrors('end_date');
    }

    // ------------------------------------------------------------- the markup

    /** Both pairs and the switch are on the page. */
    public function test_the_form_offers_both_ways(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        $this->assertStringContainsString('name="date_mode" value="calendar"', $html);
        $this->assertStringContainsString('name="date_mode" value="type"', $html);
        $this->assertStringContainsString('name="start_date_typed"', $html);
        $this->assertStringContainsString('name="end_date_typed"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html,
            'the typed box does not ask for the numeric keyboard');
    }

    /**
     * Neither date input carries `required`.
     *
     * A required field that CSS has hidden makes a browser refuse to submit AND
     * refuse to say why -- "An invalid form control is not focusable" -- which
     * is a dead form with no message. The dates are still required; the server
     * says so, and the tests above prove it.
     */
    public function test_the_date_inputs_do_not_carry_required(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        foreach (['start_date', 'end_date'] as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/<input[^>]*name="'.$name.'"[^>]*\srequired/', $html,
                "{$name} carries required while CSS can hide it, which dead-ends the form");
        }
    }

    /** Calendar is the default, so the typed pair is the hidden one. */
    public function test_calendar_is_the_default(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="date_mode" value="calendar"[^>]*checked/', $html,
            'no mode is selected when the form opens');

        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('.lf-date-typed{ display:none; }', $css,
            'the typed pair is not hidden by default, so both show at once');
    }

    /** A browser without :has() cannot switch, so it is not offered the switch. */
    public function test_a_browser_without_has_keeps_the_calendar_only(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@supports not selector\(:has\(\*\)\)\{\s*\.lf-datemode\{ display:none; \}/', $css,
            'a browser that cannot switch is still shown a switch that does nothing');
    }

    /** And the iOS zoom fix is in, since it is what prompted all this. */
    public function test_inputs_are_16px_on_narrow_screens(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(max-width:767\.98px\)\{\s*\.form-control,\.form-select\{ font-size:1rem; \}/', $css,
            'inputs are under 16px on a phone, so iOS zooms the page in on every tap');
    }
}
