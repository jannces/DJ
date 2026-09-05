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
 * stretched edge to edge.
 *
 * It truncates now to the first page, the last page, the current one and its
 * immediate neighbours, with an ellipsis for each gap — the same narrow row at
 * three pages or three hundred, and both ends one click away.
 *
 * An earlier version showed a fixed block of three, 1 2 3 then 4 5 6. With the
 * ends never shown, the arrows had to step a whole block to reach them, so on
 * a list of three pages or fewer there was no next block and BOTH arrows were
 * dead on every page — including page one with page two sitting beside it.
 * Most lists here are under thirty rows, so that was most lists.
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

    /** 29 pages of positions by default, so the window has room to move. */
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

    /**
     * First, last, the current page and its immediate neighbours, with an
     * ellipsis for each gap. The row is the same narrow width at three pages
     * or three hundred, and both ends stay one click away.
     */
    public function test_it_truncates_to_the_ends_and_the_neighbours(): void
    {
        $this->seedPages();

        $this->assertSame(['1', '2', '29'], $this->numbersOn('/positions'));
        $this->assertSame(['1', '13', '14', '15', '29'], $this->numbersOn('/positions?page=14'));
        $this->assertSame(['1', '28', '29'], $this->numbersOn('/positions?page=29'));
    }

    /** A gap of exactly one page prints that page: "1 … 3" hides a page for nothing. */
    public function test_a_gap_of_one_page_is_the_page_rather_than_an_ellipsis(): void
    {
        $this->seedPages();

        // Page 4 of 29: the gap between 1 and 3 is a single page, so it is
        // printed rather than hidden behind an ellipsis.
        $this->assertSame(['1', '2', '3', '4', '5', '29'], $this->numbersOn('/positions?page=4'));

        // Four pages, on the first: 1 2 [gap of one] 4 becomes the whole run.
        Position::query()->delete();
        $this->seedPages(35);
        $this->assertSame(['1', '2', '3', '4'], $this->numbersOn('/positions'));
        $this->assertStringNotContainsString('page-gap',
            $this->explode($this->get('/positions')->getContent()),
            'an ellipsis stands where a single page would fit');
    }

    public function test_the_gaps_are_marked(): void
    {
        $this->seedPages();

        $this->assertSame(2, substr_count($this->explode($this->get('/positions?page=14')->getContent()), 'page-gap'),
            'the two gaps either side of the middle are not shown as gaps');
    }

    public function test_the_page_you_are_on_is_the_one_marked(): void
    {
        $this->seedPages();

        $html = $this->get('/positions?page=5')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<li class="page-item active" aria-current="page">\s*<span class="page-link">5</span>#',
            $html
        );
    }

    /**
     * The arrows step one page and are disabled only at the true ends.
     *
     * An earlier version stepped a whole block of three. With the ends never
     * shown, that was the only way to reach them — and on a list of three
     * pages or fewer there was no next block, so BOTH arrows were dead on
     * every page, including page one with page two sitting beside it.
     */
    public function test_the_arrows_step_one_page(): void
    {
        $this->seedPages();

        $html = $this->get('/positions?page=5')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#href="[^"]*page=4"[^>]*rel="prev"#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*page=6"[^>]*rel="next"#', $html);
    }

    /** The regression: a short list must still offer its next page. */
    public function test_a_three_page_list_can_still_be_paged_through(): void
    {
        $this->seedPages(25);

        $first = $this->explode($this->get('/positions')->getContent());
        $this->assertStringContainsString('rel="next"', $first,
            'page two is unreachable on a three-page list');
        $this->assertStringNotContainsString('rel="prev"', $first);

        $middle = $this->explode($this->get('/positions?page=2')->getContent());
        $this->assertStringContainsString('rel="next"', $middle);
        $this->assertStringContainsString('rel="prev"', $middle);

        $last = $this->explode($this->get('/positions?page=3')->getContent());
        $this->assertStringNotContainsString('rel="next"', $last);
        $this->assertStringContainsString('rel="prev"', $last);
    }

    public function test_the_arrows_grey_out_at_each_end(): void
    {
        $this->seedPages();

        $this->assertStringContainsString('page-item disabled',
            $this->explode($this->get('/positions')->getContent()));
        $this->assertStringContainsString('page-item disabled',
            $this->explode($this->get('/positions?page=29')->getContent()));
    }

    /** The pagination list on its own, without the rest of the page. */
    private function explode(string $html): string
    {
        preg_match('#<ul class="pagination">(.*?)</ul>#s', $html, $m);

        return $m[1] ?? '';
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
