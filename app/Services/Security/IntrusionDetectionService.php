<?php

namespace App\Services\Security;

use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Application-layer IDS (ADR-004). Scans each request against curated
 * signatures (SQLi/XSS/traversal), watches request-rate anomalies, records
 * events, and auto-blocks an IP once it crosses the configured threshold.
 */
class IntrusionDetectionService
{
    /**
     * Signatures applied to the URI and query string, where an attack payload
     * has no legitimate reason to look like prose.
     *
     * @var array<string, array{pattern:string, severity:string, block:bool}>
     */
    private array $signatures = [
        'sqli' => [
            'pattern' => '/(\bUNION\b.*\bSELECT\b|\bSELECT\b.*\bFROM\b.*\bWHERE\b|\bOR\b\s+1\s*=\s*1|\bAND\b\s+1\s*=\s*1|;\s*DROP\s+TABLE|--\s|\/\*.*\*\/|\bSLEEP\s*\(|\bBENCHMARK\s*\(|INFORMATION_SCHEMA|\bWAITFOR\s+DELAY\b|\bxp_cmdshell\b)/i',
            'severity' => 'high', 'block' => true,
        ],
        'xss' => [
            'pattern' => '/(<script\b|<\/script>|javascript:|onerror\s*=|onload\s*=|onmouseover\s*=|<iframe\b|<img[^>]+src[^>]+onerror|document\.cookie|eval\s*\(|String\.fromCharCode)/i',
            'severity' => 'high', 'block' => true,
        ],
        'traversal' => [
            'pattern' => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\/|\/etc\/passwd|\/etc\/shadow|c:\\\\windows|boot\.ini|\.\.%00|%00)/i',
            'severity' => 'high', 'block' => true,
        ],
    ];

    /**
     * Signatures applied to submitted FREE TEXT (leave reasons, remarks,
     * comments...). Deliberately narrower than the set above.
     *
     * A person writing "Family emergency -- urgent" is not attacking the server,
     * but the full SQL-comment patterns match that prose exactly. Blocking it
     * produced a 400 page, a high-severity intrusion
     * record, and — after the auto-block threshold — a 24-hour IP ban for an
     * employee filing a legitimate leave application.
     *
     * These patterns keep the payloads that have no innocent reading in prose
     * and drop the punctuation-only ones (`-- `, comment blocks, %00).
     *
     * @var array<string, array{pattern:string, severity:string, block:bool}>
     */
    private array $freeTextSignatures = [
        'sqli' => [
            'pattern' => '/(\bUNION\b.*\bSELECT\b|\bSELECT\b.*\bFROM\b.*\bWHERE\b|\bOR\b\s+1\s*=\s*1|\bAND\b\s+1\s*=\s*1|;\s*DROP\s+TABLE|\bSLEEP\s*\(|\bBENCHMARK\s*\(|INFORMATION_SCHEMA|\bWAITFOR\s+DELAY\b|\bxp_cmdshell\b)/i',
            'severity' => 'high', 'block' => true,
        ],
        'xss' => [
            'pattern' => '/(<script\b|<\/script>|javascript:|onerror\s*=|onload\s*=|<iframe\b|document\.cookie|String\.fromCharCode)/i',
            'severity' => 'high', 'block' => true,
        ],
        'traversal' => [
            'pattern' => '/(\/etc\/passwd|\/etc\/shadow|c:\\\\windows|boot\.ini)/i',
            'severity' => 'high', 'block' => true,
        ],
    ];

    /**
     * Input names whose values are prose written by a person. Matched on the
     * final key segment, so `details[illness]` counts as `illness`.
     *
     * @var array<string>
     */
    private const FREE_TEXT_FIELDS = [
        'purpose', 'purpose_other', 'comments', 'remarks', 'reason',
        'late_filing_reason', 'disapproval_reason', 'hr_override_reason',
        'illness', 'surgery_details', 'accident_details', 'travel_details',
        'location_specify', 'calamity', 'calamity_area', 'description',
        'details', 'signature', 'applicant_signature', 'blocked_reason',
    ];

    /**
     * The categories whose detection also refuses the thing detected.
     *
     * Two mechanisms, one outcome. The three signature families return a 400
     * from this middleware, so the request never reaches a controller;
     * `auth_fail` is the lockout threshold, which blocks the account rather
     * than the request. Either way nothing got through.
     *
     * This is what lets the dashboard report how many attempts reached the
     * application rather than asserting that none did. A rule added later with
     * block => false shows up as a number that is no longer zero.
     *
     * @var array<string>
     */
    public const PREVENTED = ['sqli', 'xss', 'traversal', 'auth_fail'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityAlerter $alerts,
    ) {
    }

    /** The signature categories that refuse the request, read off the rules. */
    public function blockingCategories(): array
    {
        $blocking = [];
        foreach ([$this->signatures, $this->freeTextSignatures] as $set) {
            foreach ($set as $category => $rule) {
                if ($rule['block']) {
                    $blocking[$category] = true;
                }
            }
        }

        return array_keys($blocking);
    }

    public function inspect(Request $request): ?Response
    {
        if (! SystemSetting::get('security.ids_enabled', true)) {
            return null;
        }

        // Skip static asset requests.
        if (str_contains($request->path(), 'vendor/') || $request->is('*.css', '*.js', '*.png', '*.ico')) {
            return null;
        }

        // Scanned in two passes: the URI and structured input get the full
        // signature set, free-text values get the narrower one.
        $passes = [
            [$this->haystack($request), $this->signatures],
            [$this->freeTextHaystack($request), $this->freeTextSignatures],
        ];

        foreach ($passes as [$haystack, $signatures]) {
            if ($haystack === '') {
                continue;
            }
            foreach ($signatures as $category => $rule) {
                if (preg_match($rule['pattern'], $haystack, $matches)) {
                    $this->record($request, $category, $rule['severity'], $matches[0] ?? $category);
                    $this->maybeAutoBlock($request);

                    if ($rule['block']) {
                        return response()->view('errors.blocked', ['ip' => $request->ip()], 400);
                    }
                }
            }
        }

        // Request-rate anomaly (sliding window per IP).
        if ($this->rateAnomaly($request)) {
            $this->record($request, 'rate', 'medium', 'request rate exceeded');
            if ($this->maybeAutoBlock($request)) {
                return response()->view('errors.blocked', ['ip' => $request->ip()], 429);
            }
        }

        return null;
    }

    /** Public helper so controllers/tests can log non-HTTP-scan events uniformly. */
    public function record(Request $request, string $category, string $severity, string $excerpt, ?string $rule = null): IntrusionLog
    {
        return IntrusionLog::create([
            'category' => $category,
            'severity' => $severity,
            'route' => substr($request->path(), 0, 255),
            'method' => $request->method(),
            'payload_excerpt' => substr($this->sanitize($excerpt), 0, 500),
            'matched_rule' => $rule ?? $category.'_signature',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'user_id' => $request->user()?->id,
        ]);
    }

    /** URI, query string and every non-free-text input value. */
    private function haystack(Request $request): string
    {
        $parts = [rawurldecode($request->getRequestUri())];
        $this->collect($request->all(), $parts, freeText: false);

        return implode(' ', $parts);
    }

    /** Only the values a person types as prose. */
    private function freeTextHaystack(Request $request): string
    {
        $parts = [];
        $this->collect($request->all(), $parts, freeText: true);

        return implode(' ', $parts);
    }

    /**
     * Walk the input tree, keeping either the free-text values or everything
     * else. Nested keys are judged by their own name, so `details[illness]`
     * is treated as free text while `details[location]` is not.
     */
    private function collect(array $input, array &$parts, bool $freeText, bool $inheritedFreeText = false): void
    {
        foreach ($input as $key => $value) {
            $isFreeText = $inheritedFreeText
                || in_array(strtolower((string) $key), self::FREE_TEXT_FIELDS, true);

            if (is_array($value)) {
                $this->collect($value, $parts, $freeText, $isFreeText);

                continue;
            }
            if (! is_scalar($value)) {
                continue;
            }
            if ($isFreeText === $freeText) {
                $parts[] = (string) $value;
            }
        }
    }

    /**
     * Requests from this address in the current clock minute, against the
     * configured limit.
     *
     * Two things were wrong here, and the second was the serious one.
     *
     * The key was `ids.rate.{ip}` with a one-minute lifetime rewritten by
     * every request, so it only expired after a full minute of complete
     * silence from that address. The bell asks for new alerts every fifteen
     * seconds on every open tab, so silence never came: the count climbed past
     * the limit and then flagged *every* request from that address for as long
     * as the tab stayed open. That is the 284 identical `rate` events in the
     * log, one every fifteen seconds.
     *
     * And because each of those calls maybeAutoBlock(), which trips at five
     * events in ten minutes, a real employee on the LAN would have been given
     * a 24-hour IP block about a minute into the stuck state -- for leaving
     * the leave portal open. Loopback is trusted, so the one machine this
     * never happened to was the administrator's.
     *
     * The bucket is keyed by the minute now, so it retires on its own whether
     * or not the address goes quiet.
     */
    private function rateAnomaly(Request $request): bool
    {
        if ($this->isRateExempt($request)) {
            return false;
        }

        $limit = (int) SystemSetting::get('security.rate_limit_per_minute', 120);
        $key = 'ids.rate.'.$request->ip().'.'.now()->format('YmdHi');
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addMinutes(2));

        return $count > $limit;
    }

    /**
     * The system polling itself is not evidence of anything.
     *
     * Only the bell's own endpoint is exempt, and only from *counting* -- it
     * is still scanned for signatures like every other request, and it now
     * carries a real throttle, which is a bound rather than a detector. The
     * token-authenticated API endpoints stay counted: those are the ones
     * something outside the LGU could reach.
     *
     * Matched on path rather than route name: the IDS is global middleware and
     * runs before the router has resolved anything, so $request->route() is
     * still null here.
     */
    private function isRateExempt(Request $request): bool
    {
        $paths = (array) config('security.rate_exempt_paths', []);

        return $paths !== [] && $request->is(...$paths);
    }

    /**
     * Auto-block once an IP produces >= threshold events within the window.
     * Returns true if a (new or existing) active block now applies.
     */
    /**
     * Loopback and explicitly whitelisted IPs are never auto-blocked, so the
     * server/admin machine can never lock itself out. Configure extra trusted
     * IPs via the `security.never_block_ips` setting (comma-separated).
     */
    public static function isTrustedIp(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }
        $always = ['127.0.0.1', '::1'];
        $configured = array_filter(array_map('trim', explode(',', (string) SystemSetting::get('security.never_block_ips', ''))));

        return in_array($ip, array_merge($always, $configured), true);
    }

    public function maybeAutoBlock(Request $request): bool
    {
        $ip = $request->ip();

        if (self::isTrustedIp($ip)) {
            return false;
        }

        $threshold = (int) SystemSetting::get('security.auto_block_threshold', 5);
        $windowMin = (int) SystemSetting::get('security.auto_block_window_minutes', 10);

        $recent = IntrusionLog::where('ip', $ip)
            ->where('created_at', '>=', now()->subMinutes($windowMin))->count();

        if ($recent < $threshold) {
            return false;
        }

        if (BlockedIp::currentlyActive()->where('ip', $ip)->exists()) {
            return true;
        }

        $hours = (int) SystemSetting::get('security.ip_block_hours', 24);
        $block = BlockedIp::updateOrCreate(['ip' => $ip], [
            'reason' => "Automatic block: {$recent} intrusion events in {$windowMin} minutes",
            'source' => 'auto',
            'expires_at' => now()->addHours($hours),
            'active' => true,
        ]);
        Cache::forget("blocked-ip.{$ip}");

        $this->audit->log('ip_auto_blocked', $block, [], ['ip' => $ip, 'events' => $recent]);
        $this->alerts->ipAutoBlocked($ip, $recent);

        return true;
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    }
}
