<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
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
            // `q` is the toolbar's search box; `ip` is kept so a link or
            // bookmark written against the old filter still works.
            ->when($request->string('q')->toString() ?: $request->string('ip')->toString(),
                fn ($query, $ip) => $query->where('ip', 'like', "%{$ip}%"))
            // Cursor, not offset: an attack in progress writes events while
            // the log is being read, and an offset page would skip some of
            // them. See AuditLogController.
            ->latest()->orderByDesc('id')
            ->cursorPaginate(config('lists.per_page'))->withQueryString();

        return view('admin.security.intrusions', compact('logs'));
    }

    public function blockedIps(Request $request): View
    {
        $blocked = BlockedIp::with('blocker')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('ip', 'like', "%{$s}%")->orWhere('reason', 'like', "%{$s}%")
            ))
            ->when($request->string('source')->toString(), fn ($q, $s) => $q->where('source', $s))
            // In effect by default: a list of blocks is asked about because
            // something is being kept out right now, not because of what was
            // lifted last month.
            ->when($request->string('show')->toString() !== 'all',
                fn ($q) => $request->string('show')->toString() === 'lifted'
                    ? $q->where(fn ($w) => $w->where('active', false)
                        ->orWhere(fn ($e) => $e->whereNotNull('expires_at')->where('expires_at', '<=', now())))
                    : $q->currentlyActive())
            ->latest()->paginate(config('lists.per_page'))->withQueryString();

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

    /**
     * Lift a block.
     *
     * Available on automatic blocks as much as manual ones — an address the
     * system decided was hostile is exactly the one that turns out to be a
     * legitimate employee behind an office router.
     */
    public function unblockIp(BlockedIp $blockedIp): RedirectResponse
    {
        $blockedIp->update(['active' => false]);
        Cache::forget("blocked-ip.{$blockedIp->ip}");
        $this->audit->log('ip_unblocked', $blockedIp, ['active' => true], ['active' => false]);

        return back()->with('status', "Block lifted for {$blockedIp->ip}.");
    }

    /**
     * Put a lifted block back.
     *
     * Without this the row was dead weight once lifted: to block the same
     * address again you had to read the address off the row and type it into
     * the form. It is recorded as a fresh decision by whoever clicked it
     * rather than as a continuation of the original automatic block, because
     * that is what it is.
     */
    public function reblockIp(Request $request, BlockedIp $blockedIp): RedirectResponse
    {
        $hours = (int) SystemSetting::get('security.ip_block_hours', 24);
        $old = ['active' => $blockedIp->active, 'expires_at' => (string) $blockedIp->expires_at];

        $blockedIp->update([
            'active' => true,
            'source' => 'manual',
            'blocked_by' => $request->user()->id,
            'expires_at' => now()->addHours($hours),
        ]);
        Cache::forget("blocked-ip.{$blockedIp->ip}");
        $this->audit->log('ip_blocked_again', $blockedIp, $old, [
            'ip' => $blockedIp->ip,
            'hours' => $hours,
        ]);

        return back()->with('status', "IP {$blockedIp->ip} blocked again for {$hours} hours.");
    }
}
