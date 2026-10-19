<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Services\Security\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Clear the intrusion records the broken rate counter produced, and lift the
 * blocks it handed out.
 *
 * The counter never reset while an address kept making requests, so once it
 * passed the limit it flagged everything from that address for as long as a
 * browser tab stayed open. The events it wrote are not observations of
 * anything -- but they sit in a security log, so removing them is done here,
 * deliberately, against stated criteria, and audited, rather than by hand
 * against the database.
 *
 * Only the certain case is removed by default: `rate` events on the endpoint
 * that is now exempt from counting. The counter also flagged ordinary pages
 * from a stuck address, and those cannot be told apart from a real burst after
 * the fact, so --all-rate is opt-in and asks first.
 */
class PurgeRateFalsePositives extends Command
{
    protected $signature = 'security:purge-rate-false-positives
        {--all-rate : also remove rate events on ordinary routes recorded before the fix}
        {--before= : cutoff for --all-rate (Y-m-d), defaults to today}
        {--unblock : lift auto-blocks that were issued for rate events alone}
        {--force : do not ask}';

    protected $description = 'Remove the intrusion records left by the rate counter that never reset.';

    /** The path the IDS no longer counts; see config/security.php. */
    private const EXEMPT_PATH = 'api/internal/security/alerts';

    public function handle(AuditLogger $audit): int
    {
        $certain = IntrusionLog::where('category', 'rate')->where('route', self::EXEMPT_PATH);
        $certainCount = (clone $certain)->count();

        $wide = null;
        $wideCount = 0;

        if ($this->option('all-rate')) {
            // A bare date means the start of that day; no date means now, so
            // the sweep covers everything recorded up to the moment it runs.
            $before = $this->option('before')
                ? \Carbon\Carbon::parse($this->option('before'))->startOfDay()
                : now();

            $wide = IntrusionLog::where('category', 'rate')
                ->where('route', '!=', self::EXEMPT_PATH)
                ->where('created_at', '<=', $before);
            $wideCount = (clone $wide)->count();
        }

        $this->line("Rate events on the polling endpoint: <fg=yellow>{$certainCount}</>");

        if ($wide) {
            $this->line("Rate events on other routes before the cutoff: <fg=yellow>{$wideCount}</>");
            $this->warn('Those cannot be told apart from a genuine burst after the fact.');
        }

        if ($certainCount + $wideCount === 0 && ! $this->option('unblock')) {
            $this->info('Nothing to remove.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm('Remove these records permanently?', false)) {
            $this->info('Left alone.');

            return self::SUCCESS;
        }

        $certain->delete();
        $wide?->delete();

        $lifted = $this->option('unblock') ? $this->liftAutoBlocks() : 0;

        // The removal is itself a security event: what was taken out, on what
        // grounds, and by whom.
        $audit->log('intrusion_false_positives_purged', null, [], [
            'polling_endpoint_events' => $certainCount,
            'other_rate_events' => $wideCount,
            'blocks_lifted' => $lifted,
            'reason' => 'Rate counter did not reset between minutes; the events record no observation.',
        ]);

        $this->info("Removed {$certainCount} polling event(s)"
            .($wide ? " and {$wideCount} other rate event(s)" : '')
            .($this->option('unblock') ? ", lifted {$lifted} block(s)" : '')
            .'.');

        return self::SUCCESS;
    }

    /**
     * An address auto-blocked purely on rate events was blocked for keeping a
     * page open. One with anything else against it -- an injection attempt, a
     * traversal -- keeps its block.
     */
    private function liftAutoBlocks(): int
    {
        $lifted = 0;

        foreach (BlockedIp::where('source', 'auto')->where('active', true)->get() as $block) {
            $remaining = IntrusionLog::where('ip', $block->ip)->count();

            if ($remaining > 0) {
                $this->line("  kept <fg=yellow>{$block->ip}</> — {$remaining} event(s) still against it");

                continue;
            }

            $block->update(['active' => false]);
            Cache::forget("blocked-ip.{$block->ip}");
            $this->line("  lifted <fg=green>{$block->ip}</>");
            $lifted++;
        }

        return $lifted;
    }
}
