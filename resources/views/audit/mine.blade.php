@extends('layouts.app')
@section('title', 'My Audit Log')
@section('content')
<h1 class="h4 mb-1">My Audit Log</h1>
{{-- Says whose trail this is, and that it is only theirs. Without it the page
     reads as though the system recorded nothing else, which is not the claim
     being made -- the claim is that this is the holder's own record. --}}
<p class="text-muted mb-3">Everything you have changed in the system, newest first. Only your own activity appears here.</p>

<div class="card">
    <x-list-toolbar :action="route('audit.mine')">
        <x-list-filter name="action" label="Action" :options="$actions" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Time</th><th>Action</th><th>Target</th><th>Changes</th></tr></thead>
    <tbody>
    @forelse ($logs as $l)
        <tr>
            <td class="small">{{ $l->created_at->format('M d, Y H:i:s') }}</td>
            <td><span class="badge bg-light text-dark">{{ $l->action_label }}</span></td>
            <td class="small">{{ $l->target_label ?? '—' }}</td>
            {{-- Same narration as the administrator's view: the row as it was
                 and the row as it is, with the sentence that says what the
                 change actually did. There is no reason to show the audited
                 person a thinner account of their own actions than an
                 administrator gets. --}}
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
    @empty <tr><td colspan="4" class="text-center text-muted py-4">Nothing recorded for you yet.</td></tr> @endforelse
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div></div>
@endsection
