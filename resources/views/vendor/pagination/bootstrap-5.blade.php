@php
    /**
     * The pager every offset-paginated list in the system uses.
     *
     * Laravel's default rendered the whole run of page numbers, which on the
     * intrusion log meant 1 2 3 4 5 6 7 8 9 10 … 28 29 stretched across the
     * container. This truncates to the first page, the last page, the current
     * one and its immediate neighbours, with an ellipsis standing in for each
     * gap — so the row is a fixed width whether there are three pages or three
     * hundred, and both ends stay one click away.
     *
     * An earlier version showed a fixed block of three, 1 2 3 then 4 5 6. It
     * had to be abandoned: with the ends never shown, the arrows had to step a
     * whole block, so on any list of three pages or fewer there was no next
     * block and BOTH arrows were dead on every page — including page one, with
     * page two plainly sitting next to it. Most lists here are under thirty
     * rows, so that was most lists.
     *
     * The arrows step one page and are disabled only at the true ends.
     *
     * $elements, which Laravel computed with its own window, is deliberately
     * unused: the window is worked out here so one file governs every list and
     * there is nothing to keep in sync.
     */
    $last = $paginator->lastPage();
    $current = $paginator->currentPage();
    $around = 1;

    $wanted = [1, $last];
    for ($p = $current - $around; $p <= $current + $around; $p++) {
        if ($p >= 1 && $p <= $last) {
            $wanted[] = $p;
        }
    }
    $wanted = array_values(array_unique($wanted));
    sort($wanted);

    // Walk the kept pages and fill each gap. A gap of exactly one page prints
    // that page instead of an ellipsis: "1 … 3" is wider than "1 2 3" and
    // hides a page behind a click for nothing.
    $slots = [];
    foreach ($wanted as $i => $page) {
        $previous = $wanted[$i - 1] ?? null;
        if ($previous !== null && $page - $previous > 1) {
            $slots[] = $page - $previous === 2 ? $previous + 1 : null;
        }
        $slots[] = $page;
    }
@endphp

@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <ul class="pagination">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">&laquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}"
                       rel="prev" aria-label="{{ __('Previous page') }}">&laquo;</a>
                </li>
            @endif

            @foreach ($slots as $slot)
                @if ($slot === null)
                    <li class="page-item disabled" aria-hidden="true">
                        <span class="page-link page-gap">&hellip;</span>
                    </li>
                @elseif ($slot === $current)
                    <li class="page-item active" aria-current="page">
                        <span class="page-link">{{ $slot }}</span>
                    </li>
                @else
                    <li class="page-item">
                        <a class="page-link" href="{{ $paginator->url($slot) }}"
                           aria-label="{{ __('Go to page :page', ['page' => $slot]) }}">{{ $slot }}</a>
                    </li>
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}"
                       rel="next" aria-label="{{ __('Next page') }}">&raquo;</a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link" aria-hidden="true">&raquo;</span>
                </li>
            @endif
        </ul>

        <p class="pager-summary">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            of {{ number_format($paginator->total()) }} results
        </p>
    </nav>
@endif
