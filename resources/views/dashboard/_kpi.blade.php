@php
    /**
     * One counter: a label, an icon, the number, and one line saying what the
     * number sits next to.
     *
     * The icon and the number share a colour, so the pair reads as one thing
     * rather than two ornaments, and the colour is assigned by what the number
     * IS — there are only four roles:
     *
     *   info — a plain count, no judgement attached
     *   good — healthy, or done
     *   warn — needs attention
     *   bad  — a problem
     *
     * Which is why red means the same thing on the security screen as it does
     * on HR's, and why the system's violet does duty as "just a number":
     * keeping red, amber and green for states that actually have one.
     *
     * Icons are stroked, never filled. At this size a filled glyph becomes a
     * solid blob that pulls harder than the number beside it, which is
     * backwards — the number is the data, the icon is the label.
     *
     * Expects: $kpi ['label','value','sub','icon','tone','lead'?,'delta'?]
     * `lead` is the one figure inside the subtitle worth lifting out. It is a
     * separate field rather than markup inside `sub`, so nothing on this card
     * is ever rendered unescaped.
     *
     * `delta` is the same number last period: ['value','dir','of']. A count on
     * its own says where things stand and not whether that is normal — twelve
     * filed this month is a different fact after eight than after twenty.
     *
     * The arrow says which way, the colour says whether that is good, and they
     * are NOT the same question: more applications waiting is a rise and a
     * problem, more employees is a rise and neither. So `dir` carries the
     * direction and the tone is named separately in `delta['tone']`, rather
     * than green being assumed to mean "up".
     */
@endphp

<div class="kpi" data-tone="{{ $kpi['tone'] ?? 'info' }}">
    <div class="kpi-top">
        <p class="kpi-lb">{{ $kpi['label'] }}</p>
        @include('dashboard._icon', ['name' => $kpi['icon'] ?? 'file'])
    </div>
    <p class="kpi-v">{{ $kpi['value'] }}</p>
    <p class="kpi-s">
        @if (! empty($kpi['lead']))<b>{{ $kpi['lead'] }}</b> @endif{{ $kpi['sub'] ?? '' }}
    </p>
    @isset ($kpi['delta'])
        <p class="kpi-d" data-dir="{{ $kpi['delta']['dir'] }}"
           data-tone="{{ $kpi['delta']['tone'] ?? 'flat' }}">
            <span class="kpi-arrow" aria-hidden="true">{!! match ($kpi['delta']['dir']) {
                'up' => '&#8599;', 'down' => '&#8600;', default => '&#8594;',
            } !!}</span>
            <b>{{ $kpi['delta']['value'] }}</b>
            <span>{{ $kpi['delta']['of'] }}</span>
        </p>
    @endisset
</div>
