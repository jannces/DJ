<header class="lms-topbar d-flex align-items-center gap-3 no-print">
    <button class="btn btn-sm btn-outline-secondary" data-toggle-sidebar aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
    </button>

    @can('dashboard.view')
        <form class="d-none d-md-block flex-grow-1" style="max-width:420px" action="{{ route('search') }}" method="GET" data-no-loader>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" value="{{ request('q') }}" class="form-control"
                       placeholder="Search employees, requests, departments…" aria-label="Global search">
            </div>
        </form>
    @endcan

    <div class="ms-auto d-flex align-items-center gap-2">
        @can('security.dashboard')
            <a href="{{ route('security.dashboard') }}" id="alert-bell" class="btn btn-sm btn-outline-secondary position-relative"
               data-url="{{ route('web.security.alerts') }}"
               data-interval="{{ \App\Models\SystemSetting::get('general.alerts_poll_seconds', 15) }}"
               aria-label="Security alerts">
                <i class="bi bi-bell"></i>
                <span id="alert-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
            </a>
        @endcan

        <a href="{{ route('notifications.index') }}" class="btn btn-sm btn-outline-secondary position-relative" aria-label="Notifications">
            <i class="bi bi-envelope"></i>
            @php $unread = auth()->user()?->unreadNotifications()->count() ?? 0 @endphp
            @if ($unread)
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ $unread > 99 ? '99+' : $unread }}</span>
            @endif
        </a>

        <button class="btn btn-sm btn-outline-secondary" onclick="lmsToggleTheme()" aria-label="Toggle dark mode">
            <i class="theme-icon bi bi-moon-stars"></i>
        </button>

        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle me-1"></i>{{ auth()->user()?->name }}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text small text-muted">{{ auth()->user()?->email }}</span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="{{ route('password.change') }}"><i class="bi bi-key me-2"></i>Change password</a></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}" data-no-loader>
                        @csrf
                        <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
