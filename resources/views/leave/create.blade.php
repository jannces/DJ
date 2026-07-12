@extends('layouts.app')
@section('title', 'Apply for Leave')
@section('content')
<h1 class="h4 mb-3">Application for Leave — CSC Form No. 6</h1>

<form method="POST" action="{{ route('leave.store') }}" enctype="multipart/form-data" id="leaveForm" data-no-loader>
    @csrf
    @error('policy')<div class="alert alert-danger">{{ $message }}</div>@enderror
    @if ($errors->has('policy'))
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->get('policy') as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header fw-semibold">1. Employee information</div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><label class="form-label">Office / Department</label>
                        <input class="form-control" value="{{ $profile?->department?->name ?? '—' }}" readonly></div>
                    <div class="col-md-6"><label class="form-label">Name</label>
                        <input class="form-control" value="{{ auth()->user()->name }}" readonly></div>
                    <div class="col-md-4"><label class="form-label">Date of filing</label>
                        <input type="date" name="date_filed" class="form-control" value="{{ old('date_filed', now()->toDateString()) }}" required></div>
                    <div class="col-md-4"><label class="form-label">Position</label>
                        <input class="form-control" value="{{ $profile?->position?->title ?? '—' }}" readonly></div>
                    <div class="col-md-4"><label class="form-label">Salary</label>
                        <input class="form-control" value="₱{{ number_format($profile?->salary ?? 0, 2) }}" readonly></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold">2. Details of application</div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><label class="form-label">Type of leave</label>
                        <select name="leave_type_id" id="leaveType" class="form-select @error('leave_type_id') is-invalid @enderror" required>
                            <option value="">— select —</option>
                            @foreach ($types as $t)
                                <option value="{{ $t->id }}" data-schema='@json($t->detail_schema)' data-deductible="{{ $t->deductible ? 1 : 0 }}"
                                    @selected(old('leave_type_id')==$t->id)>{{ $t->name }}</option>
                            @endforeach
                        </select>
                        @error('leave_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3"><label class="form-label">Start date</label>
                        <input type="date" name="start_date" id="startDate" class="form-control" value="{{ old('start_date') }}" required></div>
                    <div class="col-md-3"><label class="form-label">End date</label>
                        <input type="date" name="end_date" id="endDate" class="form-control" value="{{ old('end_date') }}" required></div>

                    <div class="col-12">
                        <div class="alert alert-info py-2 mb-0 d-none" id="previewBox">
                            <strong>Working days:</strong> <span id="workingDays">0</span>
                            <span id="creditWarn" class="text-danger ms-2"></span>
                            <div id="warnList" class="small mt-1"></div>
                        </div>
                    </div>

                    <div class="col-12" id="detailsFields"><!-- conditional detail fields injected here --></div>

                    <div class="col-12" id="lateReasonWrap" style="display:none">
                        <label class="form-label">Late filing reason</label>
                        <input name="late_filing_reason" class="form-control" value="{{ old('late_filing_reason') }}"
                               placeholder="Required when filing sick leave after returning to work">
                    </div>

                    <div class="col-12"><label class="form-label">Purpose / other details</label>
                        <textarea name="purpose" class="form-control" rows="2">{{ old('purpose') }}</textarea></div>

                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="commutation" value="1" id="commutation" @checked(old('commutation'))>
                            <label class="form-check-label" for="commutation">Commutation requested</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold">3. Supporting documents</div>
                <div class="card-body">
                    <div id="docList" class="text-muted small mb-2">Select a leave type to see required documents.</div>
                    <div id="docUploads"></div>
                    <div class="mt-2">
                        <label class="form-label small">Additional document (optional)</label>
                        <input type="file" name="documents[supporting_document]" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header fw-semibold">Your credits</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between"><span>Vacation Leave</span><strong>{{ number_format($vlBalance, 2) }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Sick Leave</span><strong>{{ number_format($slBalance, 2) }}</strong></div>
                    <hr>
                    <label class="form-label">Applicant signature (type your full name)</label>
                    <input name="applicant_signature" class="form-control @error('applicant_signature') is-invalid @enderror"
                           value="{{ old('applicant_signature', auth()->user()->name) }}" required>
                    @error('applicant_signature')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <button class="btn btn-lgu w-100 mt-3" type="submit"><i class="bi bi-send me-1"></i>Submit application</button>
                    <p class="text-muted small mt-2 mb-0">Weekends and Philippine holidays are automatically excluded from the working-day count.</p>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
const previewUrl = '{{ route('leave.preview') }}';
const typeSelect = document.getElementById('leaveType');
const detailsWrap = document.getElementById('detailsFields');

function renderDetails() {
    const opt = typeSelect.selectedOptions[0];
    detailsWrap.innerHTML = '';
    if (!opt || !opt.value) return;
    let schema = [];
    try { schema = JSON.parse(opt.dataset.schema || '[]') || []; } catch (e) {}
    schema.forEach(function (f) {
        const id = 'detail_' + f.name;
        let field = '<div class="col-12 mb-2"><label class="form-label">' + f.label + (f.required ? ' *' : '') + '</label>';
        const name = 'details[' + f.name + ']';
        if (f.type === 'radio') {
            field += '<div>';
            for (const k in (f.options || {})) {
                field += '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="' + name + '" value="' + k + '" id="' + id + k + '"><label class="form-check-label" for="' + id + k + '">' + f.options[k] + '</label></div>';
            }
            field += '</div>';
        } else if (f.type === 'textarea') {
            field += '<textarea class="form-control" name="' + name + '" rows="2"></textarea>';
        } else if (f.type === 'checkbox') {
            field = '<div class="col-12 mb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="' + name + '" value="1" id="' + id + '"><label class="form-check-label" for="' + id + '">' + f.label + '</label></div>';
        } else {
            field += '<input class="form-control" type="' + (f.type || 'text') + '" name="' + name + '">';
        }
        field += '</div>';
        detailsWrap.insertAdjacentHTML('beforeend', field);
    });
}

function refreshPreview() {
    const typeId = typeSelect.value, s = document.getElementById('startDate').value, e = document.getElementById('endDate').value;
    if (!typeId || !s || !e) return;
    lmsFetch(previewUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ leave_type_id: typeId, start_date: s, end_date: e })
    }).then(r => r.ok ? r.json() : null).then(function (d) {
        if (!d) return;
        document.getElementById('previewBox').classList.remove('d-none');
        document.getElementById('workingDays').textContent = d.working_days;
        document.getElementById('creditWarn').textContent = d.sufficient_credits ? '' : 'Insufficient credits for this leave!';
        document.getElementById('warnList').innerHTML = (d.warnings || []).map(w => '<div class="text-warning">⚠ ' + w + '</div>').join('');
        document.getElementById('lateReasonWrap').style.display = d.requires_late_reason ? 'block' : 'none';
        const docs = d.required_documents || [];
        const list = document.getElementById('docList');
        const uploads = document.getElementById('docUploads');
        if (docs.length) {
            list.innerHTML = '<strong>Required for this leave:</strong>';
            uploads.innerHTML = docs.map(doc =>
                '<div class="mb-2"><label class="form-label small">' + doc.label + ' *</label>' +
                '<input type="file" name="documents[' + doc.type + ']" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required></div>'
            ).join('');
        } else {
            list.innerHTML = 'No mandatory documents for this leave type.';
            uploads.innerHTML = '';
        }
    });
}

typeSelect.addEventListener('change', function () { renderDetails(); refreshPreview(); });
['startDate', 'endDate'].forEach(id => document.getElementById(id).addEventListener('change', refreshPreview));
document.addEventListener('DOMContentLoaded', function () { renderDetails(); refreshPreview(); });
</script>
@endpush
@endsection
