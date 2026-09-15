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

    {{--
      The tile's own little chart, when the figure has one behind it.

      APPENDED, never inserted: this partial is shared with the leave
      dashboards, and putting it between the value and the subtitle would have
      reordered their cards too. Where it needs to sit beside the value -- the
      Security Dashboard -- the flex `order` in .dash-sharp moves it, which
      costs the other pages nothing because they never set these keys.

      Both are drawn from real series. A tile with nothing behind it gets no
      chart rather than a flat line, because a flat line is a claim.
    --}}
    @isset ($kpi['spark'])
        @php
            $spark = array_map('intval', $kpi['spark']);
            $peak = max(1, max($spark));
            $n = max(1, count($spark));
        @endphp
        <div class="kpi-viz" aria-hidden="true">
            <svg viewBox="0 0 {{ $n * 6 - 2 }} 24" preserveAspectRatio="none" focusable="false">
                @foreach ($spark as $i => $v)
                    @php $h = max(1.5, $v / $peak * 24); @endphp
                    <rect class="kpi-bar" x="{{ $i * 6 }}" y="{{ round(24 - $h, 2) }}"
                          width="4" height="{{ round($h, 2) }}" rx="1"/>
                @endforeach
            </svg>
        </div>
    @endisset

    @isset ($kpi['ring'])
        @php
            $parts = $kpi['ring']['parts'];
            $sum = max(1, array_sum(array_column($parts, 'value')));
            $first = $parts[0]['value'] / $sum;
            // r chosen so the circumference is a round 100, which lets the
            // dash array be read as a percentage.
            $c = 100;
            $r = $c / (2 * M_PI);
        @endphp
        <div class="kpi-viz kpi-viz-ring"
             title="{{ collect($parts)->map(fn ($p) => $p['label'].': '.$p['value'])->implode(' · ') }}">
            <svg viewBox="0 0 40 40" focusable="false" role="img"
                 aria-label="{{ collect($parts)->map(fn ($p) => $p['label'].': '.$p['value'])->implode(', ') }}">
                <circle class="kpi-ring-track" cx="20" cy="20" r="{{ round($r, 3) }}"/>
                <circle class="kpi-ring-fill" cx="20" cy="20" r="{{ round($r, 3) }}"
                        stroke-dasharray="{{ round($first * $c, 2) }} {{ $c }}"
                        transform="rotate(-90 20 20)"/>
            </svg>
        </div>
    @endisset
</div>
