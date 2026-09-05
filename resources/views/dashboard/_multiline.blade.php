@php
    /**
     * One line per leave type over an evenly spaced run, with a breakdown of
     * the period beside it.
     *
     * The single line this replaces showed filings rising in June without
     * saying what kind. Vacation climbing into the school holidays is a plan
     * being made; Sick Leave climbing is an outbreak. They drew the same line.
     *
     * ONE SCALE for every series. A second axis would let any two lines be made
     * to cross by choosing the scales, which is a picture of the scales rather
     * than of the leave.
     *
     * Straight segments, not curves. A spline through monthly counts invents
     * values between the months -- it dips below zero between two small
     * figures, and bulges above the peak on either side of it. The reference
     * this follows is smoothed; the smoothing is the one thing not copied,
     * because those in-between heights are not data.
     *
     * Hovering anywhere in a month's column raises a rule at that month and
     * lists every series' figure for it. The columns are the full height of the
     * plot, so the target is the width of a month rather than a 4px dot.
     *
     * Expects:
     *   $chart ['labels','top','series' => [['key','name','points',...]]]
     *   $id    unique per instance — the hover rules are scoped by it
     */
    $labels = $chart['labels'];
    $series = $chart['series'];
    $top = max(1, $chart['top']);
    $n = max(1, count($labels));

    $x = fn ($i) => $n > 1 ? round($i / ($n - 1) * 100, 3) : 50;
    $y = fn ($v) => round(100 - ($v / $top) * 100, 3);

    $ticks = [];
    for ($t = 4; $t >= 0; $t--) {
        $ticks[] = (int) round($top / 4 * $t);
    }
    $ticks = array_values(array_unique($ticks));
@endphp

<div class="ml" id="{{ $id }}">
    <div class="ml-plot">
        <div class="ln-y">
            @foreach ($ticks as $tick)<span>{{ $tick }}</span>@endforeach
        </div>
        <div class="ml-area">
            <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                @foreach ($ticks as $tick)
                    <line class="gl" x1="0" x2="100" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}"/>
                @endforeach
                <line class="ax" x1="0" x2="100" y1="100" y2="100"/>

                @foreach ($series as $line)
                    @php
                        $points = [];
                        foreach ($line['points'] as $i => $value) {
                            $points[] = $x($i).','.$y($value);
                        }
                    @endphp
                    <polyline class="ml-line" data-k="{{ $line['key'] }}"
                              points="{{ implode(' ', $points) }}"
                              vector-effect="non-scaling-stroke"/>
                @endforeach
            </svg>

            {{-- The markers sit outside the stretched SVG: a viewBox scaled to
                 the panel width would make every circle an ellipse. --}}
            @foreach ($series as $line)
                @foreach ($line['points'] as $i => $value)
                    <span class="ml-dot" data-k="{{ $line['key'] }}" data-i="{{ $i }}"
                          style="left:{{ $x($i) }}%;top:{{ $y($value) }}%"></span>
                @endforeach
            @endforeach

            {{-- One hover column per point, full height, so the target is a
                 month wide rather than a dot. --}}
            @foreach ($labels as $i => $label)
                <span class="ml-hit" data-i="{{ $i }}" tabindex="0"
                      style="left:{{ $n > 1 ? round($i / $n * 100, 3) : 0 }}%;width:{{ round(100 / $n, 3) }}%"
                      aria-label="{{ $label }}"></span>
                <span class="ml-rule" data-i="{{ $i }}" style="left:{{ $x($i) }}%"></span>
                <div class="ml-tip" data-i="{{ $i }}" role="tooltip"
                     style="left:{{ $x($i) }}%" @if ($x($i) > 65) data-flip @endif>
                    <p class="sk-tip-h">{{ $label }}</p>
                    @foreach ($series as $line)
                        <p class="sk-tip-r">
                            <span class="dn-dot" data-k="{{ $line['key'] }}"></span>
                            <span class="sk-tip-n">{{ $line['name'] }}</span>
                            <span class="sk-tip-v">{{ $line['points'][$i] }}</span>
                        </p>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <div class="ln-x ml-x">
        @foreach ($labels as $i => $label)
            <span style="left:{{ $x($i) }}%"
                  @if ($i === 0) data-first @elseif ($i === $n - 1) data-last @endif>{{ $label }}</span>
        @endforeach
    </div>

    @if ($n < 2)
        {{-- A polyline through one point draws nothing at all, so the first
             year of use rendered an empty grid. The markers are shown instead
             — the figures are still readable — and the panel says plainly that
             a trend needs a second year rather than looking broken. --}}
        <p class="dash-note ml-single">
            One year on record. This becomes a trend once there is a second one to
            compare it with; the figures are on the markers and in the list.
        </p>
    @endif

    {{-- No legend row here: the breakdown beside the chart already carries a
         dot and a name for every line, and repeating them underneath was the
         same six labels twice. Identity is still never colour alone. --}}
</div>
