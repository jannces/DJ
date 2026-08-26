@php
    /**
     * A line over a long, evenly spaced run — twelve months, or twenty-eight
     * days. Inline SVG: no canvas, no script, and it prints.
     *
     * THE AXIS IS LABELLED, vertically on the left and horizontally beneath.
     * A line with no numbers on it says only "up" and "down".
     *
     * The scale rounds UP to a whole step rather than stopping at the tallest
     * point, so the ticks land on round numbers and the top gridline is one of
     * them. Counts have no half, so the step is forced to a whole number —
     * otherwise a near-empty month labels its axis 1 · 0.5 · 0.
     *
     * The x labels are positioned at each point's own x, not centred in
     * equal-width slots. With twenty-eight points and four labels a slot-centred
     * label sits at (i+0.5)/n while its point sits at i/(n-1) — invisible at
     * twelve points, and off by most of a week at twenty-eight.
     *
     * Expects:
     *   $series ['labels' => [...], 'data' => [...]]   '' skips a label
     *   $peakLabel string  what to call the high point ('peak', 'high')
     */
    $data = array_map('intval', $series['data']);
    $labels = $series['labels'];
    $n = max(1, count($data));
    $peak = $data ? max($data) : 0;

    // Four bands, ending on a round number, so the axis reads 400/300/200/100/0
    // rather than 304/152/0. Three bands leaves a peak of 304 labelled only
    // 400 · 200 · 0, which is a scale nobody can read a value off.
    $step = 1;
    foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000] as $candidate) {
        $step = $candidate;
        if ($candidate * 4 >= $peak) {
            break;
        }
    }
    $top = max($step, (int) (ceil($peak / $step) * $step));

    $ticks = [];
    for ($v = $top; $v >= 0; $v -= $step) {
        $ticks[] = $v;
    }

    $x = fn ($i) => $n > 1 ? round($i / ($n - 1) * 100, 3) : 0;
    $y = fn ($v) => round(100 - ($v / $top) * 100, 3);

    $points = [];
    foreach ($data as $i => $value) {
        $points[] = $x($i).','.$y($value);
    }

    $peakAt = $peak > 0 ? array_search($peak, $data, true) : null;
@endphp

<div class="ln-wrap">
    <div class="ln-y">
        @foreach ($ticks as $tick)<span>{{ $tick }}</span>@endforeach
    </div>
    <div class="ln">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            @foreach ($ticks as $tick)
                <line class="gl" x1="0" x2="100" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}"/>
            @endforeach
            <line class="ax" x1="0" x2="100" y1="100" y2="100"/>
            <polyline class="p1" points="{{ implode(' ', $points) }}" vector-effect="non-scaling-stroke"/>
        </svg>
        @if ($peakAt !== null)
            {{-- Above the point normally; below it when the peak sits on the
                 top gridline, where "above" is outside the card and would land
                 the label on the panel title. And nudged in at either end,
                 where a centred label hangs off the edge of the plot. --}}
            @php
                $peakY = $y($peak);
                $below = $peakY < 20;
                $edge = $peakAt === 0 ? ' data-first' : ($peakAt === $n - 1 ? ' data-last' : '');
            @endphp
            <div class="ln-peak" style="left:{{ $x($peakAt) }}%;top:{{ $below ? $peakY + 7 : $peakY - 17 }}%" {!! $edge !!}>
                <span class="ln-lab">{{ $peakLabel ?? 'peak' }} {{ $peak }}</span>
            </div>
        @endif
    </div>
</div>
<div class="ln-x">
    @foreach ($labels as $i => $label)
        @continue($label === '')
        <span style="left:{{ $x($i) }}%"
              @if ($i === 0) data-first @elseif ($i === $n - 1) data-last @endif>{{ $label }}</span>
    @endforeach
</div>
