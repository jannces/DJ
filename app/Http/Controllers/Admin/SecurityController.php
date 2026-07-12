<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\FailedLogin;
use App\Models\IntrusionLog;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function dashboard(): View
    {
        $today = today();

        $stats = [
            'blocked_ips' => BlockedIp::currentlyActive()->count(),
            'intrusions_total' => IntrusionLog::count(),
            'intrusions_today' => IntrusionLog::whereDate('created_at', $today)->count(),
            'intrusions_week' => IntrusionLog::where('created_at', '>=', now()->subWeek())->count(),
            'intrusions_month' => IntrusionLog::where('created_at', '>=', now()->subMonth())->count(),
            'failed_logins_today' => FailedLogin::whereDate('occurred_at', $today)->count(),
        ];

        $topAttackers = IntrusionLog::select('ip', DB::raw('count(*) as total'))
            ->groupBy('ip')->orderByDesc('total')->limit(8)->get();

        $targetedPages = IntrusionLog::select('route', DB::raw('count(*) as total'))
            ->whereNotNull('route')->groupBy('route')->orderByDesc('total')->limit(8)->get();

        $byCategory = IntrusionLog::select('category', DB::raw('count(*) as total'))
            ->groupBy('category')->pluck('total', 'category');

        // 7-day trend
        $trend = ['labels' => [], 'data' => []];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $trend['labels'][] = $day->format('D');
            $trend['data'][] = IntrusionLog::whereDate('created_at', $day->toDateString())->count();
        }

        $recent = IntrusionLog::with('user')->latest()->limit(15)->get();

        // Mark all as seen (clears the polling badge).
        IntrusionLog::where('handled', false)->update(['handled' => true]);

        return view('admin.security.dashboard', compact('stats', 'topAttackers', 'targetedPages', 'byCategory', 'trend', 'recent'));
    }

    public function intrusions(Request $request): View
    {
        $logs = IntrusionLog::with('user')
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('severity')->toString(), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->string('ip')->toString(), fn ($q, $ip) => $q->where('ip', 'like', "%{$ip}%"))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.security.intrusions', compact('logs'));
    }

    public function blockedIps(): View
    {
        $blocked = BlockedIp::with('blocker')->latest()->paginate(20);

        return view('admin.security.blocked-ips', compact('blocked'));
    }

    public function blockIp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'reason' => ['required', 'string', 'max:255'],
            'hours' => ['nullable', 'integer', 'min:1'],
        ]);

        $block = BlockedIp::updateOrCreate(['ip' => $data['ip']], [
            'reason' => $data['reason'],
            'source' => 'manual',
            'blocked_by' => $request->user()->id,
            'expires_at' => isset($data['hours']) ? now()->addHours($data['hours']) : null,
            'active' => true,
        ]);
        Cache::forget("blocked-ip.{$data['ip']}");
        $this->audit->log('ip_blocked_manual', $block, [], $data);

        return back()->with('status', "IP {$data['ip']} blocked.");
    }

    public function unblockIp(BlockedIp $blockedIp): RedirectResponse
    {
        $blockedIp->update(['active' => false]);
        Cache::forget("blocked-ip.{$blockedIp->ip}");
        $this->audit->log('ip_unblocked', $blockedIp, ['active' => true], ['active' => false]);

        return back()->with('status', "IP {$blockedIp->ip} unblocked.");
    }
}
