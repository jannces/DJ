<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells a department head that one of their people has filed for leave.
 *
 * This is the whole of the head's involvement. HR decides; the head is told so
 * they can plan around an absence, which is a staffing question rather than an
 * approval one. The wording says so outright — an officer who is sent a leave
 * application and not told they have nothing to do with it will reasonably
 * assume they are being asked to act, and then it sits.
 *
 * Written from the head's point of view, which is why it is not
 * LeaveStatusNotification with another event string: that one addresses the
 * applicant ("Your leave application has been…"), and the same sentence sent
 * to somebody else's inbox reads as though their own leave had moved.
 */
class DepartmentLeaveFiledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LeaveRequest $request)
    {
    }

    /**
     * IN-SYSTEM ONLY — the bell, never the head's inbox.
     *
     * Deliberately not `mail`, unlike LeaveStatusNotification. This one fires
     * on every application filed anywhere in the office, which for a large
     * department is a steady drip of mail about something the recipient does
     * not act on; the first week of that teaches them to filter the sender,
     * and then the decisions they DO need never reach them either.
     *
     * It also suits the deployment: this runs on the LGU's own LAN, where
     * outbound mail is the one part of the system that depends on something
     * outside the building.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Leave filed in your office: '.$this->applicant(),
            'message' => $this->body().' No action is required from you — HR validates and approves.',
            'reference_no' => $this->request->reference_no,
            'status' => $this->request->status,
            'url' => route('leave.show', $this->request),
        ];
    }

    private function applicant(): string
    {
        return $this->request->user?->name ?? 'An employee';
    }

    /**
     * One sentence carrying the three facts a head plans around: who, what
     * kind of leave, and which days they will be short-handed.
     */
    private function body(): string
    {
        $days = (float) $this->request->working_days;
        $days = rtrim(rtrim(number_format($days, 1), '0'), '.');
        $type = $this->request->leaveType?->name ?? 'leave';

        $from = $this->request->start_date?->format('F d, Y');
        $to = $this->request->end_date?->format('F d, Y');
        $when = $from === $to ? $from : "$from to $to";

        return sprintf(
            '%s has filed an application for %s covering %s working day%s, %s (reference %s).',
            $this->applicant(),
            $type,
            $days,
            $days === '1' ? '' : 's',
            $when,
            $this->request->reference_no,
        );
    }
}
