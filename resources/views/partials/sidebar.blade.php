<nav class="lms-sidebar no-print" aria-label="Main navigation" id="lmsSidebar">
    <div class="lms-brand">
        <div class="seal"><i class="bi bi-buildings"></i></div>
        <div>
            <div class="brand-name">LGU Alicia</div>
            <div class="brand-sub">Leave Management</div>
        </div>
    </div>
    {{-- Workspace row: which office this installation serves. --}}
    <div class="side-ws">
        <span class="ws-dot" aria-hidden="true"></span>
        <span class="ws-name">{{ \App\Models\SystemSetting::get('general.lgu_short_name', 'LGU Alicia') }}</span>
        <i class="bi bi-chevron-expand" aria-hidden="true"></i>
    </div>

    <div class="lms-nav nav flex-column">
        @foreach (config('menu') as $item)
            @if (isset($item['heading']))
                @php
                    $visible = false;
                    foreach (array_slice(config('menu'), $loop->index + 1) as $next) {
                        if (isset($next['heading'])) break;
                        if (auth()->user()?->hasPermission($next['permission'])) { $visible = true; break; }
                    }
                @endphp
                @if ($visible)<div class="nav-heading">{{ $item['heading'] }}</div>@endif
            @elseif (auth()->user()?->hasPermission($item['permission']) && \Illuminate\Support\Facades\Route::has($item['route']))
                <a class="nav-link {{ request()->routeIs($item['route'].'*') ? 'active' : '' }}"
                   href="{{ route($item['route']) }}"
                   @if(request()->routeIs($item['route'].'*')) aria-current="page" @endif>
                    <i class="bi {{ $item['icon'] }}"></i><span>{{ $item['label'] }}</span>
                </a>
            @endif
        @endforeach
    </div>

    {{-- Bottom utility block. Mirrors the reference layout, but every link goes
         somewhere real in this system rather than being decoration. --}}
    <div class="side-foot">
        @can('leave.view-own')
            <div class="side-foot-label">Reference</div>
            <div class="side-note">
                <div class="side-note-title">CSC Form No. 6</div>
                <div class="side-note-sub">Documentary requirements for all 15 leave types.</div>
                <a href="{{ route('leave.instructions') }}">Read the instructions &rarr;</a>
            </div>
        @endcan
        <div class="side-links">
            <a href="{{ route('notifications.index') }}"><i class="bi bi-bell"></i>Notifications</a>
            <a href="{{ route('password.change') }}"><i class="bi bi-key"></i>Change password</a>
        </div>
    </div>
</nav>
