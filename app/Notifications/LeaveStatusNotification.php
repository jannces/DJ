<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LeaveRequest $request,
        public string $event,
        public ?string $stepRole = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Leave {$this->request->reference_no}: ".$this->headline())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->action('View request', url(route('leave.show', $this->request)))
            ->line('LGU Alicia — Leave Management System');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Leave {$this->request->reference_no}: ".$this->headline(),
            'message' => $this->body(),
            'reference_no' => $this->request->reference_no,
            'status' => $this->request->status,
            'url' => route('leave.show', $this->request),
        ];
    }

    private function headline(): string
    {
        return match ($this->event) {
            'submitted' => 'Submitted',
            'approved' => 'Approved',
            'certified' => 'Certified by HR',
            'rejected' => 'Disapproved',
            'returned' => 'Returned for revision',
            // A recommendation is not a stage the application waits at -- it
            // is already with HR -- so neither headline says "moved on".
            'recommended' => 'Recommended by your department head',
            'not_recommended' => 'Not recommended by your department head',
            default => ucfirst($this->event),
        };
    }

    private function body(): string
    {
        return match ($this->event) {
            'submitted' => "Your {$this->request->leaveType->name} application ({$this->request->working_days} day/s) has been submitted for review.",
            'approved' => "Your {$this->request->leaveType->name} application has been fully approved. Your leave credits have been updated.",
            'rejected' => "Your {$this->request->leaveType->name} application was disapproved."
                .($this->request->disapproval_reason ? " Reason: {$this->request->disapproval_reason}" : ''),
            'returned' => "Your {$this->request->leaveType->name} application was returned for revision. Please review the comments and resubmit.",
            'recommended' => "The head of your office has recommended your {$this->request->leaveType->name} application for approval. HR still decides it.",
            'not_recommended' => "The head of your office did not recommend your {$this->request->leaveType->name} application. It is still with HR, who decide it.",
            default => "Your {$this->request->leaveType->name} application moved to the next review stage ({$this->stepRole}).",
        };
    }
}
