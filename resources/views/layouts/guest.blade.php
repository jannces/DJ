<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') — {{ config('app.name', 'LGU Alicia LMS') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="text-center text-white mb-3">
            <div class="mx-auto mb-2 d-flex align-items-center justify-content-center"
                 style="width:64px;height:64px;border-radius:50%;background:var(--lgu-accent)">
                <i class="bi bi-building" style="font-size:1.8rem"></i>
            </div>
            <h1 class="h5 mb-0">Local Government Unit of Alicia</h1>
            <div class="small opacity-75">Digital Leave Management System</div>
        </div>
        <div class="card shadow-lg">
            <div class="card-body p-4">
                @if (session('status'))
                    <div class="alert alert-success py-2 small">{{ session('status') }}</div>
                @endif
                @yield('content')
            </div>
        </div>
        <p class="text-center text-white-50 small mt-3 mb-0">
            Authorized personnel and registered devices only.<br>All activity is monitored and logged.
        </p>
    </div>
</div>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
