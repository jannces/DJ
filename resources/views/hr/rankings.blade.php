@extends('layouts.app')
@section('title', 'Employee Leave Rankings')
@section('content')

{{--
  Who has used the most of each leave type this year.

  One table per type, switched by the row of tabs — radio inputs revealed with
  :has(), the same mechanism as the dashboard's period switches, so the page
  carries no script and prints whole.

  The search is a plain form. A live filter would have to re-fetch and swap
  every one of these tables, and the answer to "where is Reyes" is a name typed
  once, not a list narrowing as you go.

  Nothing here is coloured as a fault. Vacation days are earned and spending
  them is what they are for; what IS coloured is the credit left, because
  running out is a fact the employee needs before they file again.
--}}

<div class="list-head">
    <h1 class="h4 mb-0">Employee Leave Rankings</h1>
    <p class="text-muted small mb-0">Days used per leave type, {{ $year }}{{ $scope !== null ? ' · your office' : '' }}</p>
</div>

<div class="card">
    <x-list-toolbar search placeholder="Search employee" :action="route('rankings.index')" />

    @php $withRows = collect($rankings)->filter(fn ($r) => $r['rows'] !== [])->values(); @endphp

    @if ($withRows->isEmpty())
        <div class="card-body">
            <p class="dash-empty mb-0">
                No approved leave on record for {{ $year }}{{ request('q') ? ' matching that name' : '' }}.
            </p>
        </div>
    @else
        <div class="rk" id="rk">
            <div class="rk-tabs">
                @foreach ($withRows as $ranking)
                    <label>
                        <input type="radio" name="rk-type" id="rk-{{ $ranking['type']->code }}"
                               @checked($loop->first)>
                        {{ $ranking['type']->name }}
                        <span class="rk-count">{{ count($ranking['rows']) }}</span>
                    </label>
                @endforeach
            </div>

            @foreach ($withRows as $ranking)
                <div class="rk-pane" data-code="{{ $ranking['type']->code }}">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 rk-table">
                            <thead>
                                <tr>
                                    <th class="rk-th-rank">Rank</th>
                                    <th>Employee</th>
                                    <th>Office</th>
                                    <th>Position</th>
                                    <th class="rk-th-days">Days used</th>
                                    <th class="num">Earned</th>
                                    <th class="num">Remaining</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($ranking['rows'] as $row)
                                <tr>
                                    <td>
                                        {{-- Only the first three are marked. A
                                             medal on every row is a decoration;
                                             on three it is a ranking. --}}
                                        <span class="rk-rank" @if ($row['rank'] <= 3) data-top="{{ $row['rank'] }}" @endif>
                                            {{ $row['rank'] }}
                                        </span>
                                    </td>
                                    <td>
                                        {{-- A link only for a reader who can open the
                                             other end. This page is also given to a
                                             department head, who does not hold
                                             employees.view — a blue name would send
                                             them to a 403, so they get the same row
                                             without one. --}}
                                        <x-person :name="$row['name']"
                                            :url="auth()->user()->hasPermission('employees.view')
                                                ? route('employees.show', $row['user_id']) : null" />
                                    </td>
                                    <td class="small text-muted">{{ $row['office'] }}</td>
                                    <td class="small text-muted">{{ $row['position'] }}</td>
                                    <td>
                                        <span class="rk-days">
                                            <b>{{ $row['days'] }}</b>
                                            <span class="rk-t"><span style="width:{{ $row['pct'] }}%"></span></span>
                                        </span>
                                    </td>
                                    <td class="num small text-muted">{{ $row['earned'] ?? '—' }}</td>
                                    <td class="num">
                                        <span class="rk-left" data-state="{{ $row['state'] }}">
                                            {{ $row['left'] === null ? 'no credit' : $row['left'].' days' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="card-body">
            <p class="dash-note mb-0">
                Approved leave only, counted in the year it was taken. A pending application is a
                request, not leave used. Top 20 per type.
                @can('employees.view') Click a name to open that employee&rsquo;s record. @endcan
            </p>
        </div>
    @endif
</div>
@endsection
