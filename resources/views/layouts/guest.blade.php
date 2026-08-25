<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('img/alicia-seal.png') }}">
    <title>@yield('title', 'Sign in') — {{ config('app.name', 'LGU Alicia LMS') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script>document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('lms-theme') || 'light');</script>
</head>
<body>
<div class="auth-page">
    <aside class="auth-aside">
        <div class="auth-brand">
            <img class="auth-seal" src="{{ asset('img/alicia-seal.png') }}"
                 alt="Seal of the Municipality of Alicia, Isabela" width="400" height="400">
            <div>
                <p class="auth-rp">Republic of the Philippines</p>
                <p class="auth-mu">Municipality of Alicia</p>
            </div>
        </div>

        {{-- Two spacers whose grow factors add to 100 place the heading block.
             A percentage margin would resolve against the width, not the height. --}}
        <div class="auth-sp auth-sp-a" aria-hidden="true"></div>

        <div class="auth-hero">
            <h1>Local Government<br>Unit <em>of Alicia</em></h1>
            <p>Cybersecurity-integrated digital leave management with real-time intrusion alerts.</p>
        </div>

        <div class="auth-sp auth-sp-b" aria-hidden="true"></div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            @if (session('status'))
                <div class="auth-note auth-note-ok">
                    <i class="bi bi-check-circle"></i><span>{{ session('status') }}</span>
                </div>
            @endif
            @yield('content')
        </div>
        <p class="auth-need">Need access? Contact the <b>System Administrator</b>.</p>
    </main>
</div>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
