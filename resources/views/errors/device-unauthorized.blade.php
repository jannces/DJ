@extends('layouts.guest')
@section('title', 'Device not authorized')
@section('content')
<div class="text-center py-2">
    <i class="bi bi-pc-display-horizontal text-danger" style="font-size:3rem"></i>
    <h2 class="h5 mt-2">Device not authorized</h2>
    <p class="text-muted small">This computer ({{ request()->ip() }}) is not registered to access the
        Leave Management System. The attempt has been logged and the System Administrator notified.</p>
    <p class="text-muted small mb-0">Ask the System Administrator to register this device.</p>
</div>
@endsection
