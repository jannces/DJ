@php
    /**
     * Attack severity: three grades, worst first, with the week's shape above.
     *
     * Not sorted by magnitude, unlike every other chart on this page. Those
     * answer "which is the most", so the ranking is the point and sort order
     * carries it. This one answers "how bad", and a scale that reorders itself
     * is not a scale — Critical sits at the top on a quiet week too.
     *
     * The colours are the three the system already uses for exactly this:
     * --k-bad-f, --k-warn-f and the neutral. Red, amber, then a grey rather
     * than a green, because a green flag on an attempt that was refused reads
     * as "this is fine" when it means "this one was isolated" — and red
     * against green is the pair a colour-blind reader cannot separate, which
     * on a panel where colour carries the meaning is not a small thing.
     *
     * Expects: $severity — the array from SecurityDashboardService.
     */
    $rows = $severity['rows'];
    $peak = max(1, max(array_column($rows, 'value')));
    $change = $severity['total'] - $severity['previous'];
@endphp

<div class="sv">
    <div class="sv-heads">
        <div class="sv-fig">
            <span class="sv-n">{{ $severity['total'] }}</span>
            <span class="sv-l">Attempts</span>
        </div>
        <div class="sv-fig">
            <span class="sv-n {{ $severity['reached'] ? 'is-bad' : '' }}">{{ $severity['reached'] }}</span>
            <span class="sv-l">Reached the app</span>
        </div>
        <div class="sv-fig">
            <span class="sv-n">{{ $severity['sources'] }}</span>
            <span class="sv-l">{{ $severity['sources'] === 1 ? 'Source' : 'Sources' }}</span>
        </div>
    </div>

    @if ($severity['total'] === 0)
        <p class="dash-empty">No attacks detected in the last {{ $severity['days'] }} days.</p>
    @else
        <ul class="sv-rows">
            @foreach ($rows as $row)
                <li class="sv-row">
                    <div class="sv-key">
                        <i class="sv-dot sv-{{ $row['key'] }}"></i>
                        <span class="sv-name">{{ $row['label'] }}</span>
                        <span class="sv-v">{{ $row['value'] }}</span>
                    </div>
                    <div class="sv-t">
                        <span class="sv-f sv-{{ $row['key'] }}"
                              style="width:{{ round($row['value'] / $peak * 100, 1) }}%"></span>
                    </div>
                    <p class="sv-note">{{ $row['source'] }}</p>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="dash-note">
        {{-- Computed from the rules, not asserted: an attempt counts as
             prevented when its category is one the detector refuses. --}}
        @if ($severity['reached'] === 0)
            All {{ $severity['total'] === 1 ? 'of it was' : 'were' }} refused before reaching
            the application.
        @else
            <b>{{ $severity['reached'] }}</b> reached the application. That is the one thing on
            this page worth acting on today.
        @endif
        @if ($severity['previous'] > 0 || $severity['total'] > 0)
            {{ $severity['previous'] }} last week{!! $change > 0
                ? ' <span class="sv-up">&#9650;</span>'
                : ($change < 0 ? ' <span class="sv-down">&#9660;</span>' : '') !!}
        @endif
    </p>
</div>
