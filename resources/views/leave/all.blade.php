@extends('layouts.app')
@section('title', 'All Leave Requests')
@section('content')

{{--
  The list reads as an inbox rather than as a spreadsheet.

  Same content as the table it replaces -- reference, employee, type, dates,
  status -- arranged the way a thread list arranges a message: when it arrived
  down the left, who it is from on the right, and the thing itself in the
  middle. It suits this page better than a grid did, because HR opens it to
  triage rather than to compare columns: the question is "what came in and what
  still needs a decision", and that is what a row now answers on one line.

  It is also responsive without help. The table needed 597px of columns and a
  separate stacked layout below 640px; a row of three flexible parts just
  reflows, so `table-stack` is gone from this page.
--}}

<div class="card thread-card">
    <div class="thread-head">
        <div class="thread-title">
            <h1>All Leave Requests</h1>
            @if ($pending = $requests->getCollection()->where('status', 'pending')->count())
                {{-- Counts what is on this page, and says so. A total across
                     every page would need a second query to answer a question
                     nobody asked. --}}
                <span class="thread-count">{{ $pending }} pending here</span>
            @endif
        </div>

        <x-list-toolbar search placeholder="Reference or employee"
            :action="route('leave.all')">
            <x-list-filter name="status" label="Status" :options="[
                'pending' => 'Pending',
                // Kept so applications filed under the old two-step flow are
                // still findable by their recorded status. Nothing lands here.
                'dept_review' => 'Department review (archived flow)',
                'hr_review' => 'HR review', 'final_review' => 'Final review',
                'approved' => 'Approved', 'rejected' => 'Rejected',
                'returned' => 'Returned', 'cancelled' => 'Cancelled',
            ]" />
            <x-list-filter name="type" label="Type" :options="$types" />
        </x-list-toolbar>
    </div>

    <div data-list>
        <ul class="thread-list">
            @forelse ($requests as $r)
                <li class="thread">
                    {{-- How long it has been waiting, which is the thing HR is
                         actually scanning for. `title` carries the real date,
                         because "5d" is useless in a screenshot or a dispute. --}}
                    <time class="thread-when" datetime="{{ $r->date_filed->toDateString() }}"
                          title="Filed {{ $r->date_filed->format('d M Y') }}">
                        {{ $r->date_filed->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}
                    </time>

                    <x-avatar :name="$r->user->name" class="thread-av" />

                    <div class="thread-body">
                        {{-- The whole row is one destination, so the link wraps
                             the part you read rather than sitting beside it as
                             a separate "View" button in its own column.

                             One line, with the reference pushed to the far end.
                             Stacked, the panel was mostly empty air on the
                             right and the reference hung underneath it as an
                             orphan; anchoring something to each end gives the
                             panel a reason to be as wide as it is. --}}
                        <a href="{{ route('leave.show', $r) }}" class="thread-link">
                            <span class="thread-subject">{{ $r->leaveType->name }}</span>
                            <span class="thread-dates">{{ $r->start_date->format('M d') }} – {{ $r->end_date->format('M d, Y') }}</span>
                            <span class="thread-ref">{{ $r->reference_no }}</span>
                        </a>
                    </div>

                    <div class="thread-meta">
                        {{-- The name opens the APPLICATION, not the person's HR
                             record: this row is a leave request, and one row
                             has one destination. That rule predates this
                             layout and NameLinkTest enforces it. --}}
                        <a href="{{ route('leave.show', $r) }}" class="thread-name name-link">{{ $r->user->name }}</a>
                        @include('leave._status_badge', ['status' => $r->status])
                    </div>
                </li>
            @empty
                <li class="thread-empty">No requests found.</li>
            @endforelse
        </ul>
    </div>

    <div class="card-body">{{ $requests->links() }}</div>
</div>
@endsection
