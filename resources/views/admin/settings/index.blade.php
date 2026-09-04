@extends('layouts.app')
@section('title', 'System Settings')
@section('content')

{{--
  One panel per group, one row per setting: the name and what it does on the
  left, the control that changes it on the right.

  The old layout was a 4/8 Bootstrap grid, which put every control in a column
  of its own and left a switch stranded in the middle of a wide empty cell. A
  settings row reads better as a list: the eye goes down the names looking for
  the one it wants, and the controls line up on the right where the hand is.

  Every control is now labelled. The old markup had a <label> with no `for`
  beside an input with no id, so a screen reader announced "checkbox" and
  clicking the text did nothing -- on a page whose entire content is controls.
--}}

<h1 class="h4 mb-3">System Settings</h1>

<form method="POST" action="{{ route('settings.update') }}">
    @csrf @method('PUT')

    @foreach ($groups as $group => $settings)
        <div class="card set-card">
            <div class="set-head">
                <h2 class="set-group">{{ ucfirst(str_replace('_', ' ', $group)) }}</h2>
                <p class="set-count">{{ trans_choice(':count setting|:count settings', count($settings)) }}</p>
            </div>

            <div class="set-rows">
                @foreach ($settings as $s)
                    @php
                        $field = str_replace('.', '__', $s->key);
                        $id = 'set-'.$field;
                    @endphp
                    <div class="set-row">
                        <label class="set-copy" for="{{ $id }}">
                            <span class="set-name">{{ $s->description ?? $s->key }}</span>
                            <code class="set-key">{{ $s->key }}</code>
                        </label>

                        <div class="set-control">
                            @if ($s->type === 'bool')
                                {{-- No hidden companion field. An unchecked box
                                     posts nothing, and SettingController reads
                                     these with $request->boolean(), which is
                                     already false for a key that is absent. --}}
                                <span class="set-switch">
                                    <input class="form-check-input" type="checkbox" id="{{ $id }}"
                                           name="{{ $field }}" value="1" @checked($s->value == '1')>
                                </span>
                            @else
                                <input id="{{ $id }}" name="{{ $field }}" value="{{ $s->value }}"
                                       class="form-control form-control-sm set-input"
                                       type="{{ $s->type === 'int' ? 'number' : 'text' }}"
                                       inputmode="{{ $s->type === 'int' ? 'numeric' : 'text' }}">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <button class="btn btn-lgu">Save settings</button>
</form>
@endsection
