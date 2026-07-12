@extends('layouts.guest')
@section('title', 'Unauthorized access')
@section('content')
<div class="text-center py-2">
    <i class="bi bi-shield-x text-danger" style="font-size:3rem"></i>
    <h2 class="h5 mt-2">Unauthorized access</h2>
    <p class="text-muted small">You do not have permission to open that page.
        This attempt has been recorded in the security log.</p>
    <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="btn btn-lgu btn-sm">
        Return to safety
    </a>
</div>
@endsection
