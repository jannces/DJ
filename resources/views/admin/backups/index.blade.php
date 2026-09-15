@extends('layouts.app')
@section('title', 'Backups')
@section('content')
<h1 class="h4 mb-3">Backups</h1>

@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@error('backup')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <form method="POST" action="{{ route('backups.store') }}" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-archive" aria-hidden="true"></i> Create backup now
            </button>
        </form>
        {{-- Says what pressing it will cost in time. A button that appears to
             hang is a button somebody presses twice. --}}
        <span class="text-muted small">
            Takes a few seconds. The page returns when the archive is written.
        </span>
    </div>
</div>

<div class="card">
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Backup</th><th>Taken</th><th class="text-end">Size</th><th></th></tr></thead>
        <tbody>
        @forelse ($backups as $b)
            <tr>
                <td class="small">{{ $b['name'] }}</td>
                <td class="small">{{ $b['at']->format('d M Y, g:i a') }}</td>
                <td class="small text-end">{{ number_format($b['size'] / 1048576, 2) }} MB</td>
                <td class="text-end">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="{{ route('backups.download', $b['name']) }}">
                        <i class="bi bi-download" aria-hidden="true"></i> Download
                    </a>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No backups yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

{{-- Where they are, in words, because restoring one means finding the file
     from outside this system -- often on a day when this system is the thing
     that is broken. --}}
<p class="text-muted small mt-3 mb-0">
    Stored in <code>{{ $directory }}</code>, outside the web folder. Nothing here is
    reachable without signing in.
</p>
@endsection
