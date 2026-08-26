@php
    /**
     * Vertical bars over a short, ordered run of days.
     *
     * Bars rather than a line because these are discrete daily counts: a day
     * with nothing belongs as an absent bar. A line dipping to the axis and
     * back reads as though something happened when nothing did.
     *
     * Zero-based, one hero — the tallest day, since "when did it spike" is the
     * question. Days of the week spelled Mon/Tue/Wed rather than initials:
     * M/T/W/T/F is ambiguous twice over in five letters.
     *
     * Expects: $series ['labels' => [...], 'data' => [...]]
     */
    $labels = $series['labels'];
    $data = array_map('intval', $series['data']);
    $peak = $data ? max($data) : 0;
    $heroAt = $peak > 0 ? array_search($peak, $data, true) : null;
@endphp

<div class="vb">
    @foreach ($data as $i => $value)
        <div class="vb-c" @if ($i === $heroAt) data-hero @endif>
            <span class="vb-n">{{ $value }}</span>
            <span class="vb-b" style="height:{{ $peak > 0 ? round($value / $peak * 88, 1) : 0 }}%"></span>
        </div>
    @endforeach
</div>
<div class="vb-x">
    @foreach ($labels as $label)
        <span>{{ $label }}</span>
    @endforeach
</div>
