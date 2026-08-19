@php
    /**
     * A bar chart laid out sideways: one row per entry, bars running left to
     * right, the scale along the bottom.
     *
     * The same data as the column chart, turned ninety degrees for the case
     * that chart cannot serve — a long list of long names. Sixteen leave types
     * under sixteen columns leaves room for a three-letter code and nothing
     * else; as rows, each one gets its full name on its own line and the list
     * simply grows downward.
     *
     * Colour is keyed to the row's identity, not its rank, so an entry keeps
     * its colour when the ranking changes between windows. It is decorative
     * here — every row carries its name and its count in text.
     *
     * Expects:
     *   $rows  array of ['label' => short, 'name' => full, 'value' => int,
     *                    'note' => string|null, 'tone' => int|null,
     *                    'muted' => bool]
     */
    $rows = array_values($rows);
    $peak = $rows ? max(array_column($rows, 'value')) : 0;

    // Four equal bands ending on a round number, the same rule the column
    // charts use, so the two read on the same kind of scale.
    $step = 1;
    foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000] as $candidate) {
        $step = $candidate;
        if ($candidate * 4 >= $peak) {
            break;
        }
    }
    $ceiling = max(4, $step * 4);
@endphp

@forelse ($rows as $row)
    <div class="hbar {{ ! empty($row['muted']) ? 'is-muted' : 'tone-'.($row['tone'] ?? 0) }} {{ $row['value'] === 0 ? 'is-zero' : '' }}">
        <span class="hbar-name" title="{{ $row['name'] }}">
            <b>{{ $row['label'] }}</b>{{ $row['name'] }}
        </span>
        <span class="hbar-track">
            <span class="hbar-fill" style="width:{{ round($row['value'] / $ceiling * 100, 1) }}%"></span>
        </span>
        <span class="hbar-value">{{ $row['value'] }}</span>
        <span class="chart-tip">
            <b>{{ $row['name'] }}</b> &middot; {{ $row['value'] }}
            {{ $row['value'] === 1 ? 'application' : 'applications' }}
            @if (! empty($row['note']))<br>{{ $row['note'] }}@endif
        </span>
    </div>
@empty
    <div class="dash-empty">Nothing on record for this period.</div>
@endforelse

@if ($rows)
    <div class="hbar hbar-scale">
        <span></span>
        <span class="hbar-ticks">
            @for ($i = 0; $i <= 4; $i++)
                <i>{{ (int) ($ceiling / 4 * $i) }}</i>
            @endfor
        </span>
        <span></span>
    </div>
@endif
