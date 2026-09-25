@props([
    'title',
    'sub' => null,
    'backUrl' => null,
    'backLabel' => null,
])

{{--
  The page header every screen shares: an optional way back, the title, an
  optional subtitle, and whatever buttons the page passes in its slot.

  The back link is a real <a href> to the parent, not a browser-history
  control. Several of these pages open in a new tab — the Reports "View" button
  submits with formtarget="_blank" — and a new tab has no history behind it, so
  history.back() would be dead exactly where it is needed. A link also works on
  a bookmarked URL, needs no JavaScript, and names its destination before you
  commit to the click.

  Only pages reached *from* another page get one. A back link on a screen the
  sidebar already reaches has nowhere honest to point.
--}}

<div {{ $attributes->merge(['class' => 'page-head']) }}>
    @if ($backUrl)
        <a href="{{ $backUrl }}" class="back-link">
            <i class="bi bi-arrow-left"></i><span>{{ $backLabel ?? 'Back' }}</span>
        </a>
    @endif

    <div class="page-head-row">
        <div class="page-head-main">
            <h1>{{ $title }}</h1>
            @if (filled($sub))
                <div class="sub">{{ $sub }}</div>
            @endif
        </div>

        @if (! $slot->isEmpty())
            <div class="page-head-actions">{{ $slot }}</div>
        @endif
    </div>
</div>
