@php
    /**
     * One bar per office, divided by leave type.
     *
     * The ranked bar this replaces said which office filed the most, which is
     * mostly a fact about headcount. The composition is the part that can be
     * acted on: an office three-quarters Sick Leave and one three-quarters
     * Vacation drew the same bar, and they are not the same situation.
     *
     * Every bar starts at zero and is drawn against the same maximum, so a bar
     * twice as long is twice as many. The segments inside are shares of that
     * office's own total.
     *
     * Hovering a row names every segment with its count, because a segment
     * three pixels wide cannot be read off the bar. The panel follows the row
     * rather than the pointer -- it is anchored, so it cannot end up half
     * outside the card, and it never covers the row it describes.
     *
     * Expects:
     *   $stack ['max' => int, 'rows' => [['name','total','segments' => [...]]]]
     *   $empty string
     */
    $rows = $stack['rows'] ?? [];
    $max = max(1, $stack['max'] ?? 1);
    // Four gridlines on a round number, as the line chart does.
    $step = 1;
    foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000] as $candidate) {
        $step = $candidate;
        if ($candidate * 4 >= $max) {
            break;
        }
    }
    $top = max($step, (int) (ceil($max / $step) * $step));
@endphp

@if ($rows)
    <div class="sk">
        @foreach ($rows as $row)
            <div class="sk-r" tabindex="0">
                <span class="sk-l" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                <span class="sk-t">
                    <span class="sk-fill" style="width:{{ round($row['total'] / $top * 100, 2) }}%">
                        @foreach ($row['segments'] as $segment)
                            <span class="sk-s" data-k="{{ $segment['key'] }}"
                                  style="width:{{ $segment['pct'] }}%"></span>
                        @endforeach
                    </span>
                </span>
                <span class="sk-v">{{ $row['total'] }}</span>

                @if ($row['segments'])
                    <div class="sk-tip" role="tooltip">
                        <p class="sk-tip-h">{{ $row['name'] }}</p>
                        @foreach ($row['segments'] as $segment)
                            <p class="sk-tip-r">
                                <span class="dn-dot" data-k="{{ $segment['key'] }}"></span>
                                <span class="sk-tip-n">{{ $segment['name'] }}</span>
                                <span class="sk-tip-v">{{ $segment['value'] }}</span>
                            </p>
                        @endforeach
                        <p class="sk-tip-f">{{ $row['total'] }} filed in total</p>
                    </div>
                @endif
            </div>
        @endforeach

        <div class="sk-axis">
            @for ($v = 0; $v <= $top; $v += $step)
                <span style="left:{{ round($v / $top * 100, 2) }}%"
                      @if ($v === 0) data-first @elseif ($v === $top) data-last @endif>{{ $v }}</span>
            @endfor
        </div>
    </div>

    @if (! empty($stack['silent']))
        {{-- Named rather than dropped: a missing office would read as "no
             data". Named in one line rather than twelve empty tracks, which
             is the same statement taking up the whole panel. --}}
        <p class="sk-silent">
            <b>{{ count($stack['silent']) }} {{ Str::plural('office', count($stack['silent'])) }}</b>
            filed nothing: {{ implode(', ', $stack['silent']) }}.
        </p>
    @endif
@else
    <p class="dash-empty">{{ $empty ?? 'No offices on record.' }}</p>
@endif
