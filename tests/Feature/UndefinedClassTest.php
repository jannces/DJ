<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every class a view uses is defined in a stylesheet somewhere.
 *
 * This exists because the My Signature page shipped without one. The view was
 * written against .sig-layout, .sig-box, .sig-preview, .sig-form, .sig-cap,
 * .sig-meta and .sig-note, and not one of those rules was ever added -- so the
 * page rendered as bare stacked blocks, with no frame around the one image it
 * exists to show. Nothing failed. Nothing could: a class with no rule behind
 * it is not an error in HTML, it is just a word.
 *
 * The check is only possible because this project self-hosts its dependencies.
 * Bootstrap and Bootstrap Icons are on disk under public/vendor, so "defined"
 * can mean "defined in one of the three stylesheets the page actually loads",
 * and there is no need for a hand-kept list of framework class names that
 * would go stale the first time anyone used a utility nobody had used before.
 */
class UndefinedClassTest extends TestCase
{
    /**
     * Class names that are deliberately not styled.
     *
     * Every entry needs a reason. A hook that JavaScript reads, or a wrapper
     * that exists to group markup, is legitimately styleless -- an undefined
     * class nobody meant to leave undefined is what this test is looking for,
     * and the difference between the two is intent, which only a person can
     * record.
     */
    private const STYLELESS = [
        // app.js rewrites this element's className to swap sun for moon; the
        // class is how it finds the icon again afterwards.
        'theme-icon',
        // Groups the text beside a timeline dot. The rail and the spacing are
        // on .tl-item, so the wrapper needs nothing of its own.
        'tl-body',
    ];

    public function test_no_view_uses_a_class_that_no_stylesheet_defines(): void
    {
        $defined = $this->definedClasses();
        $offenders = [];

        foreach ($this->views() as $view => $classes) {
            foreach ($classes as $class) {
                if (! in_array($class, self::STYLELESS, true) && ! isset($defined[$class])) {
                    $offenders[] = "  {$class}  —  {$view}";
                }
            }
        }

        sort($offenders);

        $this->assertSame([], array_unique($offenders), "\n".
            count($offenders)." class(es) are used in a view but defined in no stylesheet:\n".
            implode("\n", array_unique($offenders))."\n\n".
            "Either add the rule, delete the class from the markup, or -- if it is a\n".
            "JavaScript hook or a bare grouping wrapper -- add it to STYLELESS in this\n".
            "test with a line saying why.\n");
    }

    /** Every class name mentioned in any stylesheet the app actually loads. */
    private function definedClasses(): array
    {
        $css = '';

        foreach ([
            public_path('css/app.css'),
            public_path('vendor/bootstrap/bootstrap.min.css'),
            public_path('vendor/bootstrap-icons/bootstrap-icons.min.css'),
        ] as $file) {
            if (is_file($file)) {
                $css .= file_get_contents($file);
            }
        }

        $this->assertNotSame('', $css, 'no stylesheet was found to check against');

        preg_match_all('/\.(-?[A-Za-z_][\w-]*)/', $css, $matches);

        return array_fill_keys($matches[1], true);
    }

    /**
     * The class names each Blade view puts in a class attribute.
     *
     * Views carrying their own <style> block are skipped: the two PDF
     * templates and the API docs page are self-contained documents whose
     * classes are defined inside them, not in the application stylesheet.
     *
     * @return array<string,list<string>>
     */
    private function views(): array
    {
        $found = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, '<style')) {
                continue;
            }

            $view = str_replace(base_path().'/', '', $file);

            preg_match_all('/class="([^"]*)"|class=\'([^\']*)\'/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $chunk = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

                // Blade expressions and directives inside the attribute build
                // class names at render time; what is left after removing them
                // is the literal part.
                $chunk = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $chunk);
                $chunk = preg_replace('/@\w+\(.*?\)/s', ' ', (string) $chunk);

                foreach (preg_split('/\s+/', (string) $chunk, -1, PREG_SPLIT_NO_EMPTY) as $token) {
                    // A whole class name. "bg-" is the stump of bg-{{ $tone }}
                    // and names nothing on its own.
                    if (preg_match('/^[A-Za-z_][\w-]*\w$/', $token)) {
                        $found[$view][] = $token;
                    }
                }
            }
        }

        $this->assertNotEmpty($found, 'no Blade views were scanned, so this test proves nothing');

        return array_map('array_unique', $found);
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dir as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
