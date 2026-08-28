@php
    /**
     * The pager every list in the system uses.
     *
     * Laravel's default spreads the whole run of page numbers across the
     * container — on the intrusion log, with 284 events, that was a row of
     * 1 2 3 4 5 6 7 8 9 10 … 28 29 stretched from edge to edge.
     *
     * This shows three numbers, centred, so the row is the same narrow width
     * whether there are three pages or three hundred.
     *
     * The three are a fixed block rather than a window sliding around the
     * current page: 1 2 3, then 4 5 6, then 7 8 9. A sliding window renumbers
     * itself on every step — go from 3 to 4 and the row silently becomes
     * 3 4 5 — so the same position on screen means a different page each time
     * you look. A block stays put until you leave it, and the arrows are what
     * move you to the next one.
     *
     * $elements, which Laravel computed with its own window, is deliberately
     * unused: the window is worked out here so that one file governs every
     * list and there is nothing to keep in sync.
     */
    $size = 3;
    $last = $paginator->lastPage();
    $current = $paginator->currentPage();

    $block = (int) ceil($current / $size);
    $start = ($block - 1) * $size + 1;
    $end = min($last, $start + $size - 1);

    // One click per block, so the numbers advance by three rather than by one.
    $previous = max(1, $start - $size);
    $next = min($last, $start + $size);
@endphp

@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <ul class="pagination">
            {{-- Back a block --}}
            @if ($start === 1)
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">&laquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->url($previous) }}"
                       aria-label="Previous {{ $size }} pages">&laquo;</a>
                </li>
            @endif

            @for ($page = $start; $page <= $end; $page++)
                @if ($page === $current)
                    <li class="page-item active" aria-current="page">
                        <span class="page-link">{{ $page }}</span>
                    </li>
                @else
                    <li class="page-item">
                        <a class="page-link" href="{{ $paginator->url($page) }}"
                           aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                    </li>
                @endif
            @endfor

            {{-- On a block --}}
            @if ($end >= $last)
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">&raquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->url($next) }}"
                       aria-label="Next {{ $size }} pages">&raquo;</a>
                </li>
            @endif
        </ul>

        <p class="pager-summary">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            of {{ number_format($paginator->total()) }} results
        </p>
    </nav>
@endif
