@extends('layouts.app')
@section('title', 'Audit Logs')
@section('content')
<h1 class="h4 mb-3">Audit Logs</h1>
<div class="card">
    <x-list-toolbar search placeholder="Search by user"
        :action="route('audit.index')">
        <x-list-filter name="action" label="Action" :options="$actions" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Target</th><th>IP</th><th>Changes</th></tr></thead>
    <tbody>
    @forelse ($logs as $l)
        <tr>
            <td class="small">{{ $l->created_at->format('M d, H:i:s') }}</td>
            <td class="small">{{ $l->user?->name ?? 'system' }}</td>
            <td class="small">{{ $l->role_label ?? '—' }}</td>
            <td><span class="badge bg-light text-dark">{{ $l->action_label }}</span></td>
            <td class="small">{{ $l->target_label ?? '—' }}</td>
            <td class="small">{{ $l->ip }}</td>
            {{-- What changed, in words. The trail still stores the row as it
                 was and the row as it is; this column shows the difference
                 between them, which is the part anyone actually reads. --}}
            <td class="small audit-changes">
                @php $changes = $l->change_list; @endphp
                @forelse ($changes as $i => $c)
                    @if ($i === 3 && count($changes) > 4)
                        <details class="audit-more">
                            <summary>{{ count($changes) - 3 }} more</summary>
                    @endif
                    <div class="audit-change">
                        <span class="audit-field">{{ $c['label'] }}</span>
                        @if ($c['from'] !== null)
                            <span class="audit-was">{{ $c['from'] }}</span>
                            <i class="bi bi-arrow-right audit-arrow" aria-hidden="true"></i>
                            <span class="visually-hidden">changed to</span>
                        @endif
                        <span class="audit-now">{{ $c['to'] }}</span>
                    </div>
                    @if ($loop->last && $i >= 3 && count($changes) > 4)
                        </details>
                    @endif
                @empty
                    <span class="text-muted">—</span>
                @endforelse
            </td>
        </tr>
    @empty <tr><td colspan="7" class="text-center text-muted py-4">No audit entries.</td></tr> @endforelse
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div></div>
@endsection
