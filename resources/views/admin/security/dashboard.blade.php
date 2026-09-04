@extends('layouts.app')
@section('title', 'Security Dashboard')
@section('content')

{{--
  The System Administrator's only dashboard.

  Both sidebar entries — `Dashboard` and `Security Dashboard` — arrive here, and
  neither was moved, renamed or repointed: /dashboard redirects for this role in
  DashboardController. config/menu.php is untouched.

  No leave figures at all. The administrator holds no leave permission and never
  did; the plain Dashboard was showing them anyway because the analytics hung
  off `users.manage`.

  Every chart is HTML, CSS and inline SVG. The two canvases that used to be here
  are gone, which also retires the runaway-growth bug they had: a responsive
  canvas in a container sized by its own contents grew on every resize tick,
  forever.
--}}

{{-- `dash-plain` opts this page out of the filled KPI tiles and the panel
     lift that the leave dashboards use. Colour on this screen has to be free
     to mean something -- red is an intrusion, amber a failed sign-in, green
     quiet -- and a tile that is already solid green cannot then turn green to
     say so. See "The security dashboard opts out" in app.css. --}}
<div class="dash dash-plain">

<div class="kpi-grid">
    @foreach ($kpis as $kpi)
        @include('dashboard._kpi', ['kpi' => $kpi])
    @endforeach
</div>

<div class="dash-split">
    {{-- ---------- Attempts per day ---------- --}}
    {{-- Seven days, not four weeks: attacks have no weekly rhythm to read a
         week against, and a spike matters on the day it happens. --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title">Intrusion attempts per day</p>
            <span class="dash-link">last 7 days</span>
        </div>
        <div class="dash-body">
            @include('dashboard._vbars', ['series' => $trend])
        </div>
    </div>

    {{-- ---------- Where it comes from ---------- --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title">Busiest source addresses</p>
            <a href="{{ route('security.blocked-ips') }}" class="dash-link">Blocked IPs &rarr;</a>
        </div>
        <div class="dash-body">
            @include('dashboard._hbars', [
                'rows' => $attackers, 'mono' => true,
                'empty' => 'No intrusion events in the last 30 days.',
            ])
        </div>
    </div>

</div>

{{-- Two panels stacked against one tall one. "Attempts by type" and "Most
     targeted pages" both answer a question about the attacks -- what kind, and
     at what -- so severity runs down the side of both rather than sitting under
     one of them: how bad is the third question about the same set. --}}
<div class="dash-split">
    <div class="dash-col">
        {{-- ---------- The three attacks ---------- --}}
        {{-- The only chart on the system with a categorical colour scale,
             because here the type IS the subject rather than a ranking. Three
             hues, validated in both themes: worst adjacent colour-blind
             separation 9.2 dE light and 9.4 dark against a target of 8.

             The stored category sits under each label so the mapping is visible
             rather than assumed. `xss` and `traversal` are grouped as input
             manipulation HERE and nowhere else -- the detector keeps recording
             them separately and Intrusion Logs keeps showing which. --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Attempts by type</p>
                <span class="dash-link">last 30 days</span>
            </div>
            <div class="dash-body">
                @include('dashboard._hbars', ['rows' => $attacks, 'series' => true])
            </div>
        </div>

        {{-- ---------- What it aims at ---------- --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Most targeted pages</p>
                <span class="dash-link">last 30 days</span>
            </div>
            <div class="dash-body">
                @include('dashboard._hbars', [
                    'rows' => $routes, 'mono' => true,
                    'empty' => 'No intrusion events in the last 30 days.',
                ])
            </div>
        </div>
    </div>

    {{-- ---------- How bad ---------- --}}
    {{-- The stored severity column is not a scale: the detector records all
         three attack types at high and nothing at low or critical, so charting
         it would draw one bar and two empty ones. What differs between two
         injection attempts is the pattern behind them, so that is what is
         graded -- here, in the analytics, leaving the log a record of what was
         seen rather than of what was later concluded. --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title">Attack severity</p>
            <span class="dash-link">this week</span>
        </div>
        <div class="dash-body">
            @include('dashboard._severity', ['severity' => $severity])
        </div>
    </div>
</div>

{{-- ---------- Sign-ins ---------- --}}
{{-- Four weeks, unlike the seven-day chart above, because sign-ins DO have a
     weekly rhythm. One week shows the shape but cannot say whether this week is
     unusual; four lets this Monday be read against the last three. --}}
<div class="dash-frame">
    <div class="dash-head">
        <p class="dash-title">Successful sign-ins per day</p>
        <span class="dash-link">last 4 weeks</span>
    </div>
    <div class="dash-body">
        @include('dashboard._line', ['series' => $signins, 'peakLabel' => 'high'])
    </div>
</div>

{{-- ==================================================================== --}}
{{-- The three additions                                                  --}}
{{-- ==================================================================== --}}

<div class="dash-split">
    {{-- ---------- Unreviewed events ---------- --}}
    {{-- `intrusion_logs.handled` existed and was cleared for every row the
         moment this page rendered, so it recorded a page view rather than a
         decision. This is that column doing the job its name claims, and
         reviewing is now an action.

         payload_excerpt is deliberately absent. It is the most interesting
         field in that table and it is attacker-controlled text; it belongs on
         the detail page, escaped — not on a screen somebody glances at forty
         times a day. --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title">
                Unreviewed events
                @if ($queue['total'] > 0)<span class="dash-count">{{ $queue['total'] }}</span>@endif
            </p>
            @if ($queue['total'] > 0)
                <form method="POST" action="{{ route('security.intrusions.review') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="dash-link">Mark all reviewed</button>
                </form>
            @endif
        </div>
        <div class="dash-body">
            @forelse ($queue['rows'] as $row)
                <div class="wl-r">
                    <span class="wl-ref">{{ $row['when'] }}</span>
                    <span class="wl-m">
                        <b><i class="sev sev-{{ $row['severity'] }}"></i>{{ $row['label'] }}</b>
                        <small>{{ $row['detail'] }}</small>
                    </span>
                    <span class="wl-age wl-mono">{{ $row['ip'] }}</span>
                    <form method="POST" action="{{ route('security.intrusions.review') }}" class="wl-act">
                        @csrf
                        <input type="hidden" name="id" value="{{ $row['id'] }}">
                        <button type="submit" title="Mark reviewed">&checkmark;</button>
                    </form>
                </div>
            @empty
                <p class="dash-empty">Everything has been reviewed.</p>
            @endforelse
            @if ($queue['total'] > count($queue['rows']))
                <p class="dash-note">
                    {{ $queue['total'] - count($queue['rows']) }} more outstanding &mdash;
                    <a href="{{ route('security.intrusions') }}">open Intrusion Logs</a>.
                </p>
            @endif
        </div>
    </div>

    {{-- ---------- Failures by reason ---------- --}}
    {{-- Thirty-two failures is one number. Twenty-three of them against
         usernames that do not exist is a diagnosis: somebody is guessing
         accounts, not passwords, and that is a different attack. --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title">Failed sign-ins by reason</p>
            <span class="dash-link">last 7 days</span>
        </div>
        <div class="dash-body">
            @include('dashboard._hbars', [
                'rows' => $failures,
                'empty' => 'No failed sign-ins in the last 7 days.',
            ])
        </div>
    </div>
</div>

{{-- ---------- Privilege changes ---------- --}}
{{-- A system whose case rests on auditability should show its own role and
     permission edits to the person making them. --}}
<div class="dash-frame">
    <div class="dash-head">
        <p class="dash-title">Privilege changes</p>
        <a href="{{ route('audit.index') }}" class="dash-link">Audit log &rarr;</a>
    </div>
    <div class="dash-body">
        @forelse ($privileges as $row)
            <div class="wl-r">
                <span class="wl-ref">{{ $row['when'] }}</span>
                <span class="wl-m">{{ $row['what'] }} <b>{{ $row['target'] }}</b></span>
                <span class="wl-age">{{ $row['who'] }}</span>
            </div>
        @empty
            <p class="dash-empty">No role, permission or account changes in the last 7 days.</p>
        @endforelse
    </div>
</div>

</div>{{-- /.dash --}}
@endsection
