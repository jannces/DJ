{{--
    Status presentation for the HR workspace. Icon + text carry the meaning so
    the state is readable without colour. Labels and states mirror the existing
    LeaveRequest statuses exactly — this partial only renders them.

    Usage: @include('partials.status-pill', ['status' => $r->status])
--}}
@php
    $presentation = [
        'pending' => ['status-idle', 'bi-hourglass', 'Pending'],
        'dept_review' => ['status-info', 'bi-people', 'Department Review'],
        'hr_review' => ['status-wait', 'bi-clipboard-check', 'HR Review'],
        'final_review' => ['status-wait', 'bi-award', 'Final Review'],
        'approved' => ['status-ok', 'bi-check-circle', 'Approved'],
        'rejected' => ['status-bad', 'bi-x-circle', 'Disapproved'],
        'returned' => ['status-wait', 'bi-arrow-counterclockwise', 'Returned'],
        'cancelled' => ['status-idle', 'bi-slash-circle', 'Cancelled'],
    ];
    [$tone, $icon, $label] = $presentation[$status] ?? ['status-idle', 'bi-dot', ucfirst(str_replace('_', ' ', $status))];
@endphp
<span class="status {{ $tone }}"><i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $label }}</span>
