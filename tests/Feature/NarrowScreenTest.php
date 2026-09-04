<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a leave list does on a phone.
 *
 * Six columns wanted 597px inside a 351px container. Nothing was hidden and
 * nothing overflowed the page, so no test caught it -- the table simply wrapped
 * every cell until it fit: a reference number broken across three lines, rows
 * 245px tall, and Status pushed off the right edge with nothing on screen to
 * say it was there.
 *
 * The two lists people actually open on a phone became stacked cards. The rest
 * are desk work and keep the table, with an honest horizontal scroll.
 */
class NarrowScreenTest extends TestCase
{
    use RefreshDatabase;

    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = file_get_contents(public_path('css/app.css'));
        $this->seedCore();
    }

    /**
     * The lists that stack, by URL.
     *
     * All Leave Requests is NOT among them any more: it renders as a thread
     * list, which is three flexible parts in a row and reflows on its own, so
     * it needs no second layout underneath it.
     */
    private function stackedLists(): array
    {
        return ['/leave' => 'employee'];
    }

    private function seedRequest(): void
    {
        $office = Department::create(['name' => 'Municipal Treasurers Office', 'code' => 'MTO']);
        $employee = $this->makeUser('employee');
        $employee->update(['name' => 'Josh Kirby B. Bote']);
        EmployeeProfile::factory()->create([
            'user_id' => $employee->id, 'employee_no' => 'EMP-0001',
            'department_id' => $office->id, 'position_id' => Position::factory()->create()->id,
        ]);
        LeaveRequest::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->firstOrFail()->id,
            'status' => 'pending', 'working_days' => 3,
            'date_filed' => now()->subDays(5)->toDateString(),
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);
    }

    private function open(string $url, string $role): string
    {
        $user = $role === 'employee'
            ? User::whereHas('employeeProfile')->firstOrFail()
            : $this->makeUser($role);

        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $this->get($url)->assertOk()->getContent();
    }

    /**
     * Every cell in a stacking table names itself.
     *
     * Once the header row is off-screen the label has to come from somewhere,
     * and it comes from data-label. A cell without one renders a value with no
     * indication of what it is -- which is worse than the table was.
     */
    public function test_every_cell_in_a_stacked_list_carries_its_label(): void
    {
        $this->seedRequest();

        foreach ($this->stackedLists() as $url => $role) {
            $html = $this->open($url, $role);

            $this->assertStringContainsString('table-stack', $html, "$url does not stack");

            preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body);
            $this->assertNotEmpty($body, "$url has no rows to check");

            preg_match_all('/<td\b([^>]*)>/', $body[1], $cells);
            $unlabelled = array_values(array_filter(
                $cells[1],
                fn ($attrs) => ! str_contains($attrs, 'data-label')
                    // the action cell has nothing to label and says so in CSS
                    && ! str_contains($attrs, 'text-end')
                    && ! str_contains($attrs, 'colspan')
            ));

            $this->assertSame([], $unlabelled,
                "$url has a cell with no data-label, so it stacks as a value with no name:\n"
                .implode("\n", $unlabelled));
        }
    }

    /**
     * The header is hidden from the eye, not from the accessibility tree.
     *
     * display:none would take the column names away from a screen reader too,
     * and the cells still depend on them.
     */
    public function test_the_hidden_header_is_still_announced(): void
    {
        preg_match('/\.table-stack thead\{([^}]*)\}/s', $this->css, $m);
        $this->assertNotEmpty($m, 'the stacked header has no rule');

        $this->assertStringContainsString('clip-path', $m[1]);
        $this->assertStringNotContainsString('display:none', $m[1],
            'the column names are gone from screen readers as well as from the screen');
        $this->assertStringNotContainsString('visibility:hidden', $m[1]);
    }

    /**
     * The stacking rules are behind a media query.
     *
     * All of this is wrong on a desktop, where the table is the right shape.
     */
    public function test_none_of_it_applies_on_a_wide_screen(): void
    {
        $position = strpos($this->css, '.table-stack thead{');
        $this->assertNotFalse($position);

        $before = substr($this->css, 0, $position);
        $this->assertStringContainsString('@media (max-width:640px){',
            substr($before, -3000),
            'the stacked layout is not inside a narrow-screen media query');
    }

    /**
     * A table that keeps scrolling does not wrap instead.
     *
     * Wrapping is what made the old one ugly: it is the browser's way of
     * avoiding a scrollbar, and it costs more than the scrollbar does.
     */
    public function test_the_tables_that_still_scroll_do_not_wrap(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.table-responsive:not\(\.table-stack-wrap\)[^{]*\{\s*white-space:nowrap/s', $this->css,
            'a scrolling table is still allowed to wrap its cells');
    }

    /**
     * The toolbar's controls agree on a width once they stack.
     *
     * Three controls sized by their own content read as a mistake in one
     * column: a full-width search over two half-width dropdowns is a grid.
     */
    public function test_the_filters_line_up_when_they_stack(): void
    {
        $narrow = $this->narrowBlock();

        $this->assertMatchesRegularExpression(
            '/\.list-toolbar \.toolbar-filters\{[^}]*grid-template-columns:1fr 1fr/s', $narrow);
        $this->assertMatchesRegularExpression(
            '/\.toolbar-select\{[^}]*width:100%/s', $narrow,
            'the dropdowns still size themselves by their own text');
    }

    /**
     * Everything that applies at 640px and below.
     *
     * Concatenated, because the stylesheet has SIX separate
     * `@media (max-width:640px)` blocks -- reading only the first would test a
     * rule about the security grid. Counted by brace rather than matched by
     * regex, since each block contains nested rules and a non-greedy `.*?}`
     * stops at the first one inside.
     */
    private function narrowBlock(): string
    {
        $needle = '@media (max-width:640px){';
        $bodies = [];

        for ($at = 0; ($open = strpos($this->css, $needle, $at)) !== false;) {
            $i = $open + strlen($needle) - 1;
            $depth = 0;
            for ($j = $i; $j < strlen($this->css); $j++) {
                $depth += ($this->css[$j] === '{') - ($this->css[$j] === '}');
                if ($depth === 0) {
                    $bodies[] = substr($this->css, $i + 1, $j - $i - 1);
                    break;
                }
            }
            $at = $open + strlen($needle);
        }

        $this->assertNotEmpty($bodies, 'there is no narrow-screen media query');

        return implode("\n", $bodies);
    }
}
