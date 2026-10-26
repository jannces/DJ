@php
    /**
     * The pager for the logs.
     *
     * Audit, activity and intrusion logs are cursor-paginated, so there are no
     * page numbers to offer: a cursor anchors to a row rather than to a
     * position, which is the whole point of using one here. New events arrive
     * at the top of these lists constantly, and with OFFSET every arrival
     * pushes the list down — so page 2 re-shows rows already read on page 1,
     * or skips past them. On a security log somebody is reading through to
     * review, silently skipping an event is the outcome that matters.
     *
     * Newer and Older rather than Previous and Next: these lists are ordered
     * newest first, and "previous" is ambiguous about which end it means.
     */
@endphp

@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link">&laquo; Newer</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; Newer</a>
                </li>
            @endif

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Older &raquo;</a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link">Older &raquo;</span>
                </li>
            @endif
        </ul>

        <p class="pager-summary">
            Newest first. Pages anchor to an event, so new arrivals cannot
            shift one out of view.
        </p>
    </nav>
@endif
