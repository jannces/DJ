@php
    /**
     * Whether one menu item belongs in this user's rail.
     *
     * The heading loop and the item loop both need this answer, and when they
     * each had their own copy of it a heading could survive every item under it
     * — an "Administration" label with nothing beneath.
     */
    $itemVisible = function (array $item) {
        $user = auth()->user();

        if (! $user?->hasPermission($item['permission'])) {
            return false;
        }

        // `requires_any`: permitted, but only worth a link if there is
        // something behind it for this role.
        $any = $item['requires_any'] ?? [];
        if ($any !== [] && ! collect($any)->contains(fn ($p) => $user->hasPermission($p))) {
            return false;
        }

        return \Illuminate\Support\Facades\Route::has($item['route']);
    };
@endphp

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
                        if ($itemVisible($next)) { $visible = true; break; }
                    }
                @endphp
                @if ($visible)<div class="nav-heading">{{ $item['heading'] }}</div>@endif
            @elseif ($itemVisible($item))
                <a class="nav-link {{ request()->routeIs($item['route'].'*') ? 'active' : '' }}"
                   href="{{ route($item['route']) }}"
                   @if(request()->routeIs($item['route'].'*')) aria-current="page" @endif>
                    <i class="bi {{ $item['icon'] }}"></i><span>{{ $item['label'] }}</span>
                </a>
            @endif
        @endforeach
    </div>

</nav>
