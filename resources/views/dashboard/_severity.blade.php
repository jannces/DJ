@php
    /**
     * Attack severity as a dial: one figure, and the three grades that make it
     * up.
     *
     * The arc is NOT decoration and it is not the total. A dial needs a
     * ceiling, and "attempts this week" has none -- there is no number of
     * attacks that counts as full. What does have a ceiling is the share the
     * system refused before it reached the application: that runs 0-100%, it
     * is the single fact on this page worth knowing first, and it is what the
     * arc is filled to. The number inside the ring is the week's total, which
     * is the sum of the three grades beneath it.
     *
     * Grades are not sorted by magnitude, unlike every other chart here. Those
     * answer "which is the most", so ranking is the point. This one answers
     * "how bad", and a scale that reorders itself is not a scale -- Critical
     * stays first on a quiet week too.
     *
     * The three colours are the ones the system already uses for exactly this:
     * --k-bad-f, --k-warn-f, and a neutral rather than a green. A green flag on
     * an attempt that was refused reads as "this is fine" when it means "this
     * one was isolated" -- and red against green is the pair a colour-blind
     * reader cannot separate, which on a panel where colour carries the meaning
     * is not a small thing.
     *
     * Expects: $severity — the array from SecurityDashboardService.
     */
    $rows = $severity['rows'];
    $total = (int) $severity['total'];
    $reached = (int) $severity['reached'];
    $change = $total - $severity['previous'];

    $refused = max(0, $total - $reached);
    $share = $total > 0 ? $refused / $total : 1.0;

    // A 240-degree arc centred at (100,100), opening at the bottom. Angles are
    // SVG's: 0 is right, 90 is down; the sweep runs 150 -> 270 -> 30, clockwise
    // over the top.
    $r = 76;
    $span = 240;
    $start = 150;

    $point = function (float $deg) use ($r) {
        $rad = deg2rad($deg);

        return round(100 + $r * cos($rad), 2).' '.round(100 + $r * sin($rad), 2);
    };
    $arcPath = function (float $from, float $to) use ($point) {
        $large = ($to - $from) > 180 ? 1 : 0;

        return 'M '.$point($from).' A 76 76 0 '.$large.' 1 '.$point($to);
    };

    // The track, and then one segment per grade.
    //
    // The arc used to be filled to the share refused -- a single sweep with a
    // real ceiling. It is divided now, because the three grades already sum to
    // the total in the middle, so the ring can show WHAT the total is made of
    // rather than repeating a figure stated two lines above it. The gap
    // between segments is what makes them three readings instead of a
    // gradient.
    $gap = 3.0;
    $segments = [];
    $at = $start;
    foreach ($rows as $row) {
        $slice = $total > 0 ? ($row['value'] / $total) * $span : 0.0;

        // A grade with nothing in it draws nothing; a hairline stub would read
        // as "a little of this" when the answer is none.
        if ($slice > 0.01) {
            $end = $at + max(0.5, $slice - $gap);
            $segments[] = ['key' => $row['key'], 'd' => $arcPath($at, $end)];
        }

        $at += $slice;
    }

    $track = $arcPath($start, $start + $span);
@endphp

<div class="ig">
    <p class="ig-sub">
        {{ $total === 0
            ? 'Nothing detected in the last '.$severity['days'].' days.'
            : $refused.' of '.$total.' refused before reaching the application · '
                .$severity['sources'].($severity['sources'] === 1 ? ' source' : ' sources')
                .' · this week' }}
    </p>

    <div class="ig-dial">
        <svg viewBox="0 0 200 150" role="img"
             aria-label="{{ $total }} attempts this week: {{ collect($rows)->map(fn ($r) => $r['value'].' '.$r['label'])->implode(', ') }}">
            <path class="ig-track" d="{{ $track }}"/>
            @foreach ($segments as $segment)
                <path class="ig-seg ig-{{ $segment['key'] }}" d="{{ $segment['d'] }}"/>
            @endforeach
        </svg>
        <div class="ig-centre">
            <span class="ig-cap">Attempts this week</span>
            <span class="ig-n">{{ $total }}</span>
            <span class="ig-pct">{{ round($share * 100) }}% refused</span>
        </div>
    </div>

    <div class="ig-grades">
        @foreach ($rows as $row)
            <div class="ig-grade">
                <span class="ig-key"><i class="sv-dot sv-{{ $row['key'] }}"></i>{{ $row['label'] }}</span>
                <b>{{ $row['value'] }}</b>
                <small>{{ $row['source'] }}</small>
            </div>
        @endforeach
    </div>

    {{-- The prose footnote is gone at the LGU's request. What it said that
         nothing else did -- that something got through -- is kept, because a
         card reporting attacks must not go quiet on the one fact worth acting
         on. It shows only when there IS something, rather than restating "all
         were refused" on every quiet week. --}}
    @if ($reached > 0)
        <p class="ig-alarm"><b>{{ $reached }}</b> reached the application.</p>
    @endif
</div>
