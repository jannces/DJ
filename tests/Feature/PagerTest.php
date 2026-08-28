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
 * however long the list is.
 *
 * The three are a fixed block, not a window sliding around the current page:
 * 1 2 3, then 4 5 6, then 7 8 9. A sliding window renumbers itself on every
 * step — go from 3 to 4 and the row silently becomes 3 4 5 — so the same
 * position on screen means a different page each time you look.
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

    public function test_the_numbers_advance_a_block_at_a_time(): void
    {
        $this->seedPages();

        $this->assertSame(['1', '2', '3'], $this->numbersOn('/positions'));
        $this->assertSame(['4', '5', '6'], $this->numbersOn('/positions?page=4'));
        $this->assertSame(['7', '8', '9'], $this->numbersOn('/positions?page=7'));
        $this->assertSame(['28', '29'], $this->numbersOn('/positions?page=29'));
    }

    /**
     * The block holds still while you move inside it. Pages 1, 2 and 3 all
     * show the same three numbers — only which one is marked changes.
     */
    public function test_the_block_does_not_move_until_you_leave_it(): void
    {
        $this->seedPages();

        foreach ([1, 2, 3] as $page) {
            $this->assertSame(['1', '2', '3'], $this->numbersOn('/positions?page='.$page),
                'the numbers shifted while still inside the first block');
        }
        foreach ([4, 5, 6] as $page) {
            $this->assertSame(['4', '5', '6'], $this->numbersOn('/positions?page='.$page));
        }
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

    /** One click per block, so the numbers move by three rather than by one. */
    public function test_the_arrows_step_a_whole_block(): void
    {
        $this->seedPages();

        $html = $this->get('/positions?page=5')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#href="[^"]*page=1"[^>]*aria-label="Previous 3 pages"#', $html);
        $this->assertMatchesRegularExpression(
            '#href="[^"]*page=7"[^>]*aria-label="Next 3 pages"#', $html);

        $this->assertSame(['7', '8', '9'], $this->numbersOn('/positions?page=7'));
    }

    public function test_the_arrows_grey_out_at_each_end(): void
    {
        $this->seedPages();

        $first = $this->get('/positions')->assertOk()->getContent();
        preg_match('#<ul class="pagination">(.*?)</ul>#s', $first, $m);
        $this->assertStringContainsString('page-item disabled', $m[1]);
        $this->assertStringNotContainsString('Previous 3 pages', $m[1],
            'the first block offers a way back from itself');

        $last = $this->get('/positions?page=29')->assertOk()->getContent();
        preg_match('#<ul class="pagination">(.*?)</ul>#s', $last, $m);
        $this->assertStringNotContainsString('Next 3 pages', $m[1],
            'the last block offers a way on from itself');
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
