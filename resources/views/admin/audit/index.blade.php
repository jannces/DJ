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
            {{-- What changed, in words, and what it did.

                 The trail stores the row as it was and the row as it is; the
                 difference between them is the part anyone reads. But a field
                 and its new value only say what was written — "Status: active
                 → blocked" is a fact about a column. Why somebody opened this
                 page is the sentence underneath it: they cannot sign in until
                 an administrator lifts it. --}}
            <td class="small audit-changes">
                @php $changes = $l->change_list; @endphp
                @if ($l->meaning)
                    <p class="audit-meaning">{{ $l->meaning }}</p>
                @endif
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
                        @if ($c['note'])
                            <span class="audit-note">{{ $c['note'] }}</span>
                        @endif
                    </div>
                    @if ($loop->last && $i >= 3 && count($changes) > 4)
                        </details>
                    @endif
                @empty
                    @unless ($l->meaning)<span class="text-muted">—</span>@endunless
                @endforelse
            </td>
        </tr>
    @empty <tr><td colspan="7" class="text-center text-muted py-4">No audit entries.</td></tr> @endforelse
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div></div>
@endsection
