<?php

namespace App\Services\Security;

use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use App\Notifications\IntrusionAlertNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

/**
 * Application-layer IDS (ADR-004). Scans each request against curated
 * signatures (SQLi/XSS/traversal), watches request-rate anomalies, records
 * events, and auto-blocks an IP once it crosses the configured threshold.
 */
class IntrusionDetectionService
{
    /** @var array<string, array{pattern:string, severity:string, block:bool}> */
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

    public function __construct(private readonly AuditLogger $audit)
    {
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

        $haystack = $this->haystack($request);

        foreach ($this->signatures as $category => $rule) {
            if (preg_match($rule['pattern'], $haystack, $matches)) {
                $this->record($request, $category, $rule['severity'], $matches[0] ?? $category);
                $this->maybeAutoBlock($request);

                if ($rule['block']) {
                    return response()->view('errors.blocked', ['ip' => $request->ip()], 400);
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

    private function haystack(Request $request): string
    {
        $parts = [rawurldecode($request->getRequestUri())];
        foreach ($request->all() as $key => $value) {
            $parts[] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return implode(' ', $parts);
    }

    private function rateAnomaly(Request $request): bool
    {
        $limit = (int) SystemSetting::get('security.rate_limit_per_minute', 120);
        $key = 'ids.rate.'.$request->ip();
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addMinute());

        return $count > $limit;
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
        $this->alertAdmins($ip, $recent);

        return true;
    }

    private function alertAdmins(string $ip, int $events): void
    {
        $admins = User::whereHas('roles', fn ($q) => $q->whereIn('slug', ['super-admin', 'system-admin']))->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new IntrusionAlertNotification($ip, $events));
        }
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    }
}
