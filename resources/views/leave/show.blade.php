@extends('layouts.app')
@section('title', 'Leave '.$leaveRequest->reference_no)
@section('content')
@php
    // Two parents: the employee who filed it came from My Leave Requests, an
    // approver from All Leave Requests. Picked by permission rather than by the
    // referrer, which is absent on a direct visit and forgeable when it is not.
    $viewsAll = auth()->user()?->hasPermission('leave.requests.view-all');
    $mine = $leaveRequest->user_id === auth()->id();

    /**
     * The answers to the form's conditional questions, in words.
     *
     * `details` is a JSON bag keyed by form field, and this page used to print
     * it raw: `str_replace('_', ' ', $key)` title-cased, against the stored
     * value. That produced "Purpose Other" as a label and "bar" as a value --
     * and, because the bag's `purpose` key is the STUDY LEAVE question while
     * the application has a `purpose` column of its own, two rows both labelled
     * "Purpose" holding different things.
     *
     * So the keys are named here and the stored codes are translated. A key
     * with no entry is not printed: an unlabelled row is a column name leaking
     * onto a page an officer is reading.
     */
    $detailLabels = [
        'location' => 'Leave will be spent',
        'location_specify' => 'Destination abroad',
        'confinement' => 'Sick leave type',
        'illness' => 'Illness',
        'surgery_details' => 'Gynaecological surgery',
        'purpose' => 'Study purpose',
        'purpose_other' => 'Other study purpose',
        'separation_type' => 'Separation',
        'reason' => 'Reason for monetization',
        'days_to_monetize' => 'Days to monetize',
        'expected_delivery' => 'Expected / actual delivery',
        'extension' => 'Additional extension (R.A. 11210)',
        'travel_details' => 'Purpose / travel details',
        'accident_details' => 'Work-related accident',
        'calamity' => 'Declared calamity',
        'calamity_area' => 'Affected area',
    ];

    // The stored codes are what the radio buttons submit, not English.
    $detailValues = [
        'within_ph' => 'Within the Philippines',
        'abroad' => 'Abroad',
        'hospital' => 'In hospital',
        'outpatient' => 'Out patient',
        'masters' => "Completion of Master's degree",
        'bar' => 'BAR examination review',
        'board' => 'Board examination review',
    ];

    $say = function ($v) use ($detailValues) {
        if (is_array($v)) {
            return implode(', ', array_map(fn ($x) => $detailValues[$x] ?? $x, $v));
        }

        return $detailValues[$v] ?? $v;
    };

    // Long answers get the full width of the card rather than a gutter.
    $proseKeys = ['purpose_other', 'surgery_details', 'travel_details',
        'accident_details', 'reason', 'illness'];
@endphp

<x-page-head class="mb-3"
    :title="$leaveRequest->reference_no"
    :sub="$leaveRequest->leaveType->name.' · filed '.$leaveRequest->date_filed->format('M d, Y')"
    :back-url="$viewsAll ? route('leave.all') : route('leave.index')"
    :back-label="$viewsAll ? 'All Leave Requests' : 'My Leave Requests'">
    <div class="d-flex align-items-center gap-2">
        {{-- The status belongs beside the reference, not buried in a card
             header halfway down. It is the first thing anybody opening this
             page wants and the one fact the whole page is about. --}}
        @include('leave._status_badge', ['status' => $leaveRequest->status])
        <x-paper-picker :request="$leaveRequest" />
        @if ($mine && $leaveRequest->isCancellable())
            <form method="POST" action="{{ route('leave.cancel', $leaveRequest) }}" class="d-inline"
                  data-confirm="Cancel this leave request? It cannot be un-cancelled.">
                @csrf<button class="btn btn-outline-danger btn-sm">Cancel</button>
            </form>
        @endif
    </div>
</x-page-head>

{{--
  Two rows, not two columns.

  It was one row of col-lg-8 + col-lg-4, with the details and the documents
  stacked on the left and a short timeline alone on the right. Measured at
  1600px the left column's content ran to 511px and the right ended at 284px,
  so a quarter of the page was an empty column beside a card that had nothing
  under it -- and the documents panel, pinned to the left, made the gap wider
  by refusing to cross into it.

  The documents go full width underneath instead. Nothing is left hanging, and
  the upload row gets the width to put its three controls side by side.

  No h-100 on the pair, either. It makes them equal height, which fills the row
  but moves the empty space INSIDE the shorter card -- 180px of blank card
  under the last fact, which is the thing that looked wrong on the security
  dashboard. A card that ends where its content ends reads as finished; a
  ragged bottom between two cards does not.
--}}
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold">Application details</div>
            <div class="card-body">
                {{-- dfl-2: two label/value pairs per row on a wide card. The
                     card is two thirds of the page and the values are mostly
                     short, so one pair per row left the right half empty. --}}
                <dl class="dfl dfl-2">
                    <dt>Applicant</dt><dd>{{ $leaveRequest->user->name }}</dd>
                    <dt>Office</dt><dd>{{ $leaveRequest->office_snapshot ?? '—' }}</dd>
                    <dt>Position</dt><dd>{{ $leaveRequest->position_snapshot ?? '—' }}</dd>
                    <dt>Working days</dt>
                    <dd>{{ rtrim(rtrim(number_format($leaveRequest->working_days, 1), '0'), '.') }}</dd>
                    <dt>Leave dates</dt>
                    <dd>{{ $leaveRequest->start_date->format('M d, Y') }} – {{ $leaveRequest->end_date->format('M d, Y') }}</dd>
                    <dt>Commutation</dt>
                    <dd>{{ $leaveRequest->commutation ? 'Requested' : 'Not requested' }}</dd>

                    @if ($leaveRequest->purpose)
                        <dt>Purpose</dt><dd>{{ $leaveRequest->purpose }}</dd>
                    @endif

                    @foreach ($leaveRequest->details ?? [] as $key => $value)
                        @continue(blank($value) || ! isset($detailLabels[$key]))
                        @php $wide = in_array($key, $proseKeys, true); @endphp
                        <dt @class(['dfl-wide' => $wide])>{{ $detailLabels[$key] }}</dt>
                        <dd @class(['dfl-wide' => $wide])>{{ $say($value) }}</dd>
                    @endforeach

                    @if ($leaveRequest->status === 'approved')
                        <dt>Days with pay</dt><dd>{{ $leaveRequest->days_with_pay }}</dd>
                        <dt>Days without pay</dt><dd>{{ $leaveRequest->days_without_pay }}</dd>
                    @endif

                    @if ($leaveRequest->is_late_filing && $leaveRequest->late_filing_reason)
                        <dt class="dfl-wide">Late filing reason</dt>
                        <dd class="dfl-wide">{{ $leaveRequest->late_filing_reason }}</dd>
                    @endif

                    @if ($leaveRequest->disapproval_reason)
                        <dt class="dfl-wide text-danger">Disapproval reason</dt>
                        <dd class="dfl-wide">{{ $leaveRequest->disapproval_reason }}</dd>
                    @endif
                </dl>

                @if ($leaveRequest->filing_warnings)
                    <div class="alert alert-warning small mt-3 mb-0">
                        @foreach ($leaveRequest->filing_warnings as $w)<div>⚠ {{ $w }}</div>@endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- The same timeline the employee's own preview page renders. This
             page used to hand-roll its own, with a role map keyed
             'department_head'/'hr'/'mayor' against slugs that are actually
             'department' and 'authorized' -- nothing matched, so every row
             printed the raw slug: a step called "authorized" in lower case.
             The shared partial has never had that bug, and now the two cannot
             drift apart again. --}}
        <div class="card">
            <div class="card-header fw-semibold">Approval timeline</div>
            <div class="card-body">
                @include('leave._timeline', ['r' => $leaveRequest, 'mine' => $mine])
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold">Supporting documents</div>
            <ul class="list-group list-group-flush">
                @forelse ($leaveRequest->documents as $doc)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>
                            <i class="bi bi-paperclip me-1" aria-hidden="true"></i>
                            {{ ucwords(str_replace('_', ' ', $doc->type)) }} — {{ $doc->original_name }}
                        </span>
                        <a href="{{ route('leave.documents.download', $doc) }}"
                           class="btn btn-outline-secondary btn-sm">Download</a>
                    </li>
                @empty
                    <li class="list-group-item text-muted small">No documents uploaded.</li>
                @endforelse
            </ul>

            @if ($mine && ! $leaveRequest->isFinal())
                <div class="card-body">
                    <form method="POST" action="{{ route('leave.documents.store', $leaveRequest) }}"
                          enctype="multipart/form-data" class="up-row" data-no-loader>
                        @csrf
                        <div>
                            {{-- A real label, outside the field. As a placeholder it
                                 vanished the moment somebody typed, leaving a filled
                                 box with nothing saying what was in it. --}}
                            <label class="form-label" for="doc-type-{{ $leaveRequest->id }}">Document type</label>
                            <input id="doc-type-{{ $leaveRequest->id }}" name="type"
                                   class="form-control form-control-sm"
                                   placeholder="e.g. Medical certificate" required>
                        </div>
                        <div>
                            {{-- The file input had no label at all. It is the one
                                 control on this row that decides what is uploaded. --}}
                            <label class="form-label" for="doc-file-{{ $leaveRequest->id }}">File</label>
                            <input id="doc-file-{{ $leaveRequest->id }}" type="file" name="document"
                                   class="form-control form-control-sm"
                                   accept=".pdf,.jpg,.jpeg,.png" required>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-lgu">
                                <i class="bi bi-upload me-1" aria-hidden="true"></i>Upload
                            </button>
                        </div>
                    </form>
                    <p class="form-text mb-0">PDF, JPG or PNG.</p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
