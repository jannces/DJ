@php
    /**
     * Horizontal bars: the category on the left, the bar, the value at its end.
     *
     * Sideways because the lists here are long and the names are long with them
     * — fifteen leave types under fifteen columns leaves room for a three-letter
     * code and nothing else. As rows each one gets its full name and the list
     * grows downward.
     *
     * ZERO-BASED, always. Every bar is a share of the largest value in the set,
     * so a bar twice as long means twice as many.
     *
     * ONE HERO. The top row is the system's violet, everything else is grey.
     * The question these charts answer is "which is the most", and sort order
     * already encodes the ranking — painting each row its own colour would
     * repeat the ranking in a second channel and imply a category difference
     * that is not there. It would also repaint the whole chart every time the
     * ranking moved between the month and the year view.
     *
     * NO GRIDLINES. Each bar prints its value at its end, so a grid measures
     * nothing the number does not already say.
     *
     * Expects:
     *   $rows      array of ['label' => string, 'value' => int,
     *                        'name' => string?, 'blocked' => bool?, 'source' => string?]
     *              `name` is what shows when present — the full leave type or
     *              office, since the row has width for it that a column chart
     *              never did. `label` is the fallback for rows that only have
     *              one form, like an address or a route.
     *   $markZeros bool   draw a labelled rule where the zeros begin
     *   $mono      bool   the labels are addresses or routes, not prose
     *   $empty     string what to say when there is nothing at all
     *   $series    bool   colour each row by its own identity instead of the
     *                     hero/grey rule. True in exactly one place — attacks
     *                     by type, where the type IS the subject and the three
     *                     hues were validated as a categorical set. Anywhere
     *                     else it would be decoration.
     */
    $rows = array_values($rows);
    $peak = $rows ? max(array_column($rows, 'value')) : 0;
    $markZeros = $markZeros ?? false;
    $mono = $mono ?? false;
    $series = $series ?? false;
    $zeroAt = null;

    if ($markZeros) {
        foreach ($rows as $i => $row) {
            if ($row['value'] === 0) {
                $zeroAt = $i;
                break;
            }
        }
    }
@endphp

@if ($rows)
    <div class="hb">
        @foreach ($rows as $i => $row)
            @if ($zeroAt !== null && $i === $zeroAt && $i > 0)
                <div class="hb-zero"><span>none applied for below</span></div>
            @endif

            <div class="hb-r"
                 @if ($series) data-series="{{ $row['key'] }}"
                 @elseif ($i === 0 && $row['value'] > 0) data-hero @endif>
                <span class="hb-l {{ $mono ? 'hb-mono' : '' }}" title="{{ $row['name'] ?? $row['label'] }}">
                    {{ $row['name'] ?? $row['label'] }}
                    @isset ($row['blocked'])
                        <span class="tag-s {{ $row['blocked'] ? 'tag-blocked' : 'tag-open' }}">
                            {{ $row['blocked'] ? 'blocked' : 'open' }}
                        </span>
                    @endisset
                </span>
                <span class="hb-t">
                    <span class="hb-f" style="width:{{ $peak > 0 ? round($row['value'] / $peak * 100, 1) : 0 }}%"></span>
                </span>
                <span class="hb-v">{{ $row['value'] }}</span>
            </div>

            @if (! empty($row['source']))
                <p class="hb-sub">{{ $row['source'] }}</p>
            @endif
        @endforeach
    </div>
@else
    <p class="dash-empty">{{ $empty ?? 'Nothing on record for this period.' }}</p>
@endif
