@php
    /**
     * A smooth line over an evenly spaced run, with the period before it drawn
     * behind. Inline SVG: no canvas, no script, and it prints.
     *
     * THE AXIS IS LABELLED, down the left and along the bottom. A line with no
     * numbers on it says only "up" and "down". The reference this was drawn
     * from has no y axis at all; it is kept here because this chart counts
     * attacks, where the number is the point and not the gesture.
     *
     * The scale rounds UP to a whole step rather than stopping at the tallest
     * point, so the ticks land on round numbers and the top gridline is one of
     * them. Counts have no half, so the step is forced whole -- otherwise a
     * near-empty week labels its axis 1 · 0.5 · 0.
     *
     * The x labels sit at each point's own x, not centred in equal-width
     * slots. With twenty-eight points and four labels a slot-centred label
     * lands at (i+0.5)/n while its point is at i/(n-1) -- invisible at twelve
     * points, and off by most of a week at twenty-eight.
     *
     * MARKERS AND THE END PILL ARE HTML, not SVG. The plot is stretched with
     * preserveAspectRatio="none" so that one viewBox fits any panel width,
     * which turns an SVG circle into an ellipse. Positioned divs stay round.
     *
     * Expects:
     *   $series  ['labels' => [...], 'data' => [...], 'compare' => [...]?]
     *   $peakLabel string  what to call the high point ('peak', 'high')
     *   $tone      string  optional: 'bad' draws the line in the system's
     *                      alarm red, for a series where a rise is bad news.
     *   $endLabel  string  optional: what to call the solid series.
     *   $compareLabel string optional: what the dotted line is.
     */
    $data = array_map('intval', $series['data']);
    $labels = $series['labels'];
    $compare = array_map('intval', $series['compare'] ?? []);
    $n = max(1, count($data));
    $peak = $data ? max($data) : 0;

    // Four bands ending on a round number, so the axis reads 400/300/200/100/0
    // rather than 304/152/0. The comparison line is included in the ceiling,
    // or a bigger week behind would be drawn off the top of the plot.
    $ceiling = max($peak, $compare ? max($compare) : 0);
    $step = 1;
    foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000] as $candidate) {
        $step = $candidate;
        if ($candidate * 4 >= $ceiling) {
            break;
        }
    }
    $top = max($step, (int) (ceil($ceiling / $step) * $step));

    $ticks = [];
    for ($v = $top; $v >= 0; $v -= $step) {
        $ticks[] = $v;
    }

    $x = fn ($i) => $n > 1 ? round($i / ($n - 1) * 100, 3) : 0;
    $y = fn ($v) => round(100 - ($v / $top) * 100, 3);

    /**
     * A Catmull-Rom spline written out as cubic Béziers.
     *
     * Straight segments between daily counts read as seven separate readings
     * joined up; a curve reads as one week. The control points are the
     * standard Catmull-Rom construction -- each is a sixth of the way along
     * the vector between a point's two neighbours -- with the ends clamped to
     * themselves so the curve starts and finishes where the data does rather
     * than overshooting past it.
     */
    $curve = function (array $values) use ($x, $y, $n) {
        if (! $values) {
            return '';
        }

        $pt = [];
        foreach ($values as $i => $v) {
            $pt[] = [$x($i), $y($v)];
        }

        $d = 'M '.$pt[0][0].' '.$pt[0][1];

        for ($i = 0; $i < count($pt) - 1; $i++) {
            $p0 = $pt[max(0, $i - 1)];
            $p1 = $pt[$i];
            $p2 = $pt[$i + 1];
            $p3 = $pt[min(count($pt) - 1, $i + 2)];

            $c1x = round($p1[0] + ($p2[0] - $p0[0]) / 6, 3);
            $c1y = round($p1[1] + ($p2[1] - $p0[1]) / 6, 3);
            $c2x = round($p2[0] - ($p3[0] - $p1[0]) / 6, 3);
            $c2y = round($p2[1] - ($p3[1] - $p1[1]) / 6, 3);

            $d .= ' C '.$c1x.' '.$c1y.', '.$c2x.' '.$c2y.', '.$p2[0].' '.$p2[1];
        }

        return $d;
    };

    $path = $curve($data);
    // The same curve carried down to the baseline and closed, for the wash
    // underneath. Drawn from the path rather than a second spline, so the two
    // can never disagree about where the line is.
    $area = $path.' L 100 100 L 0 100 Z';
    $comparePath = count($compare) === $n ? $curve($compare) : '';

    $peakAt = $peak > 0 ? array_search($peak, $data, true) : null;
    // One gradient id per render, or two charts on a page share the first.
    $gid = 'lng-'.substr(md5(implode(',', $data).($tone ?? '')), 0, 8);
@endphp

<div class="ln-wrap">
    <div class="ln-y">
        @foreach ($ticks as $tick)<span>{{ $tick }}</span>@endforeach
    </div>
    <div class="ln">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" class="ln-g0"/>
                    <stop offset="1" class="ln-g1"/>
                </linearGradient>
            </defs>

            <line class="ax" x1="0" x2="100" y1="100" y2="100"/>

            <path class="ln-area {{ isset($tone) ? 'ln-area-'.$tone : '' }}"
                  d="{{ $area }}" fill="url(#{{ $gid }})"/>

            @if ($comparePath !== '')
                <path class="ln-prev" d="{{ $comparePath }}" vector-effect="non-scaling-stroke"/>
            @endif

            <path class="p1 {{ isset($tone) ? 'p1-'.$tone : '' }}"
                  d="{{ $path }}" vector-effect="non-scaling-stroke"/>
        </svg>

        {{-- Round markers, one per reading, so a week is seven measurements
             rather than a continuous quantity somebody sampled. --}}
        @foreach ($data as $i => $value)
            <span class="ln-dot {{ isset($tone) ? 'ln-dot-'.$tone : '' }}"
                  style="left:{{ $x($i) }}%;top:{{ $y($value) }}%"></span>
        @endforeach

        @if ($peakAt !== null)
            {{-- Above the point normally; below it when the peak sits on the
                 top gridline, where "above" is outside the card and would land
                 the label on the panel title. Nudged in at either end, where a
                 centred label hangs off the edge of the plot. --}}
            @php
                $peakY = $y($peak);
                $below = $peakY < 20;
                $edge = $peakAt === 0 ? ' data-first' : ($peakAt === $n - 1 ? ' data-last' : '');
            @endphp
            <div class="ln-peak" style="left:{{ $x($peakAt) }}%;top:{{ $below ? $peakY + 7 : $peakY - 17 }}%"
                 @isset($tone) data-tone="{{ $tone }}" @endisset {!! $edge !!}>
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

@if ($comparePath !== '' && isset($compareLabel))
    {{-- Both series named in a legend, not in pills on the plot.

         The reference labels its two lines with pills at the right-hand end,
         which works there because its two series finish far apart. Ours
         finish wherever the week did: on the first render they overlapped
         into an unreadable blob, and the longer of the two labels ran off the
         edge of the card besides. A legend cannot collide with anything and
         cannot be clipped. --}}
    <p class="ln-legend">
        <span><i class="ln-legend-now {{ isset($tone) ? 'ln-legend-'.$tone : '' }}"></i>{{ $endLabel ?? 'This period' }}</span>
        <span><i class="ln-legend-prev"></i>{{ $compareLabel }}</span>
    </p>
@endif
