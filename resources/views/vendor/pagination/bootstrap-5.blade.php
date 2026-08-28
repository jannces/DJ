@php
    /**
     * The pager every list in the system uses.
     *
     * Laravel's default spreads the whole run of page numbers across the
     * container — on the intrusion log, with 284 events, that was a row of
     * 1 2 3 4 5 6 7 8 9 10 … 28 29 stretched from edge to edge.
     *
     * This shows three numbers, centred, so the row is the same narrow width
     * whether there are three pages or three hundred. The current page sits in
     * the middle of the three, which means the numbers either side of it are
     * already previous and next — so the arrows are the jumps the numbers
     * cannot make: first page and last page.
     *
     * $elements, which Laravel computed with its own window, is deliberately
     * unused: the window is worked out here so that one file governs every
     * list and there is nothing to keep in sync.
     */
    $window = 3;
    $last = $paginator->lastPage();
    $current = $paginator->currentPage();

    // Keep the current page centred, except at the ends, where the window
    // stops rather than running off and showing blanks.
    $start = max(1, min($current - 1, $last - $window + 1));
    $end = min($last, $start + $window - 1);
@endphp

@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <ul class="pagination">
            {{-- First --}}
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">&laquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->url(1) }}" aria-label="First page">&laquo;</a>
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

            {{-- Last --}}
            @if ($current === $last)
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">&raquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->url($last) }}" aria-label="Last page">&raquo;</a>
                </li>
            @endif
        </ul>

        <p class="pager-summary">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            of {{ number_format($paginator->total()) }} results
        </p>
    </nav>
@endif
