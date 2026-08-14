<nav class="lms-sidebar no-print" aria-label="Main navigation" id="lmsSidebar">
    <div class="lms-brand">
        <div class="seal"><i class="bi bi-buildings"></i></div>
        <div>
            <div class="brand-name">LGU Alicia</div>
            <div class="brand-sub">Leave Management</div>
        </div>
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

</nav>
