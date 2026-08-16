{{--
    Usage: @include('partials.empty-state', [
        'icon' => 'bi-inbox', 'title' => 'No Leave Requests',
        'body' => 'You haven\'t submitted any leave requests yet.',
        'actionLabel' => 'Apply for Leave', 'actionUrl' => route('leave.create'),
    ])
--}}
<div class="empty">
    <div class="empty-mark"><i class="bi {{ $icon ?? 'bi-inbox' }}" aria-hidden="true"></i></div>
    <h3>{{ $title }}</h3>
    @isset($body)<p>{{ $body }}</p>@endisset
    @isset($actionUrl)
        <a href="{{ $actionUrl }}" class="btn btn-lgu btn-sm">{{ $actionLabel ?? 'Continue' }}</a>
    @endisset
</div>
