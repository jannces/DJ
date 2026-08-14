<header class="lms-topbar no-print">
    <button class="icon-btn d-lg-none" data-toggle-sidebar aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
    </button>
    <button class="icon-btn d-none d-lg-inline-flex" data-toggle-sidebar aria-label="Collapse navigation" title="Collapse menu">
        <i class="bi bi-layout-sidebar"></i>
    </button>

    <span class="top-sep d-none d-sm-block" aria-hidden="true"></span>
    <div class="top-title">
        <i class="bi bi-grid-1x2"></i><span>@yield('title', 'Dashboard')</span>
    </div>

    <div class="ms-auto d-flex align-items-center gap-1">
        {{-- Back-office only. Employees never see the global search box, and the
             /search route rejects them server-side via the same gate. --}}
        @can('use-global-search')
            <form class="lms-search d-none d-md-block me-1" action="{{ route('search') }}" method="GET" data-no-loader role="search">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="{{ request('q') }}"
                       placeholder="Search…" aria-label="Search employees, requests, departments">
                <span class="kbd" aria-hidden="true"><b>Ctrl</b><b>K</b></span>
            </form>
        @endcan
        @can('security.dashboard')
            <a href="{{ route('security.dashboard') }}" id="alert-bell" class="icon-btn"
               data-url="{{ route('web.security.alerts') }}"
               data-interval="{{ \App\Models\SystemSetting::get('general.alerts_poll_seconds', 15) }}"
               aria-label="Security alerts" title="Security alerts">
                <i class="bi bi-shield-exclamation"></i>
                <span id="alert-badge" class="dot-badge d-none">0</span>
            </a>
        @endcan

        <a href="{{ route('notifications.index') }}" class="icon-btn" aria-label="Notifications" title="Notifications">
            <i class="bi bi-bell"></i>
            @php $unread = auth()->user()?->unreadNotifications()->count() ?? 0 @endphp
            @if ($unread)<span class="dot-badge">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
        </a>

        <button class="icon-btn" onclick="lmsToggleTheme()" aria-label="Toggle dark mode" title="Light / dark mode">
            <i class="theme-icon bi bi-moon-stars"></i>
        </button>

        <div class="dropdown">
            <button class="profile-btn" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar">{{ strtoupper(mb_substr(auth()->user()?->name ?? 'U',0,1)) }}</span>
                <span class="d-none d-sm-flex flex-column text-start" style="line-height:1.15">
                    <span style="font-weight:600;font-size:.82rem">{{ \Illuminate\Support\Str::limit(auth()->user()?->name, 18) }}</span>
                    <span class="text-muted" style="font-size:.7rem">{{ ucwords(str_replace('-',' ', app(\App\Services\Rbac\RbacService::class)->userRoleSlugs(auth()->user())->first() ?? 'user')) }}</span>
                </span>
                <i class="bi bi-chevron-down text-muted" style="font-size:.7rem"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:220px">
                <li class="px-2 py-1">
                    <div class="fw-semibold" style="font-size:.85rem">{{ auth()->user()?->name }}</div>
                    <div class="text-muted" style="font-size:.75rem">{{ auth()->user()?->email }}</div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="{{ route('password.change') }}"><i class="bi bi-key me-2"></i>Change password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}" data-no-loader>
                        @csrf
                        <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
