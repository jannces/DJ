@php
    /**
     * A plain vertical bar chart: one column per row, a scaled y axis, the
     * value above each column and the short label beneath it.
     *
     * Bars because these are discrete counts per bucket — a leave type nobody
     * used, or a department that filed nothing, belongs as an absent column
     * rather than a line dipping to the floor and back.
     *
     * One hue per chart, not one per column. The column height already carries
     * the magnitude, so colouring each column differently would imply a
     * category difference that is not there — and it would repaint the
     * survivors every time the ranking changed between the month and year
     * views. The hue instead identifies the panel.
     *
     * Expects:
     *   $rows    array of ['label' => short, 'name' => full, 'value' => int,
     *                      'note' => string|null, 'muted' => bool]
     *   $accent  'cyan' | 'amber' | 'violet' — the panel's hue
     *   $height  plot height in px
     */
    $rows = array_values($rows);
    $height = $height ?? 210;
    $accent = $accent ?? 'violet';

    $peak = $rows ? max(array_column($rows, 'value')) : 0;

    // A readable ceiling: four equal bands ending on a round number, so the
    // axis reads 200/150/100/50/0 rather than 147/110/74/37/0.
    $step = 1;
    foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000] as $candidate) {
        $step = $candidate;
        if ($candidate * 4 >= $peak) {
            break;
        }
    }
    $ceiling = max(4, $step * 4);
@endphp

<div class="bar-plot accent-{{ $accent }}" style="--plot-h:{{ $height }}px">
    <div class="day-axis">
        @for ($i = 4; $i >= 0; $i--)
            <span>{{ (int) ($ceiling / 4 * $i) }}</span>
        @endfor
    </div>

    <div class="bar-cols">
        @forelse ($rows as $row)
            <div class="bar-col {{ ! empty($row['muted']) ? 'is-muted' : '' }}">
                <span class="day-tip">
                    <b>{{ $row['name'] }}</b> &middot; {{ $row['value'] }}
                    {{ $row['value'] === 1 ? 'application' : 'applications' }}
                    @if (! empty($row['note']))<br>{{ $row['note'] }}@endif
                </span>
                <span class="bar-value">{{ $row['value'] }}</span>
                <span class="bar-fill" style="height:{{ round($row['value'] / $ceiling * 100, 1) }}%"></span>
                <span class="day-label" title="{{ $row['name'] }}">{{ $row['label'] }}</span>
            </div>
        @empty
            <div class="dash-empty">Nothing on record for this period.</div>
        @endforelse
    </div>
</div>
