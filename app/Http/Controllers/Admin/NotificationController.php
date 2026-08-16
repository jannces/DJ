<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->notifications()->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    /**
     * Unread count + newest item, polled by the topbar bell so a decision made
     * on one machine surfaces on another without a page refresh.
     */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = $user->unreadNotifications()->latest('created_at')->first();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'latest' => $latest ? [
                'id' => $latest->id,
                'title' => $latest->data['title'] ?? 'Notification',
                'message' => $latest->data['message'] ?? '',
                'url' => $latest->data['url'] ?? null,
            ] : null,
        ]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->first()?->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'All notifications marked as read.');
    }
}
