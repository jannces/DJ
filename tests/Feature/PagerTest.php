<?php

namespace Tests\Feature;

use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pager, which is one view shared by every list.
 *
 * Laravel's default spread the whole run of page numbers across the container:
 * on the intrusion log, 284 events made a row of 1 2 3 4 5 6 7 8 9 10 … 28 29
 * stretched edge to edge. Three numbers keep the row the same narrow width
 * however long the list is, and the arrows are the jumps the numbers cannot
 * make — the numbers either side of the current page are already prev and next.
 */
class PagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);
    }

    /** 29 pages of positions, so the window has somewhere to slide. */
    private function seedPages(int $count = 290): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['title' => sprintf('Position %03d', $i), 'salary_grade' => 'SG 1'];
        }
        Position::insert($rows);
    }

    /** @return array<int,string> the page numbers offered, in order */
    private function numbersOn(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match('#<ul class="pagination">(.*?)</ul>#s', $html, $m);
        $this->assertNotEmpty($m, 'there is no pager on '.$url);
        preg_match_all('#<(?:a|span) class="page-link"[^>]*>\s*(\d+)\s*<#', $m[1], $found);

        return $found[1];
    }

    public function test_only_three_numbers_are_offered_however_long_the_list_is(): void
    {
        $this->seedPages();

        $this->assertSame(['1', '2', '3'], $this->numbersOn('/positions'));
        $this->assertSame(['13', '14', '15'], $this->numbersOn('/positions?page=14'));
        $this->assertSame(['27', '28', '29'], $this->numbersOn('/positions?page=29'));
    }

    /** The current page is in the middle, so its neighbours are prev and next. */
    public function test_the_current_page_is_the_middle_one(): void
    {
        $this->seedPages();

        $html = $this->get('/positions?page=14')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<li class="page-item active" aria-current="page">\s*<span class="page-link">14</span>#',
            $html
        );
    }

    /**
     * With three numbers, the far end of a long list is otherwise 28 clicks
     * away. The arrows are what make the short window usable.
     */
    public function test_the_arrows_jump_to_the_first_and_last_page(): void
    {
        $this->seedPages();

        $html = $this->get('/positions?page=14')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#href="[^"]*page=1"[^>]*aria-label="First page"#', $html);
        $this->assertMatchesRegularExpression(
            '#href="[^"]*page=29"[^>]*aria-label="Last page"#', $html);
    }

    public function test_the_arrows_grey_out_at_each_end(): void
    {
        $this->seedPages();

        $first = $this->get('/positions')->assertOk()->getContent();
        preg_match('#<ul class="pagination">(.*?)</ul>#s', $first, $m);
        $this->assertStringContainsString('page-item disabled', $m[1]);
        $this->assertStringNotContainsString('aria-label="First page"', $m[1],
            'the first page offers a link to itself');

        $last = $this->get('/positions?page=29')->assertOk()->getContent();
        preg_match('#<ul class="pagination">(.*?)</ul>#s', $last, $m);
        $this->assertStringNotContainsString('aria-label="Last page"', $m[1],
            'the last page offers a link to itself');
    }

    public function test_the_count_reads_as_a_range_and_sits_under_the_numbers(): void
    {
        $this->seedPages();

        $html = $this->get('/positions?page=14')->assertOk()->getContent();

        $this->assertStringContainsString('Showing 131–140', $html);
        $this->assertStringContainsString('of 290 results', $html);

        // Under, not beside: the numbers come first in a column.
        $this->assertLessThan(
            strpos($html, 'pager-summary'),
            strpos($html, '<ul class="pagination">')
        );
    }

    /** A window wider than the list would show numbers that go nowhere. */
    public function test_a_short_list_shows_only_the_pages_it_has(): void
    {
        $this->seedPages(15);

        $this->assertSame(['1', '2'], $this->numbersOn('/positions'));
    }

    public function test_a_list_that_fits_on_one_page_shows_no_pager(): void
    {
        $this->seedPages(4);

        $this->assertStringNotContainsString('<ul class="pagination">',
            $this->get('/positions')->assertOk()->getContent());
    }

    /**
     * One view, so every list changed at once. If a page ever renders its own
     * pager instead, this is where it will be noticed.
     */
    public function test_every_list_uses_the_shared_pager(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/**/*.blade.php')) + glob(resource_path('views/*.blade.php')) as $file) {
            $html = file_get_contents($file);
            if (str_contains($html, 'class="pagination"') && ! str_contains($file, 'vendor/pagination')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'these hand-roll a pager instead of calling links()');
    }
}
