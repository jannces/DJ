@php
    /**
     * Expects: $record — the department being edited, or null when creating.
     *          $heads  — users holding the Department Head role.
     */
    $suffix = $record?->id ?? 'new';
@endphp

<div class="mb-3">
    <label class="form-label" for="dept-name-{{ $suffix }}">Name <span class="req">*</span></label>
    <input id="dept-name-{{ $suffix }}" name="name" required maxlength="150"
           class="form-control @error('name') is-invalid @enderror"
           value="{{ old('name', $record->name ?? '') }}">
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label class="form-label" for="dept-code-{{ $suffix }}">Code <span class="req">*</span></label>
    <input id="dept-code-{{ $suffix }}" name="code" required maxlength="20"
           class="form-control @error('code') is-invalid @enderror"
           value="{{ old('code', $record->code ?? '') }}">
    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-0">
    <label class="form-label" for="dept-head-{{ $suffix }}">Department Head</label>
    <select id="dept-head-{{ $suffix }}" name="head_user_id"
            class="form-select @error('head_user_id') is-invalid @enderror">
        <option value="">— none —</option>
        @foreach ($heads as $h)
            <option value="{{ $h->id }}" @selected(old('head_user_id', $record->head_user_id ?? null) == $h->id)>
                {{ $h->name }}
            </option>
        @endforeach
    </select>
    @error('head_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    {{-- This is the field the leave workflow reads: the head named here is the
         one who recommends their office's applications. --}}
    <div class="form-text">Recommends leave for this office before it reaches the Mayor or HR.</div>
</div>
