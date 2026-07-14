<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 rx=%2220%22 fill=%22%2314532d%22/%3E%3Ctext x=%2250%22 y=%2272%22 font-size=%2260%22 text-anchor=%22middle%22 fill=%22%23ca8a04%22%3E%E2%9A%96%3C/text%3E%3C/svg%3E">
    <title>@yield('title', 'Dashboard') — {{ config('app.name', 'LGU Alicia LMS') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/sweetalert2/sweetalert2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script>
        // Apply saved theme before first paint (prevents flashing).
        document.documentElement.setAttribute('data-bs-theme',
            localStorage.getItem('lms-theme') ||
            (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
    </script>
</head>
<body>
<div id="page-loader" aria-hidden="true">
    <div class="spinner-border text-light" role="status" style="width:3rem;height:3rem"></div>
</div>

<div class="lms-wrapper">
    @include('partials.sidebar')

    <div class="lms-main">
        @include('partials.topbar')

        <main class="lms-content">
            @if (session('warning'))
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ session('warning') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            @yield('content')
        </main>

        <footer class="text-center text-muted small py-3 no-print">
            {{ \App\Models\SystemSetting::get('general.lgu_name', 'Local Government Unit of Alicia') }}
            &middot; Cybersecurity Integrated Digital Leave Management System
        </footer>
    </div>
</div>

<div id="lms-flash" class="d-none"
     data-success="{{ session('status') ?? session('success') }}"
     data-error="{{ session('error') }}"
     data-warning="{{ session('toast_warning') }}"></div>

<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
