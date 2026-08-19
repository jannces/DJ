@php
    /**
     * A pie chart, drawn as inline SVG — no canvas, no script.
     *
     * Part-to-whole with three slices is the one job a pie does well: the
     * question is "what proportion of applications ended up approved", and the
     * slices are the whole year. It is deliberately not asked to carry any
     * second dimension — the month-by-month detail lives in the table beneath
     * it, where a reader can actually compare numbers.
     *
     * Slices are ordered largest first from twelve o'clock, so the eye starts
     * where the quantity is. The percentages sit *outside* the wedges in the
     * ordinary text colour: white on the amber slice is 2.4:1, which fails at
     * this size, and tinting the text to match its slice would make identity
     * depend on colour. The legend repeats every count beside its swatch and
     * the table carries the months, so nothing here is read by colour alone.
     *
     * Expects:
     *   $slices  array of ['key' => slug, 'label' => string, 'value' => int]
     *   $total   int
     *   $size    diameter in px
     */
    $size = $size ?? 210;
    $slices = array_values(array_filter($slices, fn ($s) => $s['value'] > 0));
    usort($slices, fn ($a, $b) => $b['value'] <=> $a['value']);

    // Centre 50,50, radius 46. The viewBox is padded well beyond that so the
    // labels have room to sit outside the circle. Angles run clockwise from
    // twelve o'clock.
    $at = function (float $degrees, float $radius) {
        $r = deg2rad($degrees);

        return [
            round(50 + $radius * sin($r), 2),
            round(50 - $radius * cos($r), 2),
        ];
    };

    $drawn = [];
    $cursor = 0.0;
    foreach ($slices as $slice) {
        $sweep = $total > 0 ? $slice['value'] / $total * 360 : 0;
        [$x1, $y1] = $at($cursor, 46);
        [$x2, $y2] = $at($cursor + $sweep, 46);
        [$lx, $ly] = $at($cursor + $sweep / 2, 58);

        $drawn[] = $slice + [
            // A single slice covering the whole circle has identical start and
            // end points, which collapses an arc to nothing — draw a circle.
            'whole' => $sweep >= 359.99,
            'path' => 'M50,50 L'.$x1.','.$y1
                .' A46,46 0 '.($sweep > 180 ? 1 : 0).' 1 '.$x2.','.$y2.' Z',
            'share' => $total > 0 ? round($slice['value'] / $total * 100) : 0,
            'label_x' => $lx,
            'label_y' => $ly,
            'sweep' => $sweep,
        ];
        $cursor += $sweep;
    }
@endphp

@if ($drawn)
    <div class="pie-wrap">
        <svg class="pie" viewBox="-18 -18 136 136" style="width:{{ $size }}px;height:{{ $size }}px"
             role="img" aria-label="{{ $total }} applications by outcome">
            @foreach ($drawn as $slice)
                @if ($slice['whole'])
                    <circle class="pie-slice slice-{{ $slice['key'] }}" cx="50" cy="50" r="46"/>
                @else
                    <path class="pie-slice slice-{{ $slice['key'] }}" d="{{ $slice['path'] }}"/>
                @endif
            @endforeach

            {{-- Below a twelfth of the circle the labels start colliding; those
                 slices are read from the legend instead. --}}
            @foreach ($drawn as $slice)
                @if ($slice['sweep'] >= 30)
                    <text class="pie-label" x="{{ $slice['label_x'] }}" y="{{ $slice['label_y'] }}"
                          text-anchor="middle" dominant-baseline="central">{{ $slice['share'] }}%</text>
                @endif
            @endforeach
        </svg>

        <div class="pie-key">
            <div class="pie-total">
                <span class="pie-total-value">{{ number_format($total) }}</span>
                <span class="pie-total-label">applications filed</span>
            </div>
            @foreach ($drawn as $slice)
                <div class="pie-row">
                    <span class="pie-dot key-{{ $slice['key'] }}"></span>
                    <span class="pie-name">{{ $slice['label'] }}</span>
                    <span class="pie-value">{{ $slice['value'] }}</span>
                    <span class="pie-share">{{ $slice['share'] }}%</span>
                </div>
            @endforeach
        </div>
    </div>
@else
    <div class="dash-empty">No applications filed in {{ now()->year }} yet.</div>
@endif
