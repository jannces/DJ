<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Services\Security\AuditLogger;
use App\Services\Security\SecurityDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * The System Administrator's only dashboard.
     *
     * `/dashboard` redirects here for that role, and the sidebar's two entries
     * both arrive at this page — neither of them was moved or renamed.
     */
    public function dashboard(SecurityDashboardService $security): View
    {
        return view('admin.security.dashboard', $security->forDashboard());
    }

    /**
     * Mark intrusion events as reviewed.
     *
     * `handled` used to be cleared for every row the moment this dashboard
     * rendered, which made it mean "the badge has been looked at" — so the
     * column recorded that somebody glanced at a page, not that anybody dealt
     * with what was on it, and any queue built on it was empty by definition.
     *
     * Reviewing is an action now, and the topbar badge counts what is still
     * outstanding. That is also what makes it an alert rather than a
     * notification: it stays up until an administrator says they have handled
     * the events, instead of clearing itself on sight.
     */
    public function reviewIntrusions(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:intrusion_logs,id'],
        ]);

        // No id means "everything currently outstanding". Scoped to the ids the
        // administrator could actually have been looking at is tempting, but an
        // event raised between the render and the click is exactly the one that
        // must not be silently cleared — so the sweep is explicit and the count
        // is reported back.
        $marked = IntrusionLog::where('handled', false)
            ->when(isset($data['id']), fn ($q) => $q->where('id', $data['id']))
            ->update(['handled' => true]);

        $this->audit->log('intrusions_reviewed', null, [], [
            'id' => $data['id'] ?? 'all',
            'events' => $marked,
        ]);

        return back()->with('status', $marked === 1
            ? 'Event marked as reviewed.'
            : "{$marked} events marked as reviewed.");
    }

    public function intrusions(Request $request): View
    {
        $logs = IntrusionLog::with('user')
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('severity')->toString(), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->string('ip')->toString(), fn ($q, $ip) => $q->where('ip', 'like', "%{$ip}%"))
            ->latest()->paginate(config('lists.per_page'))->withQueryString();

        return view('admin.security.intrusions', compact('logs'));
    }

    public function blockedIps(): View
    {
        $blocked = BlockedIp::with('blocker')->latest()->paginate(config('lists.per_page'));

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
