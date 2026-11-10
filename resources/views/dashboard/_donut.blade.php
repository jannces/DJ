@php
    /**
     * Share of a whole, as a ring with the total in the middle.
     *
     * A ring, not a pie: the hole is where the total goes, and the total is
     * half the answer. "Sick Leave is 34%" is a different statement depending
     * on whether the office filed 32 applications or 3,200.
     *
     * The arcs are stroke-dasharray on one circle, so there is no path
     * arithmetic and no library. Circumference is 100 by choice -- r =
     * 100/2pi -- which makes every dash length a percentage exactly as it comes
     * out of the service.
     *
     * Each slice loses a sliver to a gap so neighbouring colours never touch.
     * Two fills meeting flush read as one shape at small sizes, and the pair
     * most likely to meet is the pair a colour-blind reader can least separate.
     *
     * Hovering either the arc or its legend row lifts that slice and shows its
     * figures; the rest fade. Done with :has() rather than script -- the same
     * mechanism as the tab and period switches on this page.
     *
     * Expects:
     *   $ring  ['total' => int, 'slices' => [['key','name','value','share','offset']]]
     *   $unit  what the total counts ('applications')
     *   $empty what to say when nothing was filed
     */
    $slices = $ring['slices'] ?? [];
    $total = $ring['total'] ?? 0;
    // Circumference 100: r = 100 / (2 * pi). Every dash is then a percentage.
    $r = round(100 / (2 * M_PI), 4);
    $gap = 0.7;
@endphp

@if ($slices)
    <div class="dn-wrap">
        <div class="dn">
            <svg viewBox="0 0 40 40" role="img"
                 aria-label="Share by leave type. {{ $total }} {{ $unit ?? 'applications' }} in total.">
                <circle class="dn-track" cx="20" cy="20" r="{{ $r }}"/>
                @foreach ($slices as $slice)
                    @php
                        // A slice narrower than the gap would render as a
                        // negative dash, which draws the whole ring.
                        $len = max(0.35, $slice['share'] - $gap);
                    @endphp
                    <circle class="dn-arc" data-k="{{ $slice['key'] }}" data-i="{{ $loop->index }}"
                            cx="20" cy="20" r="{{ $r }}"
                            stroke-dasharray="{{ round($len, 3) }} {{ round(100 - $len, 3) }}"
                            stroke-dashoffset="{{ round(25 - $slice['offset'], 3) }}">
                        <title>{{ $slice['name'] }}: {{ $slice['value'] }} ({{ $slice['share'] }}%)</title>
                    </circle>
                @endforeach
            </svg>
            <div class="dn-mid">
                <p class="dn-total">{{ number_format($total) }}</p>
                <p class="dn-unit">{{ $unit ?? 'applications' }}</p>
            </div>
            {{-- One readout per slice, stacked in the hole. Only the hovered
                 one is shown, so the total gives way to the detail rather than
                 a tooltip covering the ring it is describing. --}}
            @foreach ($slices as $slice)
                <div class="dn-read" data-i="{{ $loop->index }}">
                    <p class="dn-total">{{ number_format($slice['value']) }}</p>
                    <p class="dn-unit">{{ $slice['share'] }}% · {{ $slice['name'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- The legend is also the table: name, share bar, percentage. Two of
             the five hues sit under 3:1 against a white card, so the figures
             are written out rather than left to colour alone. --}}
        <ul class="dn-key">
            @foreach ($slices as $slice)
                <li data-i="{{ $loop->index }}">
                    <span class="dn-dot" data-k="{{ $slice['key'] }}"></span>
                    <span class="dn-name" title="{{ $slice['name'] }}">{{ $slice['name'] }}</span>
                    <span class="dn-bar"><span data-k="{{ $slice['key'] }}"
                          style="width:{{ $slice['share'] }}%"></span></span>
                    <span class="dn-pct">{{ round($slice['share']) }}%</span>
                    <span class="dn-n">{{ $slice['value'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@else
    <p class="dash-empty">{{ $empty ?? 'Nothing filed in this period.' }}</p>
@endif
