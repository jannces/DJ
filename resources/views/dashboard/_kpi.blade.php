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
     * Expects: $kpi ['label','value','sub','icon','tone','lead'?]
     * `lead` is the one figure inside the subtitle worth lifting out. It is a
     * separate field rather than markup inside `sub`, so nothing on this card
     * is ever rendered unescaped.
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
</div>
