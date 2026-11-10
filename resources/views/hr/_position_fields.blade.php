@php
    /**
     * The position fields, shared by the New panel and every Edit panel.
     *
     * `old()` first in every value, so a rejected submission comes back with
     * what was typed rather than what was stored — which is the whole reason
     * the panel reopens itself.
     *
     * Expects: $record — the position being edited, or null when creating.
     */
    $suffix = $record?->id ?? 'new';
@endphp

<div class="mb-3">
    <label class="form-label" for="pos-title-{{ $suffix }}">Title <span class="req">*</span></label>
    <input id="pos-title-{{ $suffix }}" name="title" required maxlength="150"
           class="form-control @error('title') is-invalid @enderror"
           value="{{ old('title', $record->title ?? '') }}">
    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-0">
    <label class="form-label" for="pos-sg-{{ $suffix }}">Salary grade</label>
    <input id="pos-sg-{{ $suffix }}" name="salary_grade" maxlength="10"
           class="form-control @error('salary_grade') is-invalid @enderror"
           value="{{ old('salary_grade', $record->salary_grade ?? '') }}">
    @error('salary_grade')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
