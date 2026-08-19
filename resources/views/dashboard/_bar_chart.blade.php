@php
    /**
     * A plain vertical bar chart: one column per row, a scaled y axis, the
     * value above each column and the short label beneath it.
     *
     * Bars because these are discrete counts per bucket — a leave type nobody
     * used, or a department that filed nothing, belongs as an absent column
     * rather than a line dipping to the floor and back.
     *
     * Each column carries its own colour, keyed to the row's identity rather
     * than to its rank — so a leave type keeps its colour when the ranking
     * shifts between the month and the year view, instead of the whole chart
     * repainting. Colour is decorative here, not the encoding: every column is
     * labelled with its code and its count, and the eight slots wrap, so two
     * distant columns may share a hue without any ambiguity.
     *
     * Expects:
     *   $rows    array of ['label' => short, 'name' => full, 'value' => int,
     *                      'note' => string|null, 'tone' => int|null,
     *                      'muted' => bool]
     *   $height  plot height in px
     */
    $rows = array_values($rows);
    $height = $height ?? 210;

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

<div class="bar-plot" style="--plot-h:{{ $height }}px">
    <div class="chart-axis">
        @for ($i = 4; $i >= 0; $i--)
            <span>{{ (int) ($ceiling / 4 * $i) }}</span>
        @endfor
    </div>

    <div class="bar-cols">
        @forelse ($rows as $row)
            <div class="bar-col {{ ! empty($row['muted']) ? 'is-muted' : 'tone-'.($row['tone'] ?? 0) }} {{ $row['value'] === 0 ? 'is-zero' : '' }}">
                <span class="chart-tip">
                    <b>{{ $row['name'] }}</b> &middot; {{ $row['value'] }}
                    {{ $row['value'] === 1 ? 'application' : 'applications' }}
                    @if (! empty($row['note']))<br>{{ $row['note'] }}@endif
                </span>
                <span class="bar-value">{{ $row['value'] }}</span>
                <span class="bar-fill" style="height:{{ round($row['value'] / $ceiling * 100, 1) }}%"></span>
                <span class="chart-label" title="{{ $row['name'] }}">{{ $row['label'] }}</span>
            </div>
        @empty
            <div class="dash-empty">Nothing on record for this period.</div>
        @endforelse
    </div>
</div>
