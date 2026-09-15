<header class="lms-topbar no-print">
    <button class="icon-btn d-lg-none" data-toggle-sidebar aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
    </button>
    <button class="icon-btn d-none d-lg-inline-flex" data-toggle-sidebar aria-label="Collapse navigation" title="Collapse menu">
        <i class="bi bi-layout-sidebar"></i>
    </button>

    {{--
      No page name here.

      It rendered @yield('title') -- the same string 25 pages already print as
      their own <h1> a few pixels below it. Which name to keep was decided by
      what each one does: the heading is semantic, it is what a screen reader
      builds its outline from, and on several pages it carries something extra
      like the pending count. This was a div.

      Wayfinding does not go missing either. The sidebar marks the current page
      with a filled pill and never scrolls away, so the topbar was the third
      statement of one fact rather than the only one.
    --}}

    <div class="ms-auto d-flex align-items-center gap-1">
        {{--
          The security alert BUTTON is gone; the alerting behind it is not.

          As a control it was redundant: clicking it went to the Security
          Dashboard, which the sidebar links two rows down. But the element was
          never really a button. It carries the poll URL and interval, and
          app.js binds to it to raise a toast the moment the detector logs an
          intrusion while the administrator is on some other page -- the
          "real-time" this system is named for, and the only part of it a
          person is not required to go looking for.

          So the icon and its count are removed and the holder stays, hidden.
          Nothing is clickable, nothing shows in the bar, and an intrusion
          still announces itself. The badge span stays inside the hidden holder
          because the poller writes to it; without it the script would throw on
          the first alert and stop polling silently.
        --}}
        @can('security.dashboard')
            <span id="alert-bell" class="d-none"
                  data-url="{{ route('web.security.alerts') }}"
                  data-interval="{{ \App\Models\SystemSetting::get('general.alerts_poll_seconds', 15) }}"
                  data-log-url="{{ route('security.intrusions') }}?q=" aria-hidden="true">
                <span id="alert-badge" class="d-none">0</span>
            </span>
        @endcan

        {{--
          The bell opens the last few notifications rather than a whole page.
          Reading one is a glance, not a destination; the page is still there
          behind "See all" for the rest.

          Bootstrap's dropdown, which is what the profile menu beside this
          already uses -- no new script, and the keyboard and dismiss
          behaviour comes with it.
        --}}
        @php
            $inbox = auth()->user()?->notifications()->latest()->limit(6)->get() ?? collect();
            $unread = auth()->user()?->unreadNotifications()->count() ?? 0;
        @endphp
        <div class="dropdown">
            <button class="icon-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                    aria-expanded="false"
                    aria-label="Notifications{{ $unread ? ", $unread unread" : '' }}"
                    title="Notifications">
                <i class="bi bi-bell"></i>
                @if ($unread)<span class="dot-badge">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
            </button>

            <div class="dropdown-menu dropdown-menu-end nb-menu">
                <div class="nb-head">
                    <span class="nb-title">Notifications</span>
                    @if ($unread)<span class="nb-count">{{ $unread }} new</span>@endif
                    @if ($unread)
                        <form method="POST" action="{{ route('notifications.read-all') }}" data-no-loader class="nb-allread">
                            @csrf
                            <button class="nb-allread-btn" type="submit">
                                <i class="bi bi-check2-all" aria-hidden="true"></i>
                                <span>Mark all read</span>
                            </button>
                        </form>
                    @endif
                </div>

                <ul class="nb-list">
                    @forelse ($inbox as $n)
                        @php
                            // The mark says what KIND of thing this is, because
                            // half of these are not about a person at all -- an
                            // auto-blocked IP has no name to take initials from.
                            // Leave notifications carry a reference; security
                            // ones carry an address.
                            $ref = $n->data['reference_no'] ?? null;
                            $state = $n->data['status'] ?? null;
                            $tone = match ($state) {
                                'approved' => 'ok',
                                'rejected' => 'bad',
                                'returned' => 'warn',
                                default => $ref ? 'info' : 'bad',
                            };
                            $meta = $ref
                                ? trim($ref.($state ? ' · '.ucfirst(str_replace('_', ' ', $state)) : ''))
                                : (isset($n->data['ip']) ? 'IP '.$n->data['ip'] : null);
                        @endphp
                        <li class="nb-item @unless($n->read_at) is-unread @endunless">
                            <span class="nb-mark" data-tone="{{ $tone }}" aria-hidden="true">
                                <i class="bi {{ $ref ? 'bi-file-earmark-text' : 'bi-shield-exclamation' }}"></i>
                            </span>

                            <a class="nb-body" href="{{ \App\Support\NotificationUrl::path($n->data['url'] ?? null, route('notifications.index', absolute: false)) }}">
                                <span class="nb-top">
                                    <span class="nb-subject">{{ $n->data['title'] ?? 'Notification' }}</span>
                                    @unless ($n->read_at)
                                        <span class="nb-new">New</span>
                                    @endunless
                                    <time class="nb-when" datetime="{{ $n->created_at->toIso8601String() }}"
                                          title="{{ $n->created_at->format('d M Y, g:i a') }}">
                                        {{ $n->created_at->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}
                                    </time>
                                </span>

                                @if ($meta)
                                    {{-- Coloured only when the status is one you
                                         have to do something about. Approved and
                                         submitted stay grey: there is nothing
                                         urgent to say, so nothing shouts. --}}
                                    <span class="nb-meta" data-tone="{{ $tone }}">{{ $meta }}</span>
                                @endif

                                <span class="nb-text">{{ $n->data['message'] ?? '' }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="nb-empty">Nothing yet.</li>
                    @endforelse
                </ul>

                <a class="nb-foot" href="{{ route('notifications.index') }}">See all notifications</a>
            </div>
        </div>

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
