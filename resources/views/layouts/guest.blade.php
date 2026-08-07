<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 rx=%2220%22 fill=%22%2314532d%22/%3E%3Ctext x=%2250%22 y=%2272%22 font-size=%2260%22 text-anchor=%22middle%22 fill=%22%23ca8a04%22%3E%E2%9A%96%3C/text%3E%3C/svg%3E">
    <title>@yield('title', 'Sign in') — {{ config('app.name', 'LGU Alicia LMS') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script>document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('lms-theme') || 'light');</script>
</head>
<body>
<div class="auth-page">
    <aside class="auth-aside">
        <div style="position:relative;z-index:1">
            <div class="a-seal"><i class="bi bi-buildings"></i></div>
            <div class="mt-4" style="font-size:.8rem;letter-spacing:.05em;text-transform:uppercase;color:#9fc3ac">Republic of the Philippines</div>
            <h2 class="mt-1">Local Government Unit<br>of Alicia</h2>
            <p style="color:#c5d8cc;max-width:420px">Cybersecurity Integrated Digital Leave Management System with Real-Time Intrusion Alerts.</p>
        </div>
        <div style="position:relative;z-index:1">
            <div class="feat"><i class="bi bi-shield-check"></i><div><strong style="color:#fff">Secure by design</strong><br><span style="font-size:.82rem">Two-factor OTP, account lockout, and live intrusion monitoring.</span></div></div>
            <div class="feat"><i class="bi bi-file-earmark-text"></i><div><strong style="color:#fff">CSC compliant</strong><br><span style="font-size:.82rem">Official CSC Form No. 6 with automated leave-credit computation.</span></div></div>
            <div class="feat"><i class="bi bi-diagram-3"></i><div><strong style="color:#fff">Full approval workflow</strong><br><span style="font-size:.82rem">Department Head → HR → Municipal Mayor, digitally signed.</span></div></div>
        </div>
        <div style="position:relative;z-index:1;font-size:.75rem;color:#8fb69f">
            Authorized personnel and registered devices only. All activity is monitored and logged.
        </div>
    </aside>
    <main class="auth-main">
        <div class="auth-card">
            @if (session('status'))
                <div class="alert alert-success py-2 small mb-3"><i class="bi bi-check-circle me-1"></i>{{ session('status') }}</div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
