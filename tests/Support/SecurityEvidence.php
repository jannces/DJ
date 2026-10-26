<?php

namespace Tests\Support;

/**
 * Collects evidence from the security test matrix and persists it outside the
 * in-memory test database (which RefreshDatabase wipes between tests).
 *
 * Everything it writes lands under storage/app/security-evidence/:
 *   - runs.jsonl     one line per scenario trial (the 50-iteration ledger)
 *   - samples/*.json representative database rows captured from each scenario
 *   - summary.json   per-scenario pass/fail tallies, written at the end
 *
 * The HTML report and screenshots are built from these files by
 * scripts/security-evidence-report.php — nothing here renders images itself.
 */
class SecurityEvidence
{
    public static function dir(): string
    {
        $dir = storage_path('app/security-evidence');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
            mkdir($dir.'/samples', 0775, true);
        }
        if (! is_dir($dir.'/samples')) {
            mkdir($dir.'/samples', 0775, true);
        }

        return $dir;
    }

    /** Wipe prior evidence so a run starts from a clean ledger. */
    public static function reset(): void
    {
        $dir = self::dir();
        foreach (glob($dir.'/*.jsonl') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($dir.'/*.json') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($dir.'/samples/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Append one trial result to the ledger. */
    public static function trial(string $scenario, int $iteration, bool $pass, array $detail = []): void
    {
        $line = json_encode([
            'scenario' => $scenario,
            'iteration' => $iteration,
            'pass' => $pass,
            'at' => now()->toIso8601String(),
            'detail' => $detail,
        ], JSON_UNESCAPED_SLASHES);

        file_put_contents(self::dir().'/runs.jsonl', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** Save representative database rows for a scenario as proof of what was logged. */
    public static function sample(string $scenario, array $rows): void
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($scenario));
        file_put_contents(
            self::dir().'/samples/'.$slug.'.json',
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Fold the ledger into per-scenario tallies and write summary.json.
     * Called once at the end of the matrix run.
     */
    public static function writeSummary(array $meta = []): array
    {
        $dir = self::dir();
        $ledger = $dir.'/runs.jsonl';
        $scenarios = [];

        if (is_file($ledger)) {
            foreach (file($ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $row) {
                $r = json_decode($row, true);
                if (! is_array($r)) {
                    continue;
                }
                $name = $r['scenario'];
                $scenarios[$name] ??= ['scenario' => $name, 'iterations' => 0, 'passed' => 0, 'failed' => 0];
                $scenarios[$name]['iterations']++;
                $scenarios[$name][$r['pass'] ? 'passed' : 'failed']++;
            }
        }

        foreach ($scenarios as &$s) {
            $s['result'] = $s['failed'] === 0 && $s['iterations'] > 0 ? 'PASS' : 'FAIL';
        }
        unset($s);

        $summary = [
            'generated_at' => now()->toIso8601String(),
            'meta' => $meta,
            'scenarios' => array_values($scenarios),
            'overall' => [
                'total_iterations' => array_sum(array_column($scenarios, 'iterations')),
                'all_passed' => count(array_filter($scenarios, fn ($s) => $s['result'] !== 'PASS')) === 0,
            ],
        ];

        file_put_contents($dir.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $summary;
    }
}
