{{--
  The one status chip for the whole system. The dashboard used to build these
  inline while every other page rendered a Bootstrap badge, so the same request
  appeared as a soft pill in one place and a solid block in another. Both now
  come from here.

  Expects: $status (string).
--}}
@php
    $st = match ($status) {
        'approved' => ['st-ok', 'bi-check-circle', 'Approved'],
        'rejected' => ['st-bad', 'bi-x-circle', 'Disapproved'],
        'cancelled' => ['st-off', 'bi-dash-circle', 'Cancelled'],
        'returned' => ['st-wait', 'bi-arrow-counterclockwise', 'Returned'],
        default => ['st-wait', 'bi-clock', 'Pending'],
    };
@endphp
<span class="st {{ $st[0] }}"><i class="bi {{ $st[1] }}"></i>{{ $st[2] }}</span>
