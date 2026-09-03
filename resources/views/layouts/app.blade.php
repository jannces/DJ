<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 rx=%2220%22 fill=%22%236d28d9%22/%3E%3Ctext x=%2250%22 y=%2272%22 font-size=%2260%22 text-anchor=%22middle%22 fill=%22%23f5c518%22%3E%E2%9A%96%3C/text%3E%3C/svg%3E">
    <title>@yield('title', 'Dashboard') — {{ config('app.name', 'LGU Alicia LMS') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/sweetalert2/sweetalert2.min.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/app.css') }}">
    <script>
        document.documentElement.setAttribute('data-bs-theme',
            localStorage.getItem('lms-theme') ||
            (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
    </script>
</head>
<body>
{{-- The first focusable element on every page, and invisible until it is
     focused. Twelve sidebar entries and a topbar sit between the top of the
     document and the content; without this, a keyboard user tabs through all
     of them again on every single page. --}}
<a class="skip-link no-print" href="#lms-content">Skip to main content</a>

<div id="page-loader" aria-hidden="true"><div class="spinner-ring" role="status" aria-label="Loading"></div></div>

<div class="lms-wrapper">
    @include('partials.sidebar')
    <div class="lms-main">
        @include('partials.topbar')
        <main class="lms-content" id="lms-content" tabindex="-1">
            @if (session('warning'))
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ session('warning') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            @yield('content')
        </main>
        <footer class="app-foot no-print">
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
<script src="{{ \App\Support\Asset::url('js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
