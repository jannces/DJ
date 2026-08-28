@props([
    'id',
    'title',
    'action',
    'method' => 'POST',
    'save' => 'Save',
    'open' => false,
    'cancel' => null,
])

{{--
  A short form in a floating panel, for the pages where the record is two or
  three fields and you want to stay on the list.

  IT OPENS ITSELF WHEN THE SERVER SAYS TO, which is the part that matters. A
  rejected submission redirects back to the page it came from, and a panel that
  only opened on a click would be closed on arrival — so the errors would be
  invisible and the values would be gone. `open` is set by the page when it is
  editing a record, or when the request carries validation errors, and every
  field reads `old()` so what was typed survives the round trip.

  Not for long forms. The New User form is twenty-one fields across four
  sections; in a panel it would need its own scrollbar, lose its URL, and throw
  the lot away on a stray Escape. Those stay pages.

  WITHOUT JAVASCRIPT the panel still works. The <noscript> rule below lays it
  out as a plain card in the flow of the page — which is exactly what these
  pages looked like before — so nothing becomes unreachable if the script
  fails. That is also why the "New" button is a real link to a real URL rather
  than a bare button: with the script it opens the panel, without it the page
  loads with the panel already open.
--}}

<noscript>
    <style>
        .modal[data-open-on-load]{ display:block !important; position:static; }
        .modal[data-open-on-load] .modal-dialog{ margin:0 0 1rem; max-width:none; }
    </style>
</noscript>

<div class="modal fade" id="{{ $id }}" tabindex="-1"
     aria-labelledby="{{ $id }}-title" aria-hidden="true"
     @if ($open) data-open-on-load @endif>
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ $action }}" class="modal-content" data-no-loader>
            @csrf
            @if ($method !== 'POST') @method($method) @endif

            <div class="modal-header">
                <h5 class="modal-title" id="{{ $id }}-title">{{ $title }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                {{ $slot }}
            </div>

            <div class="modal-footer">
                {{-- Cancel is a link back to the clean list, not just a dismiss:
                     the page may have arrived at an /edit URL, and closing the
                     panel without leaving it would strand you on a URL whose
                     panel is shut. --}}
                <a href="{{ $cancel ?? url()->current() }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-lgu">{{ $save }}</button>
            </div>
        </form>
    </div>
</div>
