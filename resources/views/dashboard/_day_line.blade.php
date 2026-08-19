@php
    /**
     * Headcount on leave, day by day, drawn as inline SVG.
     *
     * A line rather than columns because this is a *level* that persists — the
     * space between Monday and Tuesday is real, so a line is entitled to cross
     * it, and it still reads at thirty-one points where thirty-one bars become a
     * comb. Days already past are a solid line; approved leave still to come is
     * dashed, because that half is the only part anyone can still act on.
     *
     * No canvas and no script: the hover layer is plain HTML over the SVG, so
     * this cannot repeat the runaway-canvas bug and it survives printing.
     *
     * Expects:
     *   $days       array of ['date' => Carbon, 'count' => int, 'future' => bool]
     *   $height     plot height in px
     *   $labelEvery show an x label every Nth day (today is always labelled)
     */
    $days = array_values($days);
    $count = count($days);
    $height = $height ?? 170;
    $labelEvery = $labelEvery ?? 1;

    $peak = $count ? max(array_column($days, 'count')) : 0;
    $ceiling = max(2, $peak + 1);
    $step = $count ? 700 / $count : 700;
    $today = now()->toDateString();

    // The viewBox is stretched to the box with preserveAspectRatio="none", so
    // every stroke carries vector-effect and every dot is drawn in HTML — an
    // SVG circle would come out an ellipse.
    $point = function (int $i) use ($days, $step, $ceiling) {
        return [
            round(($i + 0.5) * $step, 1),
            round(200 - ($days[$i]['count'] / $ceiling) * 200, 1),
        ];
    };

    $line = function (array $indexes) use ($point) {
        $parts = [];
        foreach ($indexes as $i) {
            [$x, $y] = $point($i);
            $parts[] = ($parts ? 'L' : 'M')."{$x},{$y}";
        }

        return implode(' ', $parts);
    };

    $area = function (array $indexes) use ($point, $line) {
        if (! $indexes) {
            return '';
        }
        [$firstX] = $point($indexes[0]);
        [$lastX] = $point($indexes[count($indexes) - 1]);

        return $line($indexes)." L{$lastX},200 L{$firstX},200 Z";
    };

    // Split at the last day that has already happened, and let the dashed run
    // start on that same point so the two halves join instead of leaving a gap.
    $lastPast = -1;
    foreach ($days as $i => $day) {
        if (! $day['future']) {
            $lastPast = $i;
        }
    }
    $past = $lastPast >= 0 ? range(0, $lastPast) : [];
    $future = $lastPast < $count - 1 ? range(max(0, $lastPast), $count - 1) : [];
@endphp

<div class="day-plot" style="--plot-h:{{ $height }}px">
    <div class="day-axis">
        <span>{{ $ceiling }}</span>
        <span>{{ intdiv($ceiling, 2) }}</span>
        <span>0</span>
    </div>

    <div class="day-line">
        <svg class="day-svg" viewBox="0 0 700 200" preserveAspectRatio="none" aria-hidden="true">
            <line class="day-grid" x1="0" y1="100" x2="700" y2="100"/>
            @if ($past)
                <path class="day-fill" d="{{ $area($past) }}"/>
            @endif
            @if ($future)
                <path class="day-fill is-future" d="{{ $area($future) }}"/>
            @endif
            @if ($past)
                <path class="day-stroke" d="{{ $line($past) }}"/>
            @endif
            @if ($future)
                <path class="day-stroke is-future" d="{{ $line($future) }}"/>
            @endif
        </svg>

        <div class="day-hits">
            @foreach ($days as $i => $day)
                @php
                    $isToday = $day['date']->toDateString() === $today;
                    $isPeak = $peak > 0 && $day['count'] === $peak;
                    $label = $isToday ? 'Today'
                        : ($count <= 7 ? $day['date']->format('D') : $day['date']->format('j'));
                @endphp
                <span class="day-hit {{ $isToday ? 'is-today' : '' }}">
                    @if ($isToday || $isPeak)
                        <b class="day-dot {{ $day['future'] ? 'is-future' : '' }}"
                           style="--dot-y:{{ round($day['count'] / $ceiling * 100, 1) }}%"></b>
                    @endif
                    <span class="day-tip">
                        <b>{{ $day['date']->format('D j M') }}</b>
                        &middot; {{ $day['count'] }}
                        {{ $day['future'] ? 'scheduled off' : ($day['count'] === 1 ? 'employee out' : 'employees out') }}
                        @if ($isToday) &mdash; today @elseif ($isPeak) &mdash; peak @endif
                    </span>
                    @if ($isToday || $i % $labelEvery === 0)
                        <span class="day-label">{{ $label }}</span>
                    @endif
                </span>
            @endforeach
        </div>
    </div>
</div>
