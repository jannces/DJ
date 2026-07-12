@extends('layouts.guest')
@section('title', 'Too many requests')
@section('content')
<div class="text-center py-2">
    <i class="bi bi-hourglass-split text-warning" style="font-size:3rem"></i>
    <h2 class="h5 mt-2">Slow down</h2>
    <p class="text-muted small">Too many requests were made in a short period. Please wait a moment and try again.</p>
</div>
@endsection
