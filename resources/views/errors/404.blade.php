@extends('layouts.guest')
@section('title', 'Page not found')
@section('content')
<div class="text-center py-2">
    <i class="bi bi-signpost-split text-muted" style="font-size:3rem"></i>
    <h2 class="h5 mt-2">Page not found</h2>
    <p class="text-muted small">The page you requested does not exist or was moved.</p>
    <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="btn btn-lgu btn-sm">Go back</a>
</div>
@endsection
