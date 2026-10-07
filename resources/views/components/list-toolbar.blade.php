@props([
    'search' => null,
    'action' => null,
    'placeholder' => 'Search',
])

{{--
  What narrows a list, inside the container, above the rows it narrows.

  It stays a real GET form with a real submit button: with the script, the
  button is removed and the toolbar asks the server as you type; without it,
  the button submits and the page reloads exactly as it always did. The search
  runs on the server rather than hiding rows on screen, so it covers every
  record rather than the ten currently paged in — hiding rows would answer "no
  matches" for someone sitting on page three.
--}}

<form method="GET" action="{{ $action ?? url()->current() }}"
      class="list-toolbar" data-no-loader data-live-filter>

    @if ($search)
        <input type="search" name="q" value="{{ request('q') }}"
               class="form-control form-control-sm toolbar-search"
               placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
               autocomplete="off">
    @endif

    <div class="toolbar-filters">
        {{ $slot }}
        <button class="btn btn-sm btn-lgu toolbar-submit">Filter</button>
        @if (collect(request()->query())->except('page')->filter(fn ($v) => $v !== '')->isNotEmpty())
            <a href="{{ $action ?? url()->current() }}" class="btn btn-sm btn-link toolbar-clear">Clear</a>
        @endif
    </div>
</form>
