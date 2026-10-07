@php
    /**
     * Addresses seen attacking that nothing is keeping out yet.
     *
     * A count on its own is what the broken rate counter punished us with —
     * five events looked damning and were all the notification bell polling
     * itself. So every row carries what the events were, when the last one
     * landed, and a link to read them. Deciding is meant to be possible here;
     * guessing is not.
     *
     * Expects: $intruders, $days.
     */
@endphp

<div class="card">
    <div class="card-header">
        <span>Seen attacking, not blocked</span>
        <span class="text-muted fw-normal">last {{ $days }} days</span>
    </div>

    @if (empty($intruders))
        <div class="card-body text-muted text-center py-4">
            Nothing is waiting on a decision. Every address that has attacked in
            the last {{ $days }} days is already blocked, or none has.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>IP</th><th class="num">Events</th><th>What</th>
                        <th>Last seen</th><th>Severity</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($intruders as $row)
                    <tr>
                        <td>
                            <code>{{ $row['ip'] }}</code>
                            @if ($row['on_lan'])
                                {{-- The address is inside the building. Blocking
                                     it locks a colleague out of the leave
                                     system, which is what the broken rate
                                     counter did to 192.168.1.7. --}}
                                <div class="text-warning small">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    On your LAN — probably an office computer
                                </div>
                            @endif
                        </td>
                        <td class="num">{{ $row['events'] }}</td>
                        <td class="small">{{ implode(', ', $row['kinds']) }}</td>
                        <td class="small">{{ $row['last_seen']->diffForHumans() }}</td>
                        <td>
                            <span class="sv-tag">
                                <i class="sv-dot sv-{{ $row['grade'] }}"></i>{{ $row['grade_label'] }}
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            {{-- Look before you block. --}}
                            <a href="{{ route('security.intrusions', ['q' => $row['ip']]) }}"
                               class="btn btn-sm btn-outline-secondary">
                                View {{ $row['events'] }} {{ Str::plural('event', $row['events']) }}
                            </a>
                            <form method="POST" action="{{ route('security.block-intruder') }}"
                                  class="d-inline"
                                  data-confirm="Block {{ $row['ip'] }}?{{ $row['on_lan']
                                      ? ' This address is on your own network, so this is most likely an office computer — whoever uses it will be shut out of the system.'
                                      : ' It will be shut out of the system.' }}"
                                  data-confirm-tone="danger">
                                @csrf
                                <input type="hidden" name="ip" value="{{ $row['ip'] }}">
                                <button class="btn btn-sm btn-danger">
                                    <i class="bi bi-slash-circle"></i>Block
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-body">
            <p class="dash-note mb-0">
                Blocks last {{ \App\Models\SystemSetting::get('security.ip_block_hours', 24) }} hours and
                the reason is written from the events above, not typed. An address is a weak
                identity on a municipal LAN — DHCP reuses them and a whole office can sit
                behind one — so read the events before shutting one out.
            </p>
        </div>
    @endif
</div>
