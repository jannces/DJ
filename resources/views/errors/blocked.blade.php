@extends('layouts.guest')
@section('title', 'Access blocked')
@section('content')
<div class="text-center py-2">
    <i class="bi bi-slash-circle text-danger" style="font-size:3rem"></i>
    <h2 class="h5 mt-2">Access blocked</h2>
    <p class="text-muted small">Your IP address ({{ $ip }}) has been temporarily blocked due to suspicious
        activity. If you believe this is a mistake, contact the System Administrator.</p>
</div>
@endsection
