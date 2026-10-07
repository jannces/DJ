<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * No text in this application is smaller than 12px.
 *
 * This test exists because raising the floor once was not enough. Sixty-one
 * declarations were lifted to 12px in an earlier commit, and within a few
 * hours of building on top of that I had put ten new ones back -- a 9.9px
 * badge, three 11.2px timestamps, six 11.5px meta lines -- across the thread
 * list, the column key and the notification inbox. Nothing complained,
 * because nothing was watching.
 *
 * A rule that lives only in a commit message is a rule that lasts until the
 * next feature.
 */
class TypeFloorTest extends TestCase
{
    /** The smallest text a person should be asked to read here. */
    private const FLOOR_PX = 12.0;

    /**
     * The selector a declaration belongs to.
     *
     * Walked backwards from the match to the opening brace and then to the end
     * of the rule before it, because a line-level check would miss anything
     * written across several lines -- which is most of this sheet.
     */
    private function ownerOf(string $css, int $offset): string
    {
        $brace = strrpos(substr($css, 0, $offset), '{');
        if ($brace === false) {
            return '';
        }

        $start = strcspn(strrev(substr($css, 0, $brace)), '}{;');

        return trim(substr($css, $brace - $start, $start));
    }

    public function test_no_declaration_sets_text_below_the_floor(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $lines = explode("\n", $css);

        $offenders = [];

        // rem first, which is how nearly all of this sheet is written
        preg_match_all('/font-size:\s*(\.\d+|\d+\.?\d*)rem/', $css, $rem, PREG_OFFSET_CAPTURE);
        foreach ($rem[1] as [$value, $offset]) {
            $px = (float) $value * 16;
            if ($px < self::FLOOR_PX && ! str_contains($this->ownerOf($css, $offset), '.csc-')) {
                $line = substr_count(substr($css, 0, $offset), "\n") + 1;
                $offenders[] = sprintf('  line %-5d %-22s = %.2fpx', $line, trim($lines[$line - 1]), $px);
            }
        }

        // ...and the handful written in px
        preg_match_all('/font-size:\s*(\d+\.?\d*)px/', $css, $px, PREG_OFFSET_CAPTURE);
        foreach ($px[1] as [$value, $offset]) {
            if ((float) $value < self::FLOOR_PX && ! str_contains($this->ownerOf($css, $offset), '.csc-')) {
                $line = substr_count(substr($css, 0, $offset), "\n") + 1;
                $offenders[] = sprintf('  line %-5d %-22s = %spx', $line, trim($lines[$line - 1]), $value);
            }
        }

        $this->assertSame([], $offenders,
            "Text below the ".self::FLOOR_PX."px floor:\n".implode("\n", $offenders)
            ."\n\nA label that has to be small is usually a label that has to be shorter.");
    }

    /**
     * The CSC form is deliberately exempt and stays that way.
     *
     * It is a facsimile of a printed government sheet at a fixed size, and its
     * type is measured in points against paper rather than in rem against a
     * screen. Excluding it silently would be indistinguishable from missing it,
     * so the exemption is written down here.
     */
    public function test_the_printed_form_is_measured_in_print_units(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.csc-sheet[^{]*\{[^}]*font-size:\s*\d+(\.\d+)?(px|pt)/s', $css,
            'the CSC facsimile no longer sizes its type in print units, so it is '
            .'now subject to the screen floor above and this exemption is stale');
    }
}
