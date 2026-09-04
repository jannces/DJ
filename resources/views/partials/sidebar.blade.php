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

        // `permission` may be one slug or several, any one of which is enough.
        $needed = (array) $item['permission'];
        if (! collect($needed)->contains(fn ($p) => (bool) $user?->hasPermission($p))) {
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
    {{--
      The municipality's own mark, not a stand-in. This was a generic
      `bi-buildings` glyph in a coloured square, which is what every admin
      template ships with.

      It is the MARK, not the full logo. One Alicia's lockup puts the fist
      beside its own wordmark, and at the 34px this slot allows that wordmark
      is an unreadable smudge sitting next to a perfectly readable "LGU
      Alicia" saying much the same thing. So the fist comes in on its own and
      the name stays in type beside it, which is the usual way a wide logo is
      fitted to a narrow rail.

      The mark is decorative here: the words next to it already name the
      organisation, so announcing it again would just repeat them.
    --}}
    <div class="lms-brand">
        <img class="brand-mark" src="{{ asset('img/one-alicia-mark.png') }}"
             alt="" aria-hidden="true" width="256" height="256">
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
