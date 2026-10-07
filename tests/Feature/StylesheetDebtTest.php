<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * No declaration in the stylesheet is written and then overwritten.
 *
 * The sheet had 299 of them. They were the sediment of three redesigns: the
 * original violet gradient sidebar was still there in full, overridden by the
 * white one further down; the dark palette was written out three times, each
 * complete, and only the last one had any effect; buttons, badges and the
 * navigation each carried two or three earlier versions of themselves.
 *
 * None of it was visible, which is exactly why it survived. It cost every
 * reader of this file the work of deciding, for each rule, whether it was the
 * one that wins -- and that question has bitten this project more than once:
 * a table density change that did nothing because four rules set the same
 * padding, and a media query that made a page worse because it was anchored
 * above the selector it meant to override.
 *
 * A declaration is dead here when the SAME selector, in the SAME at-rule
 * context, sets the SAME property again later in the file. Same selector means
 * same specificity, so the later one always wins and the earlier value can
 * never apply to anything.
 *
 * Overrides ACROSS contexts are not dead and are not reported: a media query
 * restating a property is the entire point of a media query.
 */
class StylesheetDebtTest extends TestCase
{
    public function test_no_declaration_is_overwritten_by_a_later_one(): void
    {
        $raw = file_get_contents(public_path('css/app.css'));

        // Blank comments rather than strip them, so every byte offset -- and
        // so every reported line number -- still matches the real file.
        $css = preg_replace_callback('/\/\*.*?\*\//s',
            fn ($m) => str_repeat(' ', strlen($m[0])), $raw);

        $rules = $this->parse($css);

        $seen = [];
        foreach ($rules as [$context, $selector, $decls]) {
            foreach ($decls as [$prop, $value, $offset]) {
                $seen[$context."\0".$selector."\0".$prop][] = [$value, $offset];
            }
        }

        $offenders = [];
        foreach ($seen as $key => $occurrences) {
            if (count($occurrences) < 2) {
                continue;
            }

            [$context, $selector, $prop] = explode("\0", $key);
            $winner = end($occurrences)[0];

            foreach (array_slice($occurrences, 0, -1) as [$value, $offset]) {
                $line = substr_count(substr($raw, 0, $offset), "\n") + 1;
                $where = $context === '' ? '' : ' inside '.$context;
                $offenders[] = sprintf('  line %-5d %s%s { %s: %s }  — never applies; "%s" wins later',
                    $line, $selector, $where, $prop, $value, $winner);
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, sprintf(
            "%d declaration(s) are overwritten by a later one on the same selector:\n%s\n\n".
            "Each is dead: same selector means same specificity, so the later rule always wins.\n".
            "Delete the earlier declaration, or -- if the earlier one is the one you want --\n".
            "delete the later one instead. Do not leave both.",
            count($offenders), implode("\n", array_slice($offenders, 0, 40))));
    }

    /**
     * Parse rules with their at-rule context, matching braces properly.
     *
     * The first version of this analysis tracked nesting by peeking at whether
     * the next character was a closing brace, which never popped correctly: it
     * placed every rule in the second half of the file inside `@media print`.
     * A "same context" claim built on that is worthless, so depth is counted
     * here and an at-rule is popped when depth returns to where it opened.
     *
     * @return list<array{0:string,1:string,2:list<array{0:string,1:string,2:int}>}>
     */
    private function parse(string $css): array
    {
        $rules = [];
        $stack = [];          // [prelude, depth at open]
        $depth = 0;
        $segment = 0;
        $i = 0;
        $n = strlen($css);

        while ($i < $n) {
            $c = $css[$i];

            if ($c === '{') {
                $prelude = trim(preg_replace('/\s+/', ' ', substr($css, $segment, $i - $segment)));

                if (str_starts_with($prelude, '@') && ! str_starts_with($prelude, '@font-face')) {
                    $stack[] = [$prelude, $depth];
                    $depth++;
                    $segment = ++$i;

                    continue;
                }

                // A declaration block: find its matching close.
                $d = 1;
                $j = $i + 1;
                while ($j < $n && $d > 0) {
                    if ($css[$j] === '{') {
                        $d++;
                    } elseif ($css[$j] === '}') {
                        $d--;
                    }
                    $j++;
                }

                $bodyStart = $i + 1;
                $decls = [];
                $pos = $bodyStart;

                foreach (explode(';', substr($css, $bodyStart, $j - 1 - $bodyStart)) as $part) {
                    $start = $pos;
                    $pos += strlen($part) + 1;

                    if (! str_contains($part, ':')) {
                        continue;
                    }

                    [$p, $v] = explode(':', $part, 2);
                    $p = trim($p);

                    if ($p === '' || str_starts_with($p, '@')) {
                        continue;
                    }

                    $decls[] = [$p, trim($v), $start];
                }

                // @font-face is not a selector: four faces each declaring src
                // are four faces, not one rule written four times.
                if ($prelude !== '' && $decls && ! str_starts_with($prelude, '@font-face')) {
                    $context = implode(' ', array_column($stack, 0));
                    $rules[] = [$context, $prelude, $decls];
                }

                $i = $j;
                $segment = $i;

                continue;
            }

            if ($c === '}') {
                $depth--;
                while ($stack && end($stack)[1] === $depth) {
                    array_pop($stack);
                }
                $segment = ++$i;

                continue;
            }

            $i++;
        }

        return $rules;
    }

    /** And nothing is left as an empty shell once its declarations are gone. */
    public function test_no_rule_is_left_empty(): void
    {
        $css = preg_replace('/\/\*.*?\*\//s', ' ', file_get_contents(public_path('css/app.css')));

        preg_match_all('/([^{}]{1,120})\{\s*\}/', $css, $matches, PREG_SET_ORDER);

        $empty = array_map(
            fn ($m) => '  '.trim(preg_replace('/\s+/', ' ', $m[1])),
            $matches);

        $this->assertSame([], $empty,
            "Rules with nothing in them:\n".implode("\n", $empty)
            ."\n\nAn empty rule is a selector that says nothing. Delete it, or give it "
            ."the declaration it was meant to carry.");
    }
}
