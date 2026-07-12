<nav class="lms-sidebar no-print" aria-label="Main navigation">
    <div class="lms-brand">
        <div class="seal"><i class="bi bi-building"></i></div>
        <div>
            <div class="fw-bold" style="font-size:.9rem">LGU Alicia</div>
            <div style="font-size:.7rem;opacity:.75">Leave Management System</div>
        </div>
    </div>
    <div class="lms-nav nav flex-column">
        @foreach (config('menu') as $item)
            @if (isset($item['heading']))
                @php
                    // Render a heading only when at least one item below it is visible.
                    $visible = false;
                    foreach (array_slice(config('menu'), $loop->index + 1) as $next) {
                        if (isset($next['heading'])) break;
                        if (auth()->user()?->hasPermission($next['permission'])) { $visible = true; break; }
                    }
                @endphp
                @if ($visible)
                    <div class="nav-heading">{{ $item['heading'] }}</div>
                @endif
            @elseif (auth()->user()?->hasPermission($item['permission']) && \Illuminate\Support\Facades\Route::has($item['route']))
                <a class="nav-link {{ request()->routeIs($item['route'].'*') ? 'active' : '' }}"
                   href="{{ route($item['route']) }}">
                    <i class="bi {{ $item['icon'] }}"></i><span>{{ $item['label'] }}</span>
                </a>
            @endif
        @endforeach
    </div>
</nav>
