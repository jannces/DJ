<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use App\Services\Security\AuditLogger;
use App\Services\Security\IntrusionDetectionService;
use App\Services\Security\SecurityDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SecurityController extends Controller
{
    /** The window the "seen attacking" list covers. */
    private const INTRUDER_DAYS = 7;

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

    public function blockedIps(Request $request, SecurityDashboardService $security): View
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

        return view('admin.security.blocked-ips', [
            'blocked' => $blocked,
            // Who has attacked and is not being kept out. The system has always
            // known; until now the only way to act on it was to read an address
            // off the intrusion log and retype it into the form.
            'intruders' => $security->intruders(self::INTRUDER_DAYS),
            'days' => self::INTRUDER_DAYS,
        ]);
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
     * Block an address off the "seen attacking" list.
     *
     * The address arrives in the request, so nothing about it is taken on
     * trust. The reason is rebuilt here from the events actually on record,
     * and an address with no attack events behind it is refused outright --
     * the one-click path requires evidence to exist. Blocking something on a
     * report rather than on evidence is what the manual form is for, and that
     * asks for a reason to be typed.
     */
    public function blockIntruder(Request $request, SecurityDashboardService $security): RedirectResponse
    {
        $ip = $request->validate(['ip' => ['required', 'ip']])['ip'];

        // Never blockable, so never blocked -- the middleware would ignore the
        // row anyway and it would sit in the list looking like it worked.
        abort_if(IntrusionDetectionService::isTrustedIp($ip), 403,
            'This address is on the never-block list.');

        $seen = collect($security->intruders(self::INTRUDER_DAYS, PHP_INT_MAX))
            ->firstWhere('ip', $ip);

        abort_if($seen === null, 404,
            'That address has no attack events on record, or is already blocked.');

        $hours = (int) SystemSetting::get('security.ip_block_hours', 24);

        $block = BlockedIp::updateOrCreate(['ip' => $ip], [
            'reason' => $seen['reason'],
            'source' => 'manual',
            'blocked_by' => $request->user()->id,
            'expires_at' => now()->addHours($hours),
            'active' => true,
        ]);
        Cache::forget("blocked-ip.{$ip}");
        $this->audit->log('ip_blocked_from_evidence', $block, [], [
            'ip' => $ip,
            'events' => $seen['events'],
            'hours' => $hours,
        ]);

        return back()->with('status', "IP {$ip} blocked for {$hours} hours.");
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

        // The reason has to be rewritten too. Keeping the original left the
        // row reading "Automatic block: 5 intrusion events in 10 minutes"
        // while labelled manual and attributed to a person -- three statements
        // that contradict each other. The original stays as context.
        $original = $blockedIp->reason;
        $blockedIp->update([
            'active' => true,
            'source' => 'manual',
            'blocked_by' => $request->user()->id,
            'expires_at' => now()->addHours($hours),
            'reason' => 'Blocked again by '.$request->user()->name
                .(str_starts_with($original, 'Blocked again by ') ? '' : ' (originally: '.$original.')'),
        ]);
        Cache::forget("blocked-ip.{$blockedIp->ip}");
        $this->audit->log('ip_blocked_again', $blockedIp, $old, [
            'ip' => $blockedIp->ip,
            'hours' => $hours,
        ]);

        return back()->with('status', "IP {$blockedIp->ip} blocked again for {$hours} hours.");
    }
}
