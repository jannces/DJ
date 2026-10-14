@php
    /**
     * Three parts of one whole, as a single bar.
     *
     * A bar rather than a pie. Both answer "what proportion ended up approved",
     * but a bar survives a fourth status arriving without turning into a colour
     * wheel, and a reader compares lengths more accurately than wedge angles.
     *
     * These are status colours, not categorical slots: approved is green,
     * rejected is red, waiting is amber, because that is what those words mean
     * everywhere else in the system. The obvious dark trio puts green and red
     * 4.6 ΔE apart under a deuteranopia simulation, which is indistinguishable;
     * the tokens in the stylesheet clear 8 ΔE on every pair in both themes.
     *
     * Expects: $parts array of ['key' => 'approved'|'rejected'|'pending',
     *                           'label' => string, 'value' => int]
     */
    $total = array_sum(array_column($parts, 'value'));
@endphp

@if ($total > 0)
    <div class="sb">
        @foreach ($parts as $part)
            @continue($part['value'] === 0)
            <i class="sb-{{ $part['key'] }}" style="width:{{ round($part['value'] / $total * 100, 1) }}%"></i>
        @endforeach
    </div>
@else
    <div class="sb sb-empty"><i></i></div>
@endif

<div class="sb-key">
    @foreach ($parts as $part)
        <span>
            <i class="dot-s sb-{{ $part['key'] }}"></i>{{ $part['label'] }}
            <b>{{ $part['value'] }}</b>
        </span>
    @endforeach
</div>
