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
    // SVG's: 0 is right, 90 is down. Sweeping 150 -> 270 -> 30 goes clockwise
    // over the top, so both arc flags are 1.
    $r = 76;
    $point = function (float $deg) use ($r) {
        $rad = deg2rad($deg);

        return round(100 + $r * cos($rad), 2).' '.round(100 + $r * sin($rad), 2);
    };
    $path = 'M '.$point(150).' A '.$r.' '.$r.' 0 1 1 '.$point(30);
    $arc = 2 * M_PI * $r * (240 / 360);
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
             aria-label="{{ round($share * 100) }}% of this week's attempts were refused before reaching the application">
            <path class="ig-track" d="{{ $path }}"/>
            <path class="ig-fill" d="{{ $path }}"
                  stroke-dasharray="{{ round($arc * $share, 2) }} {{ round($arc, 2) }}"/>
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

    <p class="dash-note">
        {{-- Computed from the rules, not asserted: an attempt counts as
             prevented when its category is one the detector refuses. --}}
        @if ($reached === 0)
            All {{ $total === 1 ? 'of it was' : 'were' }} refused before reaching the application.
        @else
            <b>{{ $reached }}</b> reached the application. That is the one thing on
            this page worth acting on today.
        @endif
        @if ($severity['previous'] > 0 || $total > 0)
            {{ $severity['previous'] }} last week{!! $change > 0
                ? ' <span class="sv-up">&#9650;</span>'
                : ($change < 0 ? ' <span class="sv-down">&#9660;</span>' : '') !!}
        @endif
    </p>
</div>
