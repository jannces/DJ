@php
    /**
     * The twelve counter icons, drawn here rather than pulled from Bootstrap
     * Icons.
     *
     * Bootstrap Icons are filled glyphs. At the size a KPI card wants one, a
     * filled shape becomes a solid block of colour that pulls the eye harder
     * than the number beside it — backwards, since the number is the data and
     * the icon is only its label. These are stroked, one weight, all on the
     * same 24-unit grid, so they sit as a set and recede behind the figure.
     *
     * The rest of the system keeps its Bootstrap Icons; this is the one place
     * where weight matters more than convenience.
     *
     * Expects: $name — a key below. An unknown name draws nothing rather than
     * a broken glyph.
     */
    $paths = [
        // employee
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>',
        'pulse' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'hour' => '<path d="M7 3h10M7 21h10M7 3c0 4 5 5 5 9s-5 5-5 9M17 3c0 4-5 5-5 9s5 5 5 9"/>',
        'calchk' => '<rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4M9 15l2 2 4-4"/>',
        // leave management
        'inbox' => '<path d="M3.5 13h5l1.5 3h4l1.5-3h5"/><path d="M5.5 4h13l2 9v5a2 2 0 01-2 2h-13a2 2 0 01-2-2v-5z"/>',
        'walk' => '<circle cx="13" cy="4" r="1.8"/><path d="M11 21l1.5-6-3-2.5 1-4.5 3 3 3 1M10 14l-2 7"/>',
        'file' => '<path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'gavel' => '<circle cx="12" cy="12" r="9"/><path d="M8.4 12.2l2.4 2.4 4.8-4.8"/>',
        // security
        'people' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c.9-3.4 3.2-5 6-5s5.1 1.6 6 5"/><path d="M16 5.4a3.2 3.2 0 010 5.2M17.5 20c-.3-1.6-.9-2.9-1.8-3.9"/>',
        'keyx' => '<circle cx="8" cy="12" r="3.4"/><path d="M11.4 12H17l1.6 1.8"/><path d="M15.6 18.4l4.4-4.4M20 18.4l-4.4-4.4"/>',
        'bug' => '<rect x="8" y="7.5" width="8" height="11" rx="4"/><path d="M8 11H4M20 11h-4M8 15.5H4.5M19.5 15.5H16M9.5 7l-1.3-2M14.5 7l1.3-2"/>',
        'slash' => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
    ];
@endphp

@if (isset($paths[$name]))
    <svg class="kpi-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false">{!! $paths[$name] !!}</svg>
@endif
