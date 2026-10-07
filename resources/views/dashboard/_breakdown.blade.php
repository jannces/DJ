@php
    /**
     * The totals behind the lines, beside them.
     *
     * A line chart says the shape; it does not say the size, and reading a
     * total off five overlapping lines means adding twelve points by eye. This
     * is the same data as a list, which is also the table view the palette's
     * two low-contrast hues oblige.
     *
     * Ordered by size rather than by slot. The colours are fixed to the type,
     * so ranking the list cannot repaint the chart.
     *
     * Expects: $chart from LeaveTypeSeries
     */
@endphp

<ul>
    @foreach ($chart['breakdown'] as $line)
        <li>
            <span class="dn-dot" data-k="{{ $line['key'] }}"></span>
            <span class="ml-side-n">{{ $line['name'] }}</span>
            <span class="ml-side-v">
                <span class="ml-side-t">{{ number_format($line['total']) }}</span>
                @if ($line['delta'] !== null)
                    {{-- Against the same period last year, and only when there
                         was one. A rise from a base of one application is not a
                         percentage; it is a number that reads as though it
                         means a great deal. --}}
                    <span class="ml-side-d"
                          @if ($line['delta'] > 0) data-up @elseif ($line['delta'] < 0) data-down @endif>
                        {{ $line['delta'] > 0 ? '+' : '' }}{{ $line['delta'] }}% {{ $line['compared_with'] }}
                    </span>
                @elseif ($line['compared_with'] !== '')
                    <span class="ml-side-d">none {{ $line['compared_with'] }}</span>
                @endif
            </span>
        </li>
    @endforeach
</ul>
