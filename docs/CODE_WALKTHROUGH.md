# Code Walkthrough — Line by Line, in Plain English

This document explains the actual code in this repository: what each important
file does, what each line contributes, where the **roles** live, and what each
role is allowed to do.

Read it top to bottom and you will follow one web request from the moment it
hits the server to the moment a leave application is approved.

---

## PART 0 — The 30-second map

This is a **Laravel** application (PHP framework). Laravel splits work up like this:

| Folder | What lives there | Plain meaning |
|---|---|---|
| `routes/` | URL definitions | "when somebody visits this address, run this code" |
| `app/Http/Middleware/` | Request filters | guards standing at the door, checked *before* the page runs |
| `app/Http/Controllers/` | Page handlers | takes the request, returns a page |
| `app/Services/` | Business rules | the real thinking (leave maths, approvals, security) |
| `app/Models/` | Database tables | one PHP class per table (`User` = `users` table) |
| `resources/views/` | Blade templates | the HTML people actually see |
| `database/seeders/` | Starting data | **this is where the five roles are created** |
| `config/` | Settings files | `config/menu.php` builds the sidebar |

**The path every request takes:**

```
Browser
  → bootstrap/app.php          (the security kernel: which guards run, in what order)
  → BlockedIpMiddleware        (is this IP banned?)
  → AuthorizedDeviceMiddleware (is this PC on the LAN allow-list?)
  → IntrusionDetectionMiddleware (does this request look like an attack?)
  → routes/*.php               (which page is this?)
  → PermissionMiddleware       (does this user hold the required permission?)
  → Controller                 (gather data)
  → Service                    (apply the rules)
  → Model                      (read/write the database)
  → Blade view                 (draw the page)
```

---

## SECTION 1 — The security kernel: which guards run and in what order

**File:** `bootstrap/app.php`

**Code:**

```php
->withMiddleware(function (Middleware $middleware): void {
    // Security kernel — order matters (see docs/Architecture.md §3).
    $middleware->prepend([
        BlockedIpMiddleware::class,
        AuthorizedDeviceMiddleware::class,
        IntrusionDetectionMiddleware::class,
    ]);

    $middleware->web(append: [
        SessionTimeout::class,
        ActivityLogMiddleware::class,
        SecurityHeaders::class,
    ]);

    $middleware->api(append: [
        SecurityHeaders::class,
    ]);

    $middleware->alias([
        'permission' => PermissionMiddleware::class,
        'otp.verified' => EnsureOtpVerified::class,
        'force.pwchange' => ForcePasswordChange::class,
    ]);

    $middleware->throttleApi('api');
})
```

**Line-by-line explanation:**

* `->withMiddleware(function (Middleware $middleware) ...` → "Middleware" means a
  filter that every request passes through. This block registers all of them.
* `$middleware->prepend([...])` → **prepend** means "put these FIRST, before
  anything else Laravel does." The three listed are the security guards. Order
  is deliberate and cheap-to-expensive:
  * `BlockedIpMiddleware` first — if the IP is already banned, reject it
    immediately and do no further work.
  * `AuthorizedDeviceMiddleware` second — is this computer even allowed on the LAN?
  * `IntrusionDetectionMiddleware` third — the expensive one (pattern scanning),
    so it only runs on requests that survived the two cheap checks.
* `$middleware->web(append: [...])` → these run on normal browser pages, at the
  end: log the person out if idle (`SessionTimeout`), record the page visit
  (`ActivityLogMiddleware`), add protective HTTP headers (`SecurityHeaders`).
* `$middleware->api(append: [SecurityHeaders::class])` → API responses get the
  headers too, but not session timeout (APIs use tokens, not sessions).
* `$middleware->alias([...])` → gives short nicknames to middleware so routes can
  say `->middleware('permission:users.manage')` instead of writing the full class
  name. **This alias is the single most important line for understanding roles** —
  every `permission:` you see in the route files points here.
* `$middleware->throttleApi('api')` → rate-limits the API so nobody can hammer it.

**Code (the exception handler at the bottom):**

```php
->withExceptions(function (Exceptions $exceptions): void {
    // CSRF token failures are a STRIDE "Tampering" signal — record them.
    $exceptions->report(function (Illuminate\Session\TokenMismatchException $e) {
        if (app()->runningInConsole()) {
            return;
        }
        $request = request();
        \App\Models\IntrusionLog::create([
            'category' => 'csrf',
            'severity' => 'medium',
            ...
        ]);
    });
})
```

**Line-by-line explanation:**

* `TokenMismatchException` → Laravel puts a hidden random token in every form
  ("CSRF token"). If a submitted form has the wrong one, the form did not come
  from this site. That throws this exception.
* `if (app()->runningInConsole()) return;` → skip this when running a command-line
  task, because there is no browser request to record.
* `IntrusionLog::create([...])` → writes a row into the `intrusion_logs` table,
  category `csrf`. So a forged form attempt appears on the Security Dashboard
  instead of just being a silent error page.

**Function/Purpose:** This one file decides the entire defensive posture of the
system: who is filtered, in what order, and what gets recorded as an attack.

**Role/Permission involved:** None directly — this runs for *everybody*,
signed in or not. It is the layer *underneath* roles.

---

## SECTION 2 — Guard 1: is this IP banned?

**File:** `app/Http/Middleware/BlockedIpMiddleware.php`

**Code:**

```php
class BlockedIpMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        // Loopback / whitelisted IPs are never blocked (server can't lock itself out).
        if (IntrusionDetectionService::isTrustedIp($ip)) {
            return $next($request);
        }

        $blocked = Cache::remember("blocked-ip.{$ip}", 60, function () use ($ip) {
            return BlockedIp::currentlyActive()->where('ip', $ip)->exists();
        });

        if ($blocked) {
            return response()->view('errors.blocked', ['ip' => $ip], 403);
        }

        return $next($request);
    }
}
```

**Line-by-line explanation:**

* `public function handle(Request $request, Closure $next)` → every middleware has
  this shape. `$request` is the incoming request; `$next` is "the rest of the
  application." Calling `$next($request)` means *let it through*; returning
  something else means *stop here*.
* `$ip = $request->ip();` → gets the visitor's IP address (e.g. `192.168.1.40`).
* `if (IntrusionDetectionService::isTrustedIp($ip))` → a safety valve. The server
  itself (`127.0.0.1`) and any IP the admin whitelisted are **never** blocked. Without
  this, the system could ban its own administrator and nobody could get back in.
* `Cache::remember("blocked-ip.{$ip}", 60, ...)` → "have I already looked this IP
  up in the last 60 seconds? If yes reuse the answer; if no, run the database query
  and remember it for 60 seconds." This avoids a database hit on *every single*
  request, including images and CSS.
* `BlockedIp::currentlyActive()->where('ip', $ip)->exists()` → asks the
  `blocked_ips` table: is there an active, unexpired ban row for this IP? `exists()`
  returns just true/false, which is faster than fetching the row.
* `return response()->view('errors.blocked', ['ip' => $ip], 403);` → show the
  "you are blocked" page with HTTP status **403 Forbidden**. The request never
  reaches a controller.
* `return $next($request);` → not blocked, carry on.

**Function/Purpose:** Cheapest possible rejection of a known-bad address, done
before any other work.

**Role/Permission involved:** None — applies to everyone. Only the **System
Administrator** (permission `security.blocked-ips`) can create or lift these bans,
via `SecurityController`.

---

## SECTION 3 — Guard 2: is this PC allowed on the network?

**File:** `app/Http/Middleware/AuthorizedDeviceMiddleware.php`

**Code:**

```php
public function handle(Request $request, Closure $next): Response
{
    if (! SystemSetting::get('security.device_enforcement', false)) {
        return $next($request);
    }

    $ip = $request->ip();
    $device = Cache::remember("device.{$ip}", 60, function () use ($ip) {
        return AuthorizedDevice::active()->where('ip_address', $ip)->first() ?: false;
    });

    if ($device === false) {
        IntrusionLog::create([
            'category' => 'device',
            'severity' => 'high',
            'payload_excerpt' => "Unauthorized device attempted access from {$ip}",
            'matched_rule' => 'device_not_registered',
            ...
        ]);

        return response()->view('errors.device-unauthorized', [], 403);
    }

    // Throttled last-active heartbeat (drives online/offline on the dashboard).
    if (! Cache::has("device.seen.{$ip}")) {
        AuthorizedDevice::where('ip_address', $ip)->update(['last_active_at' => now()]);
        Cache::put("device.seen.{$ip}", true, 60);
    }

    return $next($request);
}
```

**Line-by-line explanation:**

* `if (! SystemSetting::get('security.device_enforcement', false))` → this whole
  feature is a switch in the System Settings page. `false` is the default, so if the
  administrator never turned it on, the guard does nothing and lets everyone through.
  The `!` means "not", so this reads "if enforcement is NOT on, skip."
* `AuthorizedDevice::active()->where('ip_address', $ip)->first() ?: false` → find an
  active row in `authorized_devices` for this IP. `?:` is the "or else" shortcut:
  if `first()` returns nothing (`null`), store `false` instead. This matters because
  the cache must be able to remember "I looked and found nothing" — caching `null`
  would look like "I have not looked yet."
* `if ($device === false)` → three equals signs means "exactly false, same type."
  This is the unregistered-computer case.
* `IntrusionLog::create([... 'severity' => 'high' ...])` → an unknown machine
  touching the system is treated as a **high severity** security event, not a mere
  error. It shows up on the Security Dashboard.
* `response()->view('errors.device-unauthorized', [], 403)` → stop, show the refusal page.
* `if (! Cache::has("device.seen.{$ip}")) { ... update(['last_active_at' => now()]) }`
  → "heartbeat": remember that this PC was seen just now, so the admin's device list
  can show online/offline. The cache wrapper means the database is written at most
  **once a minute per device** instead of on every click.

**Function/Purpose:** An optional LAN allow-list. When on, only registered
government computers can reach the system at all.

**Role/Permission involved:** Managed by the **System Administrator** through
permission `devices.manage` (routes `devices.index`, `devices.store`, …).

---

## SECTION 4 — Guard 3: the Intrusion Detection System (IDS)

**File:** `app/Http/Middleware/IntrusionDetectionMiddleware.php`

**Code:**

```php
class IntrusionDetectionMiddleware
{
    public function __construct(private readonly IntrusionDetectionService $ids)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($blockResponse = $this->ids->inspect($request)) {
            return $blockResponse;
        }

        return $next($request);
    }
}
```

**Line-by-line explanation:**

* `public function __construct(private readonly IntrusionDetectionService $ids)` →
  this is **dependency injection**. Laravel automatically creates an
  `IntrusionDetectionService` and hands it in. `private readonly` means: only this
  class can use it, and it can never be swapped out afterwards.
* `if ($blockResponse = $this->ids->inspect($request))` → note the **single** `=`:
  this both calls `inspect()` *and* stores the answer. `inspect()` returns either
  `null` (nothing wrong) or a ready-made refusal page. In PHP `null` counts as
  false, so the `if` only fires when there is something to refuse with.
* `return $blockResponse;` → hand back the refusal, request over.

**Function/Purpose:** A thin wrapper. All the real work is delegated to the
service, which keeps the middleware trivial and the rules testable.

---

## SECTION 5 — The IDS rules themselves

**File:** `app/Services/Security/IntrusionDetectionService.php`

### 5a. The two signature sets

**Code:**

```php
private array $signatures = [
    'sqli' => [
        'pattern' => '/(\bUNION\b.*\bSELECT\b|...|--\s|\/\*.*\*\/|\bSLEEP\s*\(|...)/i',
        'severity' => 'high', 'block' => true,
    ],
    'xss' => [
        'pattern' => '/(<script\b|<\/script>|javascript:|onerror\s*=|...)/i',
        'severity' => 'high', 'block' => true,
    ],
    'traversal' => [
        'pattern' => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|\/etc\/passwd|...|%00)/i',
        'severity' => 'high', 'block' => true,
    ],
];
```

**Line-by-line explanation:**

* `private array $signatures` → a list of attack fingerprints. Each entry has a
  **pattern** (a regular expression — a search pattern for text), a **severity**,
  and whether matching it should **block** the request.
* `'sqli'` → **SQL injection**: someone typing database commands into a form box to
  trick the database. `\bUNION\b.*\bSELECT\b` looks for the words UNION … SELECT,
  which is the classic way to make a database dump extra rows. `\b` means "word
  boundary" so it does not match the middle of an ordinary word.
* `--\s` → in SQL, `-- ` starts a comment; attackers use it to cut off the rest of
  a query. `/i` at the end of the pattern means case-insensitive.
* `'xss'` → **cross-site scripting**: injecting JavaScript so it runs in someone
  else's browser. `<script\b`, `javascript:`, `onerror=` are the usual carriers.
* `'traversal'` → **path traversal**: `../../etc/passwd` climbs out of the web
  folder to read system files. `%2e%2e%2f` is the same thing URL-encoded, which is
  why both spellings are listed.

**Code (the second, narrower set):**

```php
/**
 * A person writing "Family emergency -- urgent" is not attacking the server,
 * but the full SQL-comment patterns match that prose exactly...
 */
private array $freeTextSignatures = [
    'sqli' => [
        'pattern' => '/(\bUNION\b.*\bSELECT\b|...|\bSLEEP\s*\(|...)/i',
        ...
    ],
    ...
];

private const FREE_TEXT_FIELDS = [
    'purpose', 'purpose_other', 'comments', 'remarks', 'reason',
    'late_filing_reason', 'disapproval_reason', 'hr_override_reason',
    'illness', 'surgery_details', ...
];
```

**Line-by-line explanation:**

* Why two sets exist: the strict set includes `-- ` (SQL comment). A real employee
  typing "Family emergency -- urgent" in the *Purpose* box would have matched it,
  been shown a 400 error, had a high-severity intrusion recorded, and after five
  such events had their **IP banned for 24 hours** — for filing legitimate leave.
* `$freeTextSignatures` keeps only the patterns that have **no innocent reading in
  ordinary prose** (`UNION SELECT`, `<script>`, `/etc/passwd`) and drops the
  punctuation-only ones.
* `FREE_TEXT_FIELDS` → the list of form field names that humans write sentences into.
  Values from these fields are judged by the gentle rules; everything else (IDs,
  dates, the URL) is judged by the strict rules.

**Function/Purpose:** Security that does not punish employees for writing English.

**Role/Permission involved:** Affects **every role** — but in practice it protects
ordinary **Employees** from being falsely banned while filing leave.

### 5b. The inspection pass

**Code:**

```php
public function inspect(Request $request): ?Response
{
    if (! SystemSetting::get('security.ids_enabled', true)) {
        return null;
    }

    // Skip static asset requests.
    if (str_contains($request->path(), 'vendor/') || $request->is('*.css', '*.js', '*.png', '*.ico')) {
        return null;
    }

    $passes = [
        [$this->haystack($request), $this->signatures],
        [$this->freeTextHaystack($request), $this->freeTextSignatures],
    ];

    foreach ($passes as [$haystack, $signatures]) {
        if ($haystack === '') {
            continue;
        }
        foreach ($signatures as $category => $rule) {
            if (preg_match($rule['pattern'], $haystack, $matches)) {
                $this->record($request, $category, $rule['severity'], $matches[0] ?? $category);
                $this->maybeAutoBlock($request);

                if ($rule['block']) {
                    return response()->view('errors.blocked', ['ip' => $request->ip()], 400);
                }
            }
        }
    }

    if ($this->rateAnomaly($request)) {
        $this->record($request, 'rate', 'medium', 'request rate exceeded');
        if ($this->maybeAutoBlock($request)) {
            return response()->view('errors.blocked', ['ip' => $request->ip()], 429);
        }
    }

    return null;
}
```

**Line-by-line explanation:**

* `: ?Response` → the `?` means "returns a Response **or** null." Null = all clear.
* `SystemSetting::get('security.ids_enabled', true)` → the IDS can be switched off
  from System Settings; `true` is the default if the setting row is missing.
* the `vendor/`, `*.css`, `*.js` check → skip images and stylesheets. They are not
  user input and scanning them is wasted work.
* `$passes = [[haystack, rules], [haystack, rules]]` → builds the two scans:
  pass 1 = URL + structured input against the strict rules; pass 2 = free text
  against the gentle rules.
* `foreach ($passes as [$haystack, $signatures])` → this square-bracket form
  unpacks each pair into two named variables in one step ("destructuring").
* `preg_match($rule['pattern'], $haystack, $matches)` → PHP's regular expression
  search. Returns 1 if the pattern is found. `$matches[0]` receives the exact text
  that matched, which is what gets logged as evidence.
* `$this->record(...)` → write the intrusion row.
* `$this->maybeAutoBlock($request)` → count recent events from this IP and possibly
  ban it.
* `return response()->view('errors.blocked', ..., 400)` → **400 Bad Request** for a
  signature hit; the request never reaches a controller.
* `$this->rateAnomaly($request)` → separately, is this IP simply making too many
  requests per minute? That returns **429 Too Many Requests**.

### 5c. Splitting user input into "prose" and "not prose"

**Code:**

```php
private function collect(array $input, array &$parts, bool $freeText, bool $inheritedFreeText = false): void
{
    foreach ($input as $key => $value) {
        $isFreeText = $inheritedFreeText
            || in_array(strtolower((string) $key), self::FREE_TEXT_FIELDS, true);

        if (is_array($value)) {
            $this->collect($value, $parts, $freeText, $isFreeText);

            continue;
        }
        if (! is_scalar($value)) {
            continue;
        }
        if ($isFreeText === $freeText) {
            $parts[] = (string) $value;
        }
    }
}
```

**Line-by-line explanation:**

* `array &$parts` → the `&` means **by reference**: the function writes into the
  caller's own array rather than a copy. That is how results get back out.
* `$isFreeText = $inheritedFreeText || in_array(...)` → a field counts as prose
  either because its own name is in the list, or because its **parent** was prose.
  That is what makes `details[illness]` count as prose: the key checked is `illness`.
* `if (is_array($value)) { $this->collect(...); continue; }` → **recursion**: forms
  can nest (`details[surgery_details]`), so the function calls itself to walk deeper.
* `if (! is_scalar($value)) continue;` → skip anything that is not a plain
  string/number (e.g. an uploaded file object).
* `if ($isFreeText === $freeText)` → the clever bit: the same function is used for
  both passes. Call it with `freeText: true` and it collects only prose; call it
  with `false` and it collects everything else.

### 5d. Rate anomaly and auto-blocking

**Code:**

```php
private function rateAnomaly(Request $request): bool
{
    if ($this->isRateExempt($request)) {
        return false;
    }

    $limit = (int) SystemSetting::get('security.rate_limit_per_minute', 120);
    $key = 'ids.rate.'.$request->ip().'.'.now()->format('YmdHi');
    $count = (int) Cache::get($key, 0) + 1;
    Cache::put($key, $count, now()->addMinutes(2));

    return $count > $limit;
}
```

**Line-by-line explanation:**

* `$key = 'ids.rate.' . IP . '.' . now()->format('YmdHi')` → the counter key
  includes the **current minute** (`YmdHi` = year-month-day-hour-minute). This is
  the fix for a real bug documented in the file: the old key had no minute in it and
  was rewritten by every request, so it only expired after 60 seconds of *total
  silence*. Since the notification bell polls every 15 seconds, silence never came —
  the counter climbed forever and eventually flagged every request, then auto-banned
  a real employee for leaving the page open.
* `$count = (int) Cache::get($key, 0) + 1; Cache::put($key, $count, ...)` → read the
  count for this minute (0 if none), add one, store it back with a 2-minute life.
* `return $count > $limit;` → true means "too fast."

**Code:**

```php
public static function isTrustedIp(?string $ip): bool
{
    if ($ip === null) {
        return false;
    }
    $always = ['127.0.0.1', '::1'];
    $configured = array_filter(array_map('trim', explode(',', (string) SystemSetting::get('security.never_block_ips', ''))));

    return in_array($ip, array_merge($always, $configured), true);
}

public function maybeAutoBlock(Request $request): bool
{
    $ip = $request->ip();

    if (self::isTrustedIp($ip)) {
        return false;
    }

    $threshold = (int) SystemSetting::get('security.auto_block_threshold', 5);
    $windowMin = (int) SystemSetting::get('security.auto_block_window_minutes', 10);

    $recent = IntrusionLog::where('ip', $ip)
        ->where('created_at', '>=', now()->subMinutes($windowMin))->count();

    if ($recent < $threshold) {
        return false;
    }

    if (BlockedIp::currentlyActive()->where('ip', $ip)->exists()) {
        return true;
    }

    $hours = (int) SystemSetting::get('security.ip_block_hours', 24);
    $block = BlockedIp::updateOrCreate(['ip' => $ip], [
        'reason' => "Automatic block: {$recent} intrusion events in {$windowMin} minutes",
        'source' => 'auto',
        'expires_at' => now()->addHours($hours),
        'active' => true,
    ]);
    Cache::forget("blocked-ip.{$ip}");

    $this->audit->log('ip_auto_blocked', $block, [], ['ip' => $ip, 'events' => $recent]);
    $this->alerts->ipAutoBlocked($ip, $recent);

    return true;
}
```

**Line-by-line explanation:**

* `isTrustedIp` → `explode(',', ...)` splits the admin's comma-separated whitelist
  into a list; `array_map('trim', ...)` strips stray spaces; `array_filter(...)`
  drops empty entries. `127.0.0.1` and `::1` (IPv4 and IPv6 loopback) are *always*
  trusted so the server cannot ban itself.
* `$threshold` / `$windowMin` → both come from System Settings: 5 events in 10
  minutes by default.
* `IntrusionLog::where('ip', $ip)->where('created_at', '>=', now()->subMinutes($windowMin))->count()`
  → a database query: "how many intrusion rows from this IP in the last 10 minutes?"
* `if ($recent < $threshold) return false;` → not enough, do nothing.
* `if (BlockedIp::currentlyActive()...->exists()) return true;` → already banned;
  report "a block applies" without writing a duplicate row.
* `BlockedIp::updateOrCreate(['ip' => $ip], [...])` → create the ban, or update the
  existing row for that IP. `expires_at` makes it a 24-hour ban, not forever.
* `Cache::forget("blocked-ip.{$ip}")` → **important**: `BlockedIpMiddleware` caches
  its answer for 60 seconds. Clearing that key makes the ban take effect on the very
  next request instead of up to a minute later.
* `$this->audit->log(...)` → permanent record of who/what/when.
* `$this->alerts->ipAutoBlocked(...)` → notify administrators (bell + email).

**Function/Purpose:** Detect attacks, record them, refuse them, and escalate a
repeat offender into a temporary IP ban — without ever being able to lock out the
server or the administrator.

**Role/Permission involved:** The resulting logs are read by the **System
Administrator** (`security.intrusions`, `security.dashboard`, `security.blocked-ips`).

---

## SECTION 6 — Signing in

**File:** `app/Http/Controllers/Auth/LoginController.php`

**Code (the constructor):**

```php
public function __construct(
    private readonly LoginSecurityService $security,
    private readonly OtpService $otp,
    private readonly AuditLogger $audit,
) {
}
```

**Line-by-line explanation:**

* Three helpers are injected automatically: `$security` handles lockouts, `$otp`
  handles the emailed one-time password, `$audit` writes the audit trail.
* Declaring them in the constructor with `private readonly` is Laravel/PHP 8
  shorthand — it creates the properties *and* assigns them in one go.

**Code (the login method, part 1 — validation and throttling):**

```php
public function login(Request $request): RedirectResponse
{
    $credentials = $request->validate([
        'identifier' => ['required', 'string', 'max:255'],
        'password' => ['required', 'string', 'max:255'],
        'remember' => ['nullable', 'boolean'],
    ]);

    // Layered throttle on top of the account lockout (STRIDE: DoS).
    $throttleKey = strtolower($credentials['identifier']).'|'.$request->ip();
    if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
        return back()->withErrors([
            'identifier' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
        ]);
    }
    RateLimiter::hit($throttleKey, 60);
```

**Line-by-line explanation:**

* `$request->validate([...])` → checks the submitted form. `required` = must be
  present, `max:255` = length limit, `nullable` = may be absent. If validation
  fails, Laravel automatically sends the user back with error messages — the code
  below never runs.
* `identifier` (not "email") → the login box accepts **either** a username or an
  email address.
* `$throttleKey = strtolower(identifier) . '|' . ip` → the throttle counts attempts
  per *username-and-IP combination*. `strtolower` so `Juan` and `juan` share a
  counter. The `|` just separates the two halves.
* `RateLimiter::tooManyAttempts($throttleKey, 5)` → more than 5 tries? Refuse.
* `RateLimiter::availableIn($throttleKey)` → how many seconds until they may retry,
  so the message can say so.
* `RateLimiter::hit($throttleKey, 60)` → record this attempt; the counter resets
  after 60 seconds.

Note this is a **second** layer. The account lockout (3 strikes / 24 hours) is
separate and is about the *account*; this throttle is about the *connection*.

**Code (part 2 — finding the user and the four refusal cases):**

```php
    $field = filter_var($credentials['identifier'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    $user = User::where($field, $credentials['identifier'])->first();

    if ($user) {
        $this->security->liftExpiredBlock($user);
        $user->refresh();
    }

    if (! $user) {
        $this->security->recordFailure($request, $credentials['identifier'], null, 'unknown_user');

        return back()->withErrors(['identifier' => 'These credentials do not match our records.'])->onlyInput('identifier');
    }

    if ($user->isBlocked()) {
        $this->security->recordFailure($request, $credentials['identifier'], null, 'blocked');

        return back()->withErrors([
            'identifier' => 'This account is blocked until '.$user->blocked_until?->format('M d, Y h:i A').'. Contact the System Administrator.',
        ])->onlyInput('identifier');
    }

    if ($user->status === User::STATUS_INACTIVE) {
        $this->security->recordFailure($request, $credentials['identifier'], null, 'inactive');

        return back()->withErrors(['identifier' => 'This account is deactivated.'])->onlyInput('identifier');
    }

    if (! Hash::check($credentials['password'], $user->password)) {
        $this->security->recordFailure($request, $credentials['identifier'], $user, 'invalid_password');
        $remaining = max(0, $this->security->maxAttempts() - $user->refresh()->failed_attempts);
        $message = $user->isBlocked()
            ? 'Account blocked for 24 hours after repeated failures. Contact the System Administrator.'
            : "Invalid password. {$remaining} attempt(s) remaining before the account is blocked.";

        return back()->withErrors(['identifier' => $message])->onlyInput('identifier');
    }
```

**Line-by-line explanation:**

* `filter_var($x, FILTER_VALIDATE_EMAIL) ? 'email' : 'username'` → if what they
  typed looks like an email address, search the `email` column; otherwise search
  `username`. The `? :` is a compact if/else.
* `User::where($field, ...)->first()` → the database query. `first()` returns the
  matching row or `null`.
* `$this->security->liftExpiredBlock($user); $user->refresh();` → if the person was
  blocked 24 hours ago and the block has now expired, lift it *before* judging them.
  `refresh()` re-reads the row so `$user` reflects the change.
* **Case 1, no such user** → still records a failed-login row (so repeated probing
  of made-up usernames is visible), then shows a deliberately vague message.
  `->onlyInput('identifier')` re-fills the username box but **never** the password box.
* **Case 2, blocked** → `$user->blocked_until?->format(...)`. The `?->` is the
  "nullsafe" operator: if `blocked_until` is null (a manual, indefinite block), it
  quietly yields nothing instead of crashing.
* **Case 3, inactive** → an account switched off by an administrator.
* **Case 4, wrong password** → `Hash::check($plain, $hashed)` compares the typed
  password against the stored **hash**. Passwords are never stored readable, so you
  cannot compare them with `===`.
  * `$remaining = max(0, maxAttempts - failed_attempts)` → how many tries are left.
    `max(0, ...)` stops it ever printing a negative number.
  * `$user->refresh()` is called again because `recordFailure()` just incremented
    the counter in the database, and this code needs the new value.
  * The message then differs depending on whether *this* failure was the one that
    tripped the lockout.

**Code (part 3 — success):**

```php
    Auth::login($user, (bool) ($credentials['remember'] ?? false));
    $request->session()->regenerate(); // session fixation defense
    RateLimiter::clear($throttleKey);
    $this->security->recordSuccess($request, $user);

    if ($this->otp->enabled()) {
        $request->session()->put('otp_verified', false);
        $this->otp->issue($user);

        return redirect()->route('otp.show')->with('status', 'We emailed you a one-time password.');
    }

    $request->session()->put('otp_verified', true);

    return redirect()->intended(route('dashboard'));
}
```

**Line-by-line explanation:**

* `Auth::login($user, $remember)` → signs the person in. The second argument sets
  the long-lived "remember me" cookie.
* `$request->session()->regenerate();` → issues a **brand-new session ID**. This
  defeats *session fixation*, where an attacker plants a known session ID on a
  victim's browser beforehand and then rides it once they log in.
* `RateLimiter::clear($throttleKey);` → a correct password wipes the attempt counter.
* `recordSuccess(...)` → zeroes `failed_attempts`, stamps `last_login_at` /
  `last_login_ip`, and writes a `login` audit row.
* `if ($this->otp->enabled())` → two-factor is a system setting.
  * `session()->put('otp_verified', false)` → mark the session as **half** logged in.
  * `$this->otp->issue($user)` → generate and email the six-digit code.
  * `redirect()->route('otp.show')` → send them to the code entry screen.
* If OTP is off, mark the session verified and go to the dashboard.
* `redirect()->intended(route('dashboard'))` → **intended** means "back to the page
  they originally asked for before being bounced to login," falling back to the
  dashboard.

**Function/Purpose:** Authenticate a person while producing a record of every
attempt and never revealing which half of the credentials was wrong.

**Role/Permission involved:** None yet — roles are consulted *after* login, by
`PermissionMiddleware`. Everyone, from Employee to System Administrator, passes
through this identical code.

---

## SECTION 7 — The 3-strike lockout

**File:** `app/Services/Auth/LoginSecurityService.php`

**Code:**

```php
public function maxAttempts(): int
{
    return (int) SystemSetting::get('auth.lockout_attempts', 3);
}

public function recordFailure(Request $request, string $identifier, ?User $user, string $reason): void
{
    FailedLogin::create([
        'identifier' => $identifier,
        'user_id' => $user?->id,
        'ip' => $request->ip(),
        'user_agent' => substr((string) $request->userAgent(), 0, 500),
        'reason' => $reason,
        'occurred_at' => now(),
    ]);

    if (! $user) {
        return;
    }

    $user->increment('failed_attempts');
    if ($user->failed_attempts >= $this->maxAttempts()) {
        $this->blockAccount($user, $request);
    }
}
```

**Line-by-line explanation:**

* `maxAttempts()` → reads the threshold from System Settings (default 3), so the
  administrator can change it without touching code.
* `FailedLogin::create([...])` → one row per failed attempt, carrying the typed
  identifier, the IP, the browser string, and *why* it failed.
* `substr((string) $request->userAgent(), 0, 500)` → browser strings can be very
  long; cut to 500 characters so the database column never overflows.
* `if (! $user) return;` → if the username did not exist there is nothing to count
  against. This is deliberate: you cannot lock out an account that does not exist.
* `$user->increment('failed_attempts')` → adds 1 directly in the database (one
  atomic SQL statement, safe under simultaneous attempts).
* `if ($user->failed_attempts >= $this->maxAttempts())` → third strike → lock.

**Code (the lockout itself):**

```php
public function blockAccount(User $user, Request $request): void
{
    $hours = (int) SystemSetting::get('auth.lockout_hours', 24);
    $reason = sprintf('Exceeded %d failed login attempts', $this->maxAttempts());

    $user->forceFill([
        'status' => User::STATUS_BLOCKED,
        'blocked_until' => now()->addHours($hours),
        'blocked_reason' => $reason,
    ])->save();

    IntrusionLog::create([
        'category' => 'auth_fail',
        'severity' => 'high',
        'matched_rule' => 'lockout_threshold',
        ...
    ]);

    $this->audit->log('account_blocked', $user, [], [
        'reason' => $reason,
        'blocked_until' => (string) $user->blocked_until,
        'ip' => $request->ip(),
        'browser' => (string) $request->userAgent(),
    ], $user);

    $this->alerts->accountLocked(
        $user->email,
        (string) $request->ip(),
        $this->maxAttempts(),
        $user->blocked_until?->format('D, d M Y H:i'),
    );
}
```

**Line-by-line explanation:**

* `now()->addHours($hours)` → sets the moment the block lifts (24 hours from now).
* `forceFill([...])->save()` → writes those columns even though some are not in the
  model's `$fillable` list. `forceFill` is the deliberate "I know what I am doing"
  version, used here because these are security columns that no form may ever set.
* `IntrusionLog::create([... 'category' => 'auth_fail', 'severity' => 'high' ...])`
  → a lockout **is** a brute-force detection, so it appears on the Security
  Dashboard alongside SQL-injection attempts.
* `$this->audit->log('account_blocked', ...)` → the permanent who/what/when record,
  including the browser, so an administrator investigating later has the evidence.
* `$this->alerts->accountLocked(...)` → sends the alert: a signed-in administrator
  sees the topbar bell; one who is not signed in gets an email.

**Code (unblocking and auto-expiry):**

```php
public function unblockAccount(User $user, ?User $admin = null, string $how = 'manual'): void
{
    $old = ['status' => $user->status, 'blocked_until' => (string) $user->blocked_until];
    $user->forceFill([
        'status' => User::STATUS_ACTIVE,
        'blocked_until' => null,
        'blocked_reason' => null,
        'failed_attempts' => 0,
    ])->save();

    $this->audit->log('account_unblocked', $user, $old, ['how' => $how], $admin);
}

/** Auto-lift expired 24h blocks (scheduler + opportunistic check at login). */
public function liftExpiredBlock(User $user): bool
{
    if ($user->status === User::STATUS_BLOCKED
        && $user->blocked_until !== null
        && $user->blocked_until->isPast()) {
        $this->unblockAccount($user, null, 'expired');

        return true;
    }

    return false;
}
```

**Line-by-line explanation:**

* `$old = [...]` → captures the before-state so the audit row can show old → new.
* `unblockAccount` clears all four columns together — status, until, reason, and the
  strike counter. Forgetting `failed_attempts` would mean the next single mistake
  re-locks the account instantly.
* `$how = 'manual'` → distinguishes an administrator unblocking someone from the
  system auto-lifting an expired block. Both are audited, with the reason recorded.
* `liftExpiredBlock` → all three conditions must hold: status is blocked, there is
  an expiry date (a **manual** block has `blocked_until = null` and so is never
  auto-lifted), and that date is in the past.

**Function/Purpose:** Turn repeated wrong passwords into a temporary, audited,
self-expiring account block that also raises a security alert.

**Role/Permission involved:** The manual override — block/unblock by hand — belongs
to the **System Administrator** via permission `users.block`
(`UserController::block()` / `unblock()`).

---

## SECTION 8 — The emailed one-time password (OTP)

**File:** `app/Services/Auth/OtpService.php`

**Code:**

```php
public function issue(User $user, string $purpose = 'login'): void
{
    // Reissue invalidates any previous outstanding code (replay resistance).
    OtpCode::where('user_id', $user->id)
        ->where('purpose', $purpose)
        ->whereNull('consumed_at')
        ->update(['consumed_at' => now()]);

    $code = (string) random_int(100000, 999999);
    $ttl = (int) SystemSetting::get('auth.otp_ttl_minutes', 5);

    OtpCode::create([
        'user_id' => $user->id,
        'code_hash' => hash('sha256', $code),
        'purpose' => $purpose,
        'expires_at' => now()->addMinutes($ttl),
        'ip' => app()->runningInConsole() ? null : request()->ip(),
    ]);

    Mail::to($user->email)->queue(new OtpCodeMail($user, $code, $ttl));
}
```

**Line-by-line explanation:**

* The first query marks every **unused** previous code as consumed. So pressing
  "resend" three times leaves exactly one valid code, not three. That is *replay
  resistance*.
* `whereNull('consumed_at')` → "where this column is empty", i.e. still unused.
* `random_int(100000, 999999)` → a six-digit code. `random_int` is the
  cryptographically secure generator; `rand()` would be guessable.
* `hash('sha256', $code)` → **the code is stored hashed, never in plain text**. If
  somebody reads the database they still cannot use the codes.
* `expires_at => now()->addMinutes($ttl)` → default 5 minutes, configurable.
* `Mail::to(...)->queue(...)` → `queue` rather than `send`: the email is handed to
  a background worker so the login page responds instantly instead of waiting for
  the mail server.

**Code (verification):**

```php
public function verify(User $user, string $code, string $purpose = 'login'): bool
{
    $otp = OtpCode::where('user_id', $user->id)
        ->where('purpose', $purpose)
        ->whereNull('consumed_at')
        ->latest('id')
        ->first();

    if (! $otp || ! $otp->isUsable()) {
        return false;
    }

    if (! hash_equals($otp->code_hash, hash('sha256', $code))) {
        $otp->increment('attempts');

        return false;
    }

    $otp->update(['consumed_at' => now()]);

    return true;
}
```

**Line-by-line explanation:**

* `->latest('id')->first()` → the newest unconsumed code for this person.
* `! $otp->isUsable()` → the model's own check: not expired and under the attempt
  ceiling (`MAX_ATTEMPTS = 5`).
* `hash_equals($a, $b)` → compares two hashes in **constant time**. An ordinary
  `===` returns faster when the first character differs, and that timing difference
  can be measured to guess a code character by character. `hash_equals` always takes
  the same time.
* `$otp->increment('attempts')` → a wrong guess burns one of the five tries.
* `$otp->update(['consumed_at' => now()])` → **single use**: a correct code is
  immediately spent and cannot be replayed.

**File:** `app/Http/Middleware/EnsureOtpVerified.php`

**Code:**

```php
public function handle(Request $request, Closure $next): Response
{
    if ($request->user()
        && $this->otp->enabled()
        && ! $request->session()->get('otp_verified', false)) {
        return redirect()->route('otp.show');
    }

    return $next($request);
}
```

**Line-by-line explanation:**

* Three conditions must all hold to redirect: somebody is signed in, OTP is
  switched on, and the session is **not** marked verified.
* `session()->get('otp_verified', false)` → the flag `LoginController` set. Default
  `false` means "if the flag is missing, treat it as unverified" — failing safe.
* Anyone in this half-signed-in state is bounced to the code entry page, no matter
  which URL they typed.

**Where it is wired in:** `routes/web.php` —

```php
Route::middleware(['auth', 'otp.verified', 'force.pwchange'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    ...
    require __DIR__.'/leave.php';
    require __DIR__.'/admin.php';
});
```

Every leave route and every admin route is inside this group, so **no page in the
system is reachable without: signed in + OTP verified + password changed.**

**Function/Purpose:** A second factor that cannot be replayed, cannot be brute
forced (5 tries), expires in 5 minutes, and is unreadable in the database.

**Role/Permission involved:** Applies to every role identically.

---

## SECTION 9 — Three small but important guards

### 9a. Forcing a password change

**File:** `app/Http/Middleware/ForcePasswordChange.php`

**Code:**

```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();

    if ($user && $user->must_change_password
        && ! $request->routeIs('password.change*', 'logout')) {
        return redirect()->route('password.change');
    }

    return $next($request);
}
```

**Line-by-line explanation:**

* `$user->must_change_password` → a true/false column set when an administrator
  creates the account or resets its password.
* `! $request->routeIs('password.change*', 'logout')` → the two escapes. Without
  them the user would be redirected to the change-password page *from* the
  change-password page — an endless loop. The `*` matches both the form and its
  submit route.
* Everything else is redirected. A new employee therefore cannot look at anything
  until they have set their own password.

**Role/Permission involved:** Triggered by the **System Administrator** creating an
account (`UserController::store`) or resetting one (`resetPassword`).

### 9b. Idle session timeout

**File:** `app/Http/Middleware/SessionTimeout.php`

**Code:**

```php
if ($request->user()) {
    $idleMinutes = (int) SystemSetting::get('auth.session_idle_minutes', 30);
    $lastSeen = $request->session()->get('last_activity_at');

    if ($lastSeen && now()->timestamp - $lastSeen > $idleMinutes * 60) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['message' => 'Session expired.'], 401)
            : redirect()->route('login')->with('status', 'Your session expired due to inactivity. Please sign in again.');
    }

    $request->session()->put('last_activity_at', now()->timestamp);
}
```

**Line-by-line explanation:**

* `now()->timestamp - $lastSeen > $idleMinutes * 60` → both are counts of seconds,
  so the subtraction is "seconds since the last click." `* 60` converts the
  configured minutes into seconds.
* `Auth::logout()` signs them out; `session()->invalidate()` throws the session data
  away; `regenerateToken()` issues a fresh CSRF token so the login form works.
* `$request->expectsJson() ? ... : ...` → a background AJAX call gets a **401**
  status it can react to; a person in a browser gets a friendly redirect.
* `session()->put('last_activity_at', now()->timestamp)` → the last line resets the
  clock on every request, which is what makes it an *idle* timeout rather than a
  hard session length.

**Why this matters for an LGU:** shared counter PCs. Thirty minutes away from the
desk and the next person cannot use your account.

### 9c. Activity logging

**File:** `app/Http/Middleware/ActivityLogMiddleware.php`

**Code:**

```php
$response = $next($request);

// Log meaningful navigations only (skip polling/asset/HEAD noise).
if ($request->user()
    && ! $request->isMethod('HEAD')
    && ! $request->routeIs('api.security.alerts')
    && ! str_contains((string) $request->path(), 'vendor/')) {
    ActivityLog::create([
        'user_id' => $request->user()->id,
        'method' => $request->method(),
        'path' => substr($request->path(), 0, 255),
        'route_name' => $request->route()?->getName(),
        'ip' => $request->ip(),
        'user_agent' => substr((string) $request->userAgent(), 0, 500),
    ]);
}

return $response;
```

**Line-by-line explanation:**

* `$response = $next($request);` **first** → unlike the guards, this runs *after*
  the page is produced. It observes rather than blocks.
* The three `!` conditions filter out noise: `HEAD` requests (browsers checking
  whether a page changed), the alert bell polling every 15 seconds, and static
  assets under `vendor/`. Without these filters one person with a tab open would
  write thousands of meaningless rows a day.
* `$request->route()?->getName()` → the friendly route name such as `leave.store`.
  The `?->` guards against routes that have no name.

**Function/Purpose:** Answers "who looked at what, and when" — the *Repudiation*
leg of STRIDE (nobody can deny having visited a page).

**Role/Permission involved:** Written for everyone, read by the **System
Administrator** only, through permission `activity.view` (route `activity.index`).

### 9d. Security headers

**File:** `app/Http/Middleware/SecurityHeaders.php`

**Code:**

```php
$headers = [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Referrer-Policy' => 'same-origin',
    'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
    'X-XSS-Protection' => '1; mode=block',
    'Content-Security-Policy' => "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data:; font-src 'self' data:; "
        ."connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
];

if ($request->secure()) {
    $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
}

foreach ($headers as $key => $value) {
    $response->headers->set($key, $value);
}
```

**Line-by-line explanation:**

* `X-Content-Type-Options: nosniff` → stops the browser guessing a file's type. An
  uploaded image cannot be coaxed into running as a script.
* `X-Frame-Options: DENY` → no other website may load this system inside a frame.
  That defeats *clickjacking* (an invisible frame over a fake button).
* `Referrer-Policy: same-origin` → do not tell external sites which internal page a
  user came from.
* `Permissions-Policy: geolocation=(), microphone=(), camera=()` → the empty
  brackets mean "nobody" — the page may not request location, mic or camera.
* `Content-Security-Policy: default-src 'self'` → the browser may only load
  scripts, styles, images and fonts **from this server**. This is what makes the
  "fully offline LAN" claim real: no CDN, and an injected `<script src="evil.com">`
  would be refused by the browser itself.
* `frame-ancestors 'none'` / `form-action 'self'` → nobody may frame us; forms may
  only submit back to us.
* `if ($request->secure())` → the HSTS header (force HTTPS for a year) is only sent
  when already on HTTPS. Sending it over plain HTTP on a LAN could make the system
  unreachable.

---

# PART 2 — ROLES AND PERMISSIONS (the heart of your question)

Before the code: this system deliberately has **two separate ideas**.

* A **role** is a job title: Employee, Department Head, HR, Mayor, System Administrator.
* A **permission** is a single ability: `leave.apply`, `users.manage`, `audit.view`.

Nothing in the application ever asks "are you HR?" Everything asks "do you hold
permission X?" Roles exist only to *bundle* permissions. That is why the README
says "no hardcoded checks."

**The four places a person's abilities come from:**

1. Permissions attached to each of their roles — table `permission_role`
2. Permissions inherited from a role's **parent** role — column `roles.parent_id`
3. Permissions granted directly to that one person — table `permission_user`, type `allow`
4. Permissions **denied** to that one person — table `permission_user`, type `deny`
   (deny always wins)

---

## SECTION 10 — The Role model: where the five roles are named

**File:** `app/Models/Role.php`

**Code:**

```php
class Role extends Model
{
    use Auditable;

    /**
     * The five roles the LGU has, in the order they are offered.
     * ...
     */
    public const ASSIGNABLE = ['employee', 'department-head', 'hr', 'mayor', 'system-admin'];

    protected $fillable = ['name', 'slug', 'description', 'parent_id', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];
```

**Line-by-line explanation:**

* `class Role extends Model` → this class represents the `roles` database table.
  Laravel works the table name out from the class name automatically.
* `use Auditable;` → pulls in the audit trait (Section 24). Any create, update or
  delete of a role is automatically written to the audit log.
* **`public const ASSIGNABLE = [...]` → THIS IS THE ANSWER TO "where are the roles
  defined in code."** These five slugs are the complete list of roles an
  administrator may hand out. A "slug" is the short machine name;
  `department-head` is the slug, "Department Head" is the display name.
* `protected $fillable = [...]` → the only columns that may be filled from a
  submitted form. `parent_id` is here because roles inherit; `is_system` marks a
  role as built-in and undeletable.
* `protected $casts = ['is_system' => 'boolean']` → the database stores 1/0;
  this converts it to true/false in PHP so `if ($role->is_system)` reads naturally.

**Code (ordering the roles the way the organisation reads):**

```php
/** The roles an administrator may hand out, in the declared order. */
public function scopeAssignable($query)
{
    return $query->whereIn('slug', self::ASSIGNABLE)
        ->orderByRaw('CASE slug '.collect(self::ASSIGNABLE)
            ->map(fn ($slug, $i) => "WHEN '{$slug}' THEN {$i}")
            ->implode(' ').' ELSE 99 END');
}
```

**Line-by-line explanation:**

* `scopeAssignable` → a Laravel **scope**: a reusable query fragment. The `scope`
  prefix is dropped when you use it, so elsewhere you write
  `Role::assignable()->get()` (see `UserController::create()`).
* `whereIn('slug', self::ASSIGNABLE)` → only the five.
* `orderByRaw('CASE slug WHEN ... THEN ...')` → sorting alphabetically would list
  "Department Head, Employee, HR, Municipal Mayor, System Administrator", which is
  meaningless. This builds a small SQL `CASE` statement mapping each slug to its
  position in the array, so the dropdown reads the way the organisation does:
  employee → head of office → HR → Mayor → administrator.
* `->map(fn ($slug, $i) => "WHEN '{$slug}' THEN {$i}")` → an arrow function turning
  each slug and its index into one `WHEN` clause; `implode(' ')` glues them together.
* `ELSE 99` → anything unexpected sorts last instead of breaking the query.

**Code (the relationships):**

```php
public function permissions(): BelongsToMany
{
    return $this->belongsToMany(Permission::class);
}

public function users(): BelongsToMany
{
    return $this->belongsToMany(User::class);
}

public function parent(): BelongsTo
{
    return $this->belongsTo(self::class, 'parent_id');
}

public function children(): HasMany
{
    return $this->hasMany(self::class, 'parent_id');
}
```

**Line-by-line explanation:**

* `belongsToMany(Permission::class)` → **many-to-many**: a role has many
  permissions and a permission belongs to many roles. Laravel uses the in-between
  table `permission_role`.
* `belongsToMany(User::class)` → likewise `role_user`: a user can hold several
  roles and a role is held by several users.
* `parent()` / `children()` → a role can point at another role as its parent. This
  is **role inheritance**: `department-head`, `hr` and `mayor` all have `employee`
  as parent, so they automatically get everything Employee can do.
* `self::class` → "this same class," i.e. Role points at Role.

**Code (resolving inheritance):**

```php
/** Own permissions plus every ancestor's, following the parent chain. */
public function effectivePermissionSlugs(): array
{
    $slugs = $this->permissions()->pluck('slug')->all();
    $seen = [$this->id];
    $parent = $this->parent;
    while ($parent && ! in_array($parent->id, $seen, true)) {
        $seen[] = $parent->id;
        $slugs = array_merge($slugs, $parent->permissions()->pluck('slug')->all());
        $parent = $parent->parent;
    }

    return array_values(array_unique($slugs));
}
```

**Line-by-line explanation:**

* `$this->permissions()->pluck('slug')->all()` → start with this role's own
  permission slugs. `pluck` takes just that one column.
* `$seen = [$this->id];` → a list of roles already visited.
* `while ($parent && ! in_array($parent->id, $seen, true))` → climb the parent
  chain. The `$seen` check is a **loop guard**: if somebody accidentally made role A
  the parent of role B and B the parent of A, this stops instead of hanging forever.
* `array_merge(...)` → add each ancestor's permissions to the pile.
* `$parent = $parent->parent;` → move one step up.
* `array_unique(...)` → remove duplicates (a permission held by both a role and its
  parent). `array_values(...)` renumbers the list cleanly from 0.

**Function/Purpose:** Defines what a role *is*, which five exist, how they are
ordered, and how a child role absorbs its parent's abilities.

---

## SECTION 11 — The Permission model

**File:** `app/Models/Permission.php`

**Code:**

```php
class Permission extends Model
{
    use Auditable;
    protected $fillable = ['name', 'slug', 'module', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('type');
    }
}
```

**Line-by-line explanation:**

* `slug` → the machine name the code checks, e.g. `leave.approve.final`.
* `module` → a grouping label (`leave`, `security`, `users`) used to organise the
  checkboxes on the Roles and Access pages.
* `roles()` → which roles include this permission.
* `users()->withPivot('type')` → the direct, per-person overrides. **`withPivot('type')`
  is the key detail**: the in-between table `permission_user` carries an extra
  column `type`, holding either `allow` or `deny`. Without `withPivot` that column
  would be invisible to PHP.

---

## SECTION 12 — RbacService: the engine that answers "may they?"

**File:** `app/Services/Rbac/RbacService.php`

**Code (the setup):**

```php
class RbacService
{
    private const VERSION_KEY = 'rbac.version';
    private const TTL = 300;

    /** Wildcard permission slug held by Super Admin (seeded, still a DB record). */
    public const WILDCARD = '*';
```

**Line-by-line explanation:**

* `VERSION_KEY` → a counter kept in the cache. Every time roles or permissions
  change, this number goes up. Because it forms part of every cache key, bumping it
  instantly invalidates **all** cached answers at once. This is much cheaper than
  hunting down every affected user's cache entry.
* `TTL = 300` → "time to live": cached answers last 300 seconds (5 minutes).
* `WILDCARD = '*'` → a permission slug that satisfies *every* check. The seeder
  comment notes that **no role holds it any more**, and `RoleController` refuses to
  assign it — but the code that understands it remains, so an old installation that
  still has it keeps working.

**Code (the main resolution):**

```php
public function effectivePermissions(User $user): array
{
    $key = sprintf('rbac.user.%d.v%d', $user->id, $this->version());

    return Cache::remember($key, self::TTL, function () use ($user) {
        $roleIds = DB::table('role_user')->where('user_id', $user->id)->pluck('role_id')->all();
        $map = $this->rolePermissionMap();

        $slugs = [];
        foreach ($roleIds as $roleId) {
            $slugs = array_merge($slugs, $map[$roleId]['permissions'] ?? []);
        }

        $direct = DB::table('permission_user')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('permission_user.user_id', $user->id)
            ->get(['permissions.slug', 'permission_user.type']);

        foreach ($direct as $grant) {
            if ($grant->type === 'allow') {
                $slugs[] = $grant->slug;
            }
        }
        $slugs = array_values(array_unique($slugs));

        // Deny overrides any allow, including role-derived ones.
        $denied = $direct->where('type', 'deny')->pluck('slug')->all();

        return array_values(array_diff($slugs, $denied));
    });
}
```

**Line-by-line explanation:**

* `$key = sprintf('rbac.user.%d.v%d', $user->id, $this->version());` → builds a
  cache key like `rbac.user.14.v7`. `%d` inserts a number. When `version()` becomes
  8, every `v7` key is orphaned and every answer is recomputed — that is the
  "changes take effect immediately" behaviour promised in the README.
* `Cache::remember($key, self::TTL, function () { ... })` → run the expensive block
  only if the answer is not already cached.
* `DB::table('role_user')->where('user_id', ...)->pluck('role_id')` → **step 1**:
  which roles does this person hold? This uses the query builder directly rather
  than Eloquent models, because only the IDs are needed — it is faster.
* `$map = $this->rolePermissionMap();` → **step 2**: fetch the role → permissions
  lookup table (with inheritance already resolved), itself cached.
* `foreach ($roleIds as $roleId) { $slugs = array_merge($slugs, $map[$roleId]['permissions'] ?? []); }`
  → **step 3**: pile up the permissions of every role they hold. `?? []` means "or
  an empty list if that role is missing," which avoids a crash if a role row was
  deleted while an assignment lingered.
* The `permission_user` query → **step 4**: this person's individual overrides.
  `join('permissions', ...)` pulls the human-readable slug alongside the `type`.
* `if ($grant->type === 'allow') { $slugs[] = $grant->slug; }` → add the individual
  grants.
* `array_unique` then `array_values` → deduplicate and renumber.
* `$denied = $direct->where('type', 'deny')->pluck('slug')->all();` → collect the
  individual denials.
* `array_diff($slugs, $denied)` → **remove every denied slug from the final list.**
  This is the rule that *deny beats allow*, including permissions that came from a
  role. It is how an administrator can say "HR, but this one officer may not adjust
  balances" without inventing a sixth role.

**Code (the two questions the rest of the app asks):**

```php
public function userHasPermission(User $user, string $slug): bool
{
    $permissions = $this->effectivePermissions($user);

    return in_array(self::WILDCARD, $permissions, true)
        || in_array($slug, $permissions, true);
}

/** @param array<string> $slugs */
public function userHasRole(User $user, array $slugs): bool
{
    return $this->userRoleSlugs($user)->intersect($slugs)->isNotEmpty();
}
```

**Line-by-line explanation:**

* `userHasPermission` → the workhorse. True if the person holds the wildcard, or
  holds this exact slug. The third argument `true` to `in_array` means "strict
  comparison," so no surprising type juggling.
* `userHasRole` → still exists for display purposes (e.g. showing "HR" on a profile),
  but note that **no authorisation decision in the application uses it**.
* `intersect($slugs)->isNotEmpty()` → "do the roles they hold overlap with the roles
  being asked about?"

**Code (the inheritance map):**

```php
public function rolePermissionMap(): array
{
    $key = 'rbac.map.v'.$this->version();

    return Cache::remember($key, self::TTL, function () {
        $roles = Role::query()->get(['id', 'slug', 'parent_id'])->keyBy('id');
        $rolePerms = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->get(['permission_role.role_id', 'permissions.slug'])
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('slug')->all());

        $map = [];
        foreach ($roles as $role) {
            $slugs = $rolePerms[$role->id] ?? [];
            $seen = [$role->id];
            $parentId = $role->parent_id;
            while ($parentId && isset($roles[$parentId]) && ! in_array($parentId, $seen, true)) {
                $seen[] = $parentId;
                $slugs = array_merge($slugs, $rolePerms[$parentId] ?? []);
                $parentId = $roles[$parentId]->parent_id;
            }
            $map[$role->id] = [
                'slug' => $role->slug,
                'permissions' => array_values(array_unique($slugs)),
            ];
        }

        return $map;
    });
}
```

**Line-by-line explanation:**

* This does the same climb as `Role::effectivePermissionSlugs()`, but for **all
  roles at once, in two queries**, instead of one query per ancestor per user. That
  is why it exists separately.
* `->keyBy('id')` → re-index the roles by their ID so `$roles[5]` fetches role 5
  directly.
* `->groupBy('role_id')->map(fn ($rows) => $rows->pluck('slug')->all())` → turns a
  flat list of (role, permission) pairs into `roleId => [slug, slug, ...]`.
* The `while` loop → the same guarded parent climb, with `isset($roles[$parentId])`
  added in case a parent row no longer exists.
* The result is `roleId => ['slug' => ..., 'permissions' => [...]]`, cached under
  one key for everybody.

**Code (cache invalidation and the write helpers):**

```php
/** Call after any role/permission/assignment mutation. */
public function bumpVersion(): void
{
    Cache::forever(self::VERSION_KEY, $this->version() + 1);
}

public function syncUserRoles(User $user, array $roleIds): void
{
    $user->roles()->sync($roleIds);
    $this->bumpVersion();
}

public function syncRolePermissions(Role $role, array $permissionIds): void
{
    $role->permissions()->sync($permissionIds);
    $this->bumpVersion();
}

public function grantUserPermission(User $user, Permission $permission, string $type = 'allow'): void
{
    $user->directPermissions()->syncWithoutDetaching([$permission->id => ['type' => $type]]);
    $this->bumpVersion();
}

private function version(): int
{
    return (int) Cache::get(self::VERSION_KEY, 1);
}
```

**Line-by-line explanation:**

* `bumpVersion()` → the single lever that makes every cached permission answer stale.
  `Cache::forever` stores it without expiry (it must survive longer than the answers
  it invalidates).
* `sync($roleIds)` → makes the person's roles **exactly** this list: adds the new
  ones, removes any not listed. Every `sync` is paired with a `bumpVersion()`, which
  is the discipline that keeps the cache honest.
* `syncWithoutDetaching([...])` → adds without removing what is already there; used
  for individual grants so one grant does not wipe another.
* `[$permission->id => ['type' => $type]]` → the array key is the permission ID, the
  value is the extra pivot column. That is how `allow` / `deny` is stored.
* `version()` → defaults to 1 if the cache was cleared, which simply means every
  answer is recomputed once.

**Function/Purpose:** This class is the single source of truth for "what may this
person do." Everything else — middleware, menus, dashboards, workflow — just asks it.

**Role/Permission involved:** All of them. This is the machinery beneath every role.

---

## SECTION 13 — PermissionMiddleware: the gate on every route

**File:** `app/Http/Middleware/PermissionMiddleware.php`

**Code:**

```php
/**
 * Dynamic RBAC gate: `->middleware('permission:users.manage')`.
 * Slugs resolve against the database via RbacService (never hardcoded).
 * Unauthorized hits are recorded as privilege-escalation probes.
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        IntrusionLog::create([
            'category' => 'privilege',
            'severity' => 'medium',
            'route' => $request->path(),
            'method' => $request->method(),
            'payload_excerpt' => 'Denied permission(s): '.implode(',', $permissions),
            'matched_rule' => 'rbac_denied',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'user_id' => $user->id,
        ]);

        abort(403);
    }
}
```

**Line-by-line explanation:**

* `string ...$permissions` → the `...` is a **variadic** parameter: it collects any
  number of extra arguments into an array. This is what lets a route say
  `permission:employees.view,leave.review.department` and have both slugs arrive here.
* `if (! $user) return redirect()->guest(route('login'));` → nobody signed in.
  `redirect()->guest()` remembers the page they wanted so they land back on it after
  logging in.
* `foreach (...) { if ($user->hasPermission($permission)) return $next($request); }`
  → **any one** of the listed permissions is enough. The first match lets them
  through immediately. This "OR" behaviour is used by the Leave Rankings page, which
  HR reaches via `employees.view` and a department head via `leave.review.department`.
* `$user->hasPermission(...)` → calls straight through to `RbacService`. No role
  name appears anywhere in this file, which is exactly the point.
* `IntrusionLog::create([... 'category' => 'privilege' ...])` → **a denied request
  is treated as a security event**, not just an error page. Somebody typing
  `/settings` when they are an Employee is a privilege-escalation probe, and it is
  logged with the route, the method, the IP and their user ID.
* `abort(403)` → stop everything and show the Forbidden page.

**Function/Purpose:** The one gate that enforces every role boundary in the system.

**Role/Permission involved:** Every role. This is where `employee` is refused
`/users` and `hr` is refused `/settings`.

---

## SECTION 14 — The User model's RBAC shortcuts

**File:** `app/Models/User.php`

**Code:**

```php
class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'name', 'username', 'email', 'password', 'status',
        'blocked_until', 'blocked_reason', 'failed_attempts',
        'must_change_password', 'password_changed_at',
        'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'remember_token'];
```

**Line-by-line explanation:**

* `extends Authenticatable` → the Laravel base class for anything that can log in.
* The five traits, in plain terms:
  * `Auditable` — every change to a user row lands in the audit log.
  * `HasApiTokens` — Sanctum API tokens, for `/api/v1`.
  * `HasFactory` — lets the test suite fabricate users.
  * `Notifiable` — the user can receive notifications (`$user->notify(...)`).
  * `SoftDeletes` — **deleting a user does not remove the row**; it sets
    `deleted_at`. This is what makes "archive, never destroy" possible.
* The three `STATUS_` constants → using `User::STATUS_BLOCKED` instead of the
  string `'blocked'` means a typo becomes an error instead of a silent bug.
* `$hidden` → `password` and `remember_token` are stripped whenever the model is
  turned into JSON, so they can never leak through the API.

**Code (the casts):**

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'blocked_until' => 'datetime',
        'password_changed_at' => 'datetime',
        'last_login_at' => 'datetime',
        'must_change_password' => 'boolean',
        'password' => 'hashed',
    ];
}
```

**Line-by-line explanation:**

* `'datetime'` → these columns come back as date objects, which is why
  `$user->blocked_until->isFuture()` works elsewhere.
* `'boolean'` → 1/0 becomes true/false.
* **`'password' => 'hashed'`** → the important one. Any time code assigns
  `$user->password = 'something'`, Laravel hashes it automatically. It is
  impossible to accidentally store a plain-text password on this model.

**Code (the RBAC helpers):**

```php
// ---- RBAC ------------------------------------------------------------

public function hasRole(string ...$slugs): bool
{
    return app(RbacService::class)->userHasRole($this, $slugs);
}

public function hasPermission(string $slug): bool
{
    return app(RbacService::class)->userHasPermission($this, $slug);
}

public function permissionSlugs(): array
{
    return app(RbacService::class)->effectivePermissions($this);
}
```

**Line-by-line explanation:**

* `app(RbacService::class)` → fetches the shared `RbacService` instance from
  Laravel's service container. This is why the caching works across the whole
  request: everyone uses the same object.
* `hasPermission($slug)` → **this is the method the whole application calls**:
  middleware, the sidebar, the dashboard, the approval workflow. It is a one-line
  pass-through, which is deliberate — all the logic lives in one place.
* `hasRole(...)` → available, but used for display only.

**Code (state helpers and relations):**

```php
public function isBlocked(): bool
{
    return $this->status === self::STATUS_BLOCKED
        && ($this->blocked_until === null || $this->blocked_until->isFuture());
}

public function department(): ?Department
{
    return $this->employeeProfile?->department;
}

public function headsDepartments(): HasMany
{
    return $this->hasMany(Department::class, 'head_user_id');
}
```

**Line-by-line explanation:**

* `isBlocked()` → blocked *and* either indefinitely (`blocked_until === null`, a
  manual block) or still inside the 24-hour window. A past expiry means not blocked.
* `department()` → `?Department` means "a Department or null". The `?->` walks
  safely through a user who has no employee profile yet.
* **`headsDepartments()` → this is how "which office does this Department Head
  actually head?" is answered.** It looks at `departments.head_user_id`, not at the
  role. So holding the Department Head *role* gives you the ability to review a
  department; being named on a department row decides **which** department. That
  separation is what stops one head reviewing another office's leave — see
  `ApprovalWorkflowService::canRecommend()` in Section 21.

---

## SECTION 15 — THE ROLE DEFINITIONS: RolePermissionSeeder

**File:** `database/seeders/RolePermissionSeeder.php`

This is the single most important file for your question. It creates every
permission, creates the five roles, and decides exactly what each role may do.

### 15a. The permission catalogue

**Code:**

```php
private array $permissions = [
    '*' => ['Full system access (wildcard)', 'system'],
    'dashboard.view' => ['View own dashboard', 'dashboard'],

    'users.manage' => ['Create/update/archive/restore/delete users', 'users'],
    'users.block' => ['Manually block & unblock accounts', 'users'],
    'users.reset-password' => ['Reset user passwords', 'users'],
    'users.assign-roles' => ['Assign roles and permissions to users', 'users'],
    'users.history' => ['View login and audit history of users', 'users'],
    'rbac.manage' => ['Manage roles and permissions', 'rbac'],
    'settings.manage' => ['Manage system settings', 'settings'],

    'devices.manage' => ['Manage authorized devices', 'devices'],
    'security.dashboard' => ['View security dashboard & alerts', 'security'],
    'security.blocked-ips' => ['Manage blocked IP addresses', 'security'],
    'security.intrusions' => ['View intrusion logs', 'security'],
    'audit.view' => ['View audit logs', 'audit'],
    'activity.view' => ['View activity logs', 'audit'],
    'audit.view-own' => ['View own audit trail', 'audit'],
    'backup.run' => ['Create and download system backups', 'settings'],

    'employees.view' => ['View employee records', 'employees'],
    'employees.manage' => ['Create/update/archive employees', 'employees'],
    'employees.view-salary' => ['See employee salary fields', 'employees'],
    'departments.manage' => ['Manage departments', 'organization'],
    'positions.manage' => ['Manage positions', 'organization'],
    'holidays.manage' => ['Maintain the holiday calendar', 'organization'],

    'leave.apply' => ['File leave applications', 'leave'],
    'leave.view-own' => ['View own leave requests, balances, history', 'leave'],
    'leave.cancel' => ['Cancel own pending leave requests', 'leave'],
    'leave.review.department' => ['View leave applications in own department', 'leave'],
    'leave.certify.hr' => ['Validate & certify leave credits (HR step)', 'leave'],
    'leave.approve.final' => ['Approve or disapprove leave applications (HR)', 'leave'],
    'leave.requests.view-all' => ['View all leave requests', 'leave'],
    'leave.balances.manage' => ['Adjust leave balances', 'leave'],
    'leave-types.manage' => ['Configure leave types & policies', 'leave'],

    'reports.generate' => ['Generate & export operational reports', 'reports'],
    'reports.security' => ['Generate & export security reports', 'reports'],
    'reports.department' => ['Generate & export reports for own department', 'reports'],
];
```

**Line-by-line explanation:**

* The format is `'slug' => ['human name', 'module']`. The slug is what the code
  checks; the module groups the checkboxes on the admin screens.
* `'*'` → the wildcard. It still exists as a row so old installations work, but the
  comment at the bottom of the file says no role holds it any more.
* **`audit.view` vs `audit.view-own`** → the file comments explain these are
  deliberately separate, not strong-and-weak versions of the same thing: one is the
  *whole* log, the other is *the holder's own rows*. A role gaining the first should
  not silently gain the second's scope, or the reverse.
* **`leave.review.department`** → the comment is candid: the slug says "review"
  because it is baked into route guards and existing databases, but all it now
  grants is **visibility** of one's own department's leave. No authority.
* **`leave.approve.final`** → the one permission that can decide an application.
* **`leave.requests.view-all`** → read every application in the LGU, without the
  power to decide any (this is what the Mayor holds).
* `reports.generate` vs `reports.security` vs `reports.department` → three separate
  report rights so that running leave reports does not also open security reports.

### 15b. The Employee baseline

**Code:**

```php
/**
 * What every person on the payroll can do with their own leave.
 *
 * Held by Employee, Department Head, HR and the Mayor. Not by the System
 * Administrator, whose account operates the system rather than working in
 * it -- their dashboard is the security one and carries no leave figures.
 */
private const EMPLOYEE_BASELINE = [
    'dashboard.view', 'leave.apply', 'leave.view-own', 'leave.cancel',
    // Everyone the system audits can read their own trail...
    'audit.view-own',
];
```

**Line-by-line explanation:**

* Five abilities that everybody on the payroll needs: see a dashboard, file leave,
  view their own leave, cancel their own pending leave, read their own audit trail.
* The comment explains the **System Administrator is deliberately excluded** — that
  account runs the system rather than working in the LGU.
* `audit.view-own` is in the baseline rather than granted per role so that a role
  added later cannot end up audited but unable to see its own trail.

### 15c. Creating the five roles

**Code:**

```php
public function run(): void
{
    foreach ($this->permissions as $slug => [$name, $module]) {
        Permission::updateOrCreate(['slug' => $slug], [
            'name' => $name, 'module' => $module, 'description' => $name,
        ]);
    }

    $employee = Role::updateOrCreate(['slug' => 'employee'], [
        'name' => 'Employee', 'is_system' => true,
        'description' => 'Regular LGU employee: files and tracks own leave.',
    ]);

    // Department Head inherits everything Employee can do (role inheritance).
    $deptHead = Role::updateOrCreate(['slug' => 'department-head'], [
        'name' => 'Department Head', 'is_system' => true, 'parent_id' => $employee->id,
        'description' => 'Sees leave filed in own department; approves none of it.',
    ]);

    $hr = Role::updateOrCreate(['slug' => 'hr'], [
        'name' => 'HR', 'is_system' => true, 'parent_id' => $employee->id,
        'description' => 'Human Resources: employees, balances, certification, reports.',
    ]);

    $mayor = Role::updateOrCreate(['slug' => 'mayor'], [
        'name' => 'Municipal Mayor', 'is_system' => true, 'parent_id' => $employee->id,
        'description' => 'Oversees leave across the LGU; signs the printed form as head of agency.',
    ]);

    $sysAdmin = Role::updateOrCreate(['slug' => 'system-admin'], [
        'name' => 'System Administrator', 'is_system' => true,
        'description' => 'Operates users, devices, security monitoring and settings.',
    ]);
```

**Line-by-line explanation:**

* `foreach ($this->permissions as $slug => [$name, $module])` → the square brackets
  unpack each two-item array into `$name` and `$module` in one step.
* `Permission::updateOrCreate(['slug' => $slug], [...])` → "find the row with this
  slug and update it, or create it if absent." This makes the seeder **safe to run
  again** — re-running it never produces duplicates.
* `Role::updateOrCreate(['slug' => 'employee'], [...])` → same idea for roles.
* `'is_system' => true` → marks all five as built-in. `RoleController::destroy()`
  refuses to delete any role with this flag, so the five cannot be removed.
* **`'parent_id' => $employee->id`** on Department Head, HR and Mayor → this is the
  inheritance wiring. All three are employees first and hold a duty second.
* **System Administrator has NO `parent_id`** → it does not inherit the employee
  baseline, because that account does not file leave.

### 15d. Granting the permissions — the actual role definitions

**Code:**

```php
    $grant = function (Role $role, array $slugs): void {
        $ids = Permission::whereIn('slug', $slugs)->pluck('id');
        $role->permissions()->sync($ids);
    };

    $grant($employee, self::EMPLOYEE_BASELINE);
```

**Line-by-line explanation:**

* `$grant = function (Role $role, array $slugs) { ... }` → a small helper stored in
  a variable ("closure"), so the five grants below read as one line each.
* `Permission::whereIn('slug', $slugs)->pluck('id')` → turn the list of slugs into
  the list of database IDs.
* `$role->permissions()->sync($ids)` → make the role hold **exactly** those
  permissions — adding what is missing and removing anything extra.
* `$grant($employee, self::EMPLOYEE_BASELINE)` → **Employee = the five baseline
  abilities and nothing else.**

**Code (Department Head):**

```php
    // Department Head SEES its own office's leave and acts on none of it.
    // ...
    // Deliberately NOT `leave.approve.final`, and no longer any authority
    // at all. The office is read off the department record rather than
    // chosen, so this is not `leave.requests.view-all` in a smaller coat:
    // it cannot reach another office's applications.
    $grant($deptHead, [
        ...self::EMPLOYEE_BASELINE,
        'leave.review.department', 'reports.generate', 'reports.department',
    ]);
```

**Line-by-line explanation:**

* `...self::EMPLOYEE_BASELINE` → the `...` **spread operator** copies every item of
  the baseline array into this new array, then adds three more.
* `leave.review.department` → see (not decide) their own office's leave.
* `reports.generate` + `reports.department` → run the three department reports.
* The comment records that the head has **no** deciding authority. They are
  *notified* when their staff file, and may write a recommendation on box 7.B of the
  paper form — but HR decides.

**Code (HR):**

```php
    $grant($hr, [
        ...self::EMPLOYEE_BASELINE,
        'employees.view', 'employees.manage', 'employees.view-salary',
        'departments.manage', 'positions.manage', 'holidays.manage',
        'leave.requests.view-all', 'leave.balances.manage', 'leave-types.manage',
        'leave.certify.hr', 'leave.approve.final', 'reports.generate',
    ]);
```

**Line-by-line explanation:**

* HR is the operational core: employee records (including salaries, which appear on
  CSC Form 6), the organisational lists (departments, positions, holidays), the
  leave configuration (types and balances), and crucially
  **`leave.approve.final` — HR, and only HR, decides leave.**
* `leave.certify.hr` → certify the credit balances printed on the form.
* `reports.generate` → the six leave reports. HR does **not** get
  `reports.security`, so the four security reports stay closed to them.

**Code (Mayor):**

```php
    // The Mayor OVERSEES leave; HR decides it. No `leave.approve.final`,
    // so the Leave Approvals queue is not merely hidden from the Mayor —
    // the route guard and ApprovalWorkflowService::canDecide() both refuse
    // them, which is the only version of this that means anything.
    //
    // What stays is sight of it: every application, and the reports behind
    // them...
    //
    // The Mayor's signature has not left the process. It is at the foot of
    // the printed CSC Form No. 6, as head of agency...
    $grant($mayor, [
        ...self::EMPLOYEE_BASELINE,
        'leave.requests.view-all', 'reports.generate',
    ]);
```

**Line-by-line explanation:**

* The Mayor holds the employee baseline (they file their own leave like anyone),
  plus exactly two things: **read every application** and **run leave reports**.
* The comment is precise about why this is real security rather than a hidden menu:
  the route `review.index` carries `permission:leave.approve.final`, and
  `ApprovalWorkflowService::act()` checks the same permission again. A Mayor who
  typed the URL directly would be refused twice.

**Code (System Administrator):**

```php
    $grant($sysAdmin, [
        'dashboard.view', 'users.manage', 'users.block', 'users.reset-password',
        'users.assign-roles', 'users.history', 'rbac.manage', 'settings.manage',
        'devices.manage', 'security.dashboard', 'security.blocked-ips',
        'security.intrusions', 'audit.view', 'activity.view', 'backup.run',
        'reports.generate', 'reports.security',
    ]);

    // No role holds `*`. Super Admin did, and the System Administrator
    // already covers what an administrator does here — so there is now no
    // permission anywhere that satisfies every check, and none that this
    // installation can grant by accident. RoleController refuses it too.

    DB::table('cache')->where('key', 'like', '%rbac%')->delete();
}
```

**Line-by-line explanation:**

* Note what is **absent**: no `leave.*` permission at all. The System Administrator
  cannot see anyone's leave records. This is a real separation of duties — the
  person who runs the servers cannot read the Mayor's sick leave.
* `reports.generate` + `reports.security` → they get the four security reports.
  Since `ReportService` also checks each report's *subject* permission, and they
  hold no `leave.requests.view-all`, the leave reports stay closed to them.
* `DB::table('cache')->where('key', 'like', '%rbac%')->delete();` → the last line
  wipes every cached RBAC answer, so a freshly seeded database takes effect at once
  rather than after the 5-minute cache expires.

**Function/Purpose:** This file *is* the role definition. Change a line here and
re-seed, and the system's entire authority structure changes — with no code edits
anywhere else.

---

## SECTION 16 — Where each role is actually STOPPED: the route files

The routes are where permissions become URLs. Three files matter.

### 16a. The outer shell — `routes/web.php`

**Code:**

```php
// ---- Guest / authentication ------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    ...
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---- OTP second factor (authenticated, pre-OTP) ----------------------------
Route::middleware('auth')->group(function () {
    Route::get('/otp', [OtpController::class, 'show'])->name('otp.show');
    Route::post('/otp/verify', [OtpController::class, 'verify'])->name('otp.verify');
    Route::post('/otp/resend', [OtpController::class, 'resend'])->name('otp.resend');
});

// ---- Fully authenticated application ---------------------------------------
Route::middleware(['auth', 'otp.verified', 'force.pwchange'])->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/change-password', [PasswordChangeController::class, 'edit'])->name('password.change');
    Route::post('/change-password', [PasswordChangeController::class, 'update'])->name('password.change.update');

    require __DIR__.'/leave.php';
    require __DIR__.'/admin.php';
});
```

**Line-by-line explanation:**

* `Route::middleware('guest')->group(...)` → **`guest` is the opposite of `auth`**:
  these pages are only for people who are *not* signed in. Visiting `/login` while
  already signed in redirects away.
* `Route::get(...)` vs `Route::post(...)` → GET shows a form, POST submits it. The
  login page is two routes for the same URL, by method.
* `->name('login')` → gives the route a nickname so code can write
  `route('login')` instead of hardcoding `/login`. Change the URL later and every
  link still works.
* `Route::post('/logout', ...)->middleware('auth')` → logout is POST, not GET, so a
  malicious `<img src="/logout">` on another page cannot sign people out.
* The OTP group requires `auth` but **not** `otp.verified` — otherwise you could
  never reach the page where you verify.
* **The last group is the whole application**, and it demands three things at once:
  signed in, OTP verified, password changed.
* `require __DIR__.'/leave.php';` → literally pastes the other route files in here,
  so *every* leave and admin route inherits those three guards. This is why no
  individual route needs to repeat them.

### 16b. Leave routes — `routes/leave.php`

**Code (who may file, view and cancel):**

```php
Route::middleware('permission:leave.apply')->group(function () {
    Route::get('leave/apply', [LeaveRequestController::class, 'create'])->name('leave.create');
    Route::post('leave', [LeaveRequestController::class, 'store'])->name('leave.store');
    Route::post('leave/preview', [LeaveRequestController::class, 'preview'])->name('leave.preview');
});
Route::middleware('permission:leave.view-own')->group(function () {
    Route::get('leave', [LeaveRequestController::class, 'index'])->name('leave.index');
    Route::get('leave/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('leave.show');
    Route::get('leave/{leaveRequest}/form6', [LeaveRequestController::class, 'form6'])->name('leave.form6');
    ...
});
Route::middleware('permission:leave.cancel')->group(function () {
    Route::post('leave/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave.cancel');
});
```

**Line-by-line explanation:**

* `permission:leave.apply` → the `permission` alias from `bootstrap/app.php`; the
  part after the colon is the slug handed to `PermissionMiddleware`.
* `leave/{leaveRequest}` → the curly braces are a **route parameter**. Laravel reads
  the ID from the URL, fetches that `LeaveRequest` row, and hands the whole object
  to the controller ("route model binding").
* **Important:** holding `leave.view-own` gets you *to* this route, but it does not
  prove the application is yours. The URL contains an ID anybody could change. That
  is why the controller calls `authorizeView()` (Section 18) on every one of these.
* **Who holds these?** All four payroll roles, via `EMPLOYEE_BASELINE`.

**Code (the department head's recommendation):**

```php
// The head of an office recommends on box 7.B of their own people's forms.
//
// Gated on the permission here AND scoped to the office they actually head
// inside ApprovalWorkflowService::canRecommend(): the permission says "may
// review a department", and which one comes from who heads it, never from the
// request. This is a recommendation, not a decision -- HR still decides.
Route::middleware('permission:leave.review.department')->group(function () {
    Route::post('leave/{leaveRequest}/recommend', [ApprovalController::class, 'recommend'])
        ->name('leave.recommend');
});
```

**Line-by-line explanation:**

* One route, one permission — held only by **Department Head**.
* The comment states the two-layer rule plainly: the **permission** says you may
  review *a* department; the **department record** says *which*. Both are checked.

**Code (the Mayor's oversight, and HR's queue):**

```php
Route::middleware('permission:leave.requests.view-all')->group(function () {
    Route::get('all-leave', [LeaveRequestController::class, 'all'])->name('leave.all');
});

// The approval queue. HR holds `leave.approve.final` and nobody else does, so
// this is HR's page — the Mayor oversees leave through All Leave Requests and
// a Department Head reads their own office's on their dashboard.
//
// The guard is on the route, not only on the menu entry: a menu is what a
// person is offered, and this route takes a request id that anybody could type.
Route::middleware('permission:leave.approve.final')->group(function () {
    Route::get('review', [ApprovalController::class, 'queue'])->name('review.index');
    Route::get('blank-leave-form', [LeaveRequestController::class, 'blankForm6'])->name('leave.form6-blank');
    Route::post('review/{leaveRequest}/act', [ApprovalController::class, 'act'])->name('review.act');
});
```

**Line-by-line explanation:**

* `all-leave` (HR + Mayor) is *reading*; `review` (HR only) is *deciding*. Two
  different permissions, two different pages.
* `blank-leave-form` → a blank printable CSC Form 6, deliberately placed behind
  HR's permission because it is the paper handed across a counter, not something an
  employee filing online should be offered.
* The comment about "its own path segment, not `leave/blank-form`" → had it been
  under `leave/`, the router would have matched it against `leave/{leaveRequest}`
  and tried to look up an application with the ID "blank-form".

**Code (HR's management modules, and the shared rankings page):**

```php
Route::middleware('permission:employees.view')->group(function () {
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/{user}', [EmployeeController::class, 'show'])->name('employees.show');
});
Route::middleware('permission:departments.manage')->group(function () {
    Route::resource('departments', DepartmentController::class)->except('show');
});
Route::middleware('permission:leave.balances.manage')->group(function () {
    Route::get('balances', [BalanceController::class, 'index'])->name('balances.index');
    Route::post('balances/{user}/adjust', [BalanceController::class, 'adjust'])->name('balances.adjust');
});
// Who has used the most of each leave type. Gated on either permission: HR
// reads the whole LGU, a department head only the office they head — the scope
// is taken from the department record inside the controller, never from the
// request.
Route::get('rankings', [RankingController::class, 'index'])
    ->middleware('permission:employees.view,leave.review.department')
    ->name('rankings.index');
```

**Line-by-line explanation:**

* `Route::resource('departments', DepartmentController::class)->except('show')` →
  one line that creates the whole set of CRUD routes (index, create, store, edit,
  update, destroy). `except('show')` drops the single-record view, which this
  module does not need.
* **`permission:employees.view,leave.review.department`** → the comma is the
  variadic case from Section 13. HR passes on the first slug, a Department Head on
  the second, and **the controller then narrows what each actually sees.**

### 16c. Admin routes — `routes/admin.php`

**Code:**

```php
// Notifications (any authenticated user)
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

// Roles & permissions.
// The five roles are fixed by the LGU's structure, so there is no `create` and
// no `store` — a sixth invented from a form would hold authority nothing in the
// organisation answers for. `destroy` stays and stays refusing...
Route::middleware('permission:rbac.manage')->group(function () {
    Route::resource('roles', RoleController::class)->only(['index', 'edit', 'update', 'destroy']);
});

// Users
Route::middleware('permission:users.manage')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/access', [UserController::class, 'access'])->name('users.access');
    Route::post('users/{user}/access', [UserController::class, 'updateAccess'])->name('users.access.update');
    Route::post('users/{user}/block', [UserController::class, 'block'])->name('users.block');
    Route::post('users/{user}/archive', [UserController::class, 'archive'])->name('users.archive');
    Route::post('users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    // No permanent delete. An account is archived, never destroyed: a
    // forceDelete cascaded through every leave application the person ever
    // filed -- approved ones included, each backed by a signed CSC Form 6 --
    // and nulled their name out of the audit, activity and intrusion logs...
});
```

**Line-by-line explanation:**

* The notifications routes carry **no permission** — they are inside the
  authenticated group, so anyone signed in may read their own bell.
* `Route::resource('roles', ...)->only([...])` → **no `create`, no `store`.** There
  is deliberately no way to invent a sixth role. `destroy` is kept only because a
  replayed form would hit that URL, and the controller refuses it.
* Every user-management route sits behind the single permission `users.manage`,
  held only by the **System Administrator**.
* **There is no delete route.** The comment explains why: a hard delete would
  cascade through every leave application the person ever filed, including approved
  ones backed by a signed form, and would blank their name out of the audit logs.

**Code (the personal audit trail, and backups):**

```php
Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
Route::get('activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:activity.view')->name('activity.index');

// A person's OWN audit trail: employee, HR, department head and Mayor alike.
// Separate permission and separate controller from the whole log above -- the
// scope comes from the session, and there is no id anywhere in this route for
// anyone to change.
Route::get('my-audit-log', [MyAuditController::class, 'index'])->middleware('permission:audit.view-own')->name('audit.mine');

Route::middleware('permission:backup.run')->group(function () {
    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('backups', [BackupController::class, 'store'])->name('backups.store');
    Route::get('backups/{file}', [BackupController::class, 'download'])
        ->where('file', 'lms_(partial_)?[0-9]{8}_[0-9]{6}\\.zip')
        ->name('backups.download');
});
```

**Line-by-line explanation:**

* `audit.view` (whole log, System Administrator) and `audit.view-own` (your own
  rows, everyone on the payroll) are two different routes, two different
  controllers, two different permissions.
* **"there is no id anywhere in this route"** → `my-audit-log` takes no parameter at
  all. The controller reads the signed-in user from the session. There is literally
  nothing in the URL for an attacker to change.
* `->where('file', 'lms_(partial_)?[0-9]{8}_[0-9]{6}\.zip')` → a **route
  constraint**: the `{file}` part of the URL must match this pattern (the letters
  `lms_`, an optional `partial_`, 8 digits, an underscore, 6 digits, `.zip`).
  Anything else — such as `../../.env` — never even reaches PHP. This is
  defence in depth against path traversal on the download route.

---

## SECTION 17 — The permission-driven menu

Each role sees a different sidebar, and no role name appears anywhere in it.

**File:** `config/menu.php`

**Code:**

```php
return [
    [
        'label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => 'dashboard',
        'permission' => 'dashboard.view',
        'requires_any' => ['leave.view-own', 'leave.requests.view-all'],
    ],

    ['label' => 'Security Dashboard', 'icon' => 'bi-shield-exclamation', 'route' => 'security.dashboard', 'permission' => 'security.dashboard'],

    ['heading' => 'Leave'],
    ['label' => 'Apply for Leave', 'icon' => 'bi-calendar-plus', 'route' => 'leave.create', 'permission' => 'leave.apply'],
    ['label' => 'My Leave Requests', 'icon' => 'bi-card-checklist', 'route' => 'leave.index', 'permission' => 'leave.view-own'],
    ['label' => 'My Signature', 'icon' => 'bi-pen', 'route' => 'signature.edit', 'permission' => 'leave.view-own'],
    ['label' => 'My Audit Log', 'icon' => 'bi-journal-check', 'route' => 'audit.mine', 'permission' => 'audit.view-own'],

    ['heading' => 'HR Management'],
    ['label' => 'Leave Approvals', 'icon' => 'bi-clipboard-check', 'route' => 'review.index',
        'permission' => 'leave.approve.final'],
    ['label' => 'All Leave Requests', 'icon' => 'bi-collection', 'route' => 'leave.all', 'permission' => 'leave.requests.view-all'],
    ['label' => 'Employees', 'icon' => 'bi-person-badge', 'route' => 'employees.index', 'permission' => 'employees.view'],
    ...
    ['label' => 'Leave Rankings', 'icon' => 'bi-bar-chart-steps', 'route' => 'rankings.index',
        'permission' => ['employees.view', 'leave.review.department']],
    ...

    ['heading' => 'Administration'],
    ['label' => 'Users', 'icon' => 'bi-people-fill', 'route' => 'users.index', 'permission' => 'users.manage'],
    ['label' => 'Roles & Permissions', 'icon' => 'bi-shield-lock', 'route' => 'roles.index', 'permission' => 'rbac.manage'],
    ...
];
```

**Line-by-line explanation:**

* Each entry is a menu item: a label, a Bootstrap icon name, a route name, and the
  **permission required to see it**.
* `['heading' => 'Leave']` → a section title rather than a link.
* `'permission' => ['employees.view', 'leave.review.department']` → an **array**
  means "any one of these is enough." HR and Department Head both see Leave
  Rankings, for different reasons.
* **`'requires_any' => [...]` on the Dashboard entry** → a second, *narrowing*
  condition. The System Administrator holds `dashboard.view` but no leave
  permission, and `/dashboard` redirects them to the Security Dashboard — so
  without this, their sidebar would show two links to the same page.
* The comments record real consequences of the layout: because the Mayor holds
  `leave.requests.view-all` and nothing else in that section, the "HR Management"
  heading appears in the Mayor's sidebar with exactly one entry under it.

**File:** `resources/views/partials/sidebar.blade.php`

**Code:**

```blade
@php
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
```

**Line-by-line explanation:**

* `@php ... @endphp` → a block of PHP inside a Blade template.
* `$itemVisible = function (array $item) { ... }` → one shared decision function.
  The comment explains why it is shared: when the heading loop and the item loop had
  separate copies, a heading could survive while every item under it was hidden —
  an "Administration" label with nothing beneath it.
* `(array) $item['permission']` → forces a single string into a one-item array, so
  the same code handles both spellings.
* `collect($needed)->contains(fn ($p) => $user?->hasPermission($p))` → true if the
  user holds **any** of them. `collect()` wraps the array in Laravel's collection
  helper so `contains()` is available.
* `$item['requires_any'] ?? []` → `??` means "or this if not set."
* `Route::has($item['route'])` → a final safety check: never render a link to a
  route that does not exist (which would crash the page).

**Code (the rendering loop):**

```blade
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
           href="{{ route($item['route']) }}">
            <i class="bi {{ $item['icon'] }}"></i><span>{{ $item['label'] }}</span>
        </a>
    @endif
@endforeach
```

**Line-by-line explanation:**

* `@foreach (config('menu') as $item)` → walk the menu configuration in order.
* `array_slice(config('menu'), $loop->index + 1)` → for a heading, look at
  everything *after* it. `$loop->index` is Blade's built-in counter.
* `if (isset($next['heading'])) break;` → stop at the next heading; only the items
  belonging to *this* section are considered.
* `if ($itemVisible($next)) { $visible = true; break; }` → the moment one visible
  item is found, the heading is worth printing. This is what prevents empty sections.
* `request()->routeIs($item['route'].'*')` → highlight the current page. The `*`
  means `leave.create`, `leave.store` etc. all light up the same entry.
* `{{ ... }}` → Blade's echo, which **escapes HTML automatically**. That is the
  built-in XSS defence on every page of this system.

**Function/Purpose:** Each role gets a sidebar built entirely from what they may do.

**Role/Permission involved:** All five, differently:

| Role | Sees in the sidebar |
|---|---|
| Employee | Dashboard, Apply for Leave, My Leave Requests, My Signature, My Audit Log |
| Department Head | the above + Leave Rankings + Reports |
| HR | the above + Leave Approvals, All Leave Requests, Employees, Departments, Positions, Leave Balances, Leave Types, Holidays, Reports |
| Mayor | Employee items + All Leave Requests (under an "HR Management" heading) + Reports |
| System Administrator | Security Dashboard + the whole Administration section + Reports |

---

## SECTION 18 — Routing each role to the right dashboard

**File:** `app/Http/Controllers/DashboardController.php`

**Code:**

```php
/** Routes each role to the dashboard it is permitted to see. */
public function index(Request $request): View|RedirectResponse
{
    $user = $request->user();

    // The System Administrator has one dashboard, and it is the security
    // one. They hold no leave permission, so this page would otherwise be
    // an empty frame...
    if (! $user->hasPermission('leave.view-own')
        && ! $user->hasPermission('leave.requests.view-all')
        && $user->hasPermission('security.dashboard')) {
        return redirect()->route('security.dashboard');
    }

    return view('dashboard.index', $this->dashboard->forUser($user));
}
```

**Line-by-line explanation:**

* `: View|RedirectResponse` → this method returns *either* a page or a redirect.
* The `if` describes the System Administrator **by their permissions, not by their
  role name**: no personal leave, no all-leave view, but yes to the security
  dashboard. Anybody matching that shape is sent to the security screen.
* `$this->dashboard->forUser($user)` → asks the service for the data this specific
  person is allowed to see, and hands it to the view.

**File:** `app/Services/DashboardService.php`

**Code:**

```php
/**
 * Two panes, gated separately, and somebody may hold both.
 *
 *   · leave.view-own       — their own credits and applications...
 *   · leave.approve.final  — the management pane: the whole LGU's leave,
 *                            for whoever decides it. Which is HR.
 *
 * THE SECOND GATE IS `approve.final`, NOT `requests.view-all`, and the
 * difference is the Mayor...
 */
public function forUser(User $user): array
{
    $data = [];

    if ($user->hasPermission('leave.view-own')) {
        $data['mine'] = $this->ownPane($user);
    }

    if ($user->hasPermission('leave.approve.final')) {
        $data['management'] = $this->managementPane();
    } elseif ($user->hasPermission('leave.review.department')) {
        // A department head gets the same pane scoped to the one office
        // they head — never `leave.requests.view-all`, which is the whole
        // municipality. If they head no office there is nothing to show,
        // and the pane is absent rather than empty.
        $data['department'] = $this->departmentPane($user);
    }

    return $data;
}
```

**Line-by-line explanation:**

* `$data = []` → build a list of panes; the view draws whichever are present.
* **First `if`** → anyone who files leave gets their own credits and applications.
  HR sees this too; an HR officer takes leave like anybody else.
* **Second `if` (`leave.approve.final`)** → the management pane, whole-LGU figures.
  Only HR.
* **`elseif` (`leave.review.department`)** → a Department Head instead gets the same
  shape of pane narrowed to the one office they head. `elseif` matters: if somebody
  somehow held both, they get the wider pane once, not two overlapping ones.
* The long comment explains the most subtle decision in the file: **the gate is
  `approve.final`, not `requests.view-all`.** The Mayor can read every application
  (via the All Leave Requests page) but does not run the leave operation — opening
  their dashboard onto HR's caseload buried the Mayor's own leave under a page of
  other people's.

**Function/Purpose:** One URL, `/dashboard`, produces five different pages
depending on permissions alone.

---

# PART 3 — THE LEAVE WORKFLOW

The approval chain, in the words of the service that implements it:

```
Employee files
   → the head of their office is NOTIFIED   (nothing to act on)
   → HR validates and decides
   → Approved | Disapproved
```

---

## SECTION 19 — Filing an application

**File:** `app/Http/Controllers/Leave/LeaveRequestController.php`

**Code (validation):**

```php
public function store(Request $request): RedirectResponse
{
    // 6.A posts an array: the entry form uses a single <select name="…[]">,
    // which yields a one-element array, and the printed sheet is a checkbox
    // list. Either way the "exactly one type" rule is enforced here, on the
    // server, rather than by the shape of the control.
    $data = $request->validate([
        'leave_type_id' => ['required', 'array', 'size:1'],
        'leave_type_id.*' => ['required', 'integer', 'exists:leave_types,id'],
        'date_filed' => ['required', 'date'],
        'start_date' => ['required', 'date'],
        'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        'purpose' => ['nullable', 'string', 'max:1000'],
        'commutation' => ['nullable', 'boolean'],
        'late_filing_reason' => ['nullable', 'string', 'max:500'],
        'applicant_signature' => ['required', 'string', 'max:150'],
        'details' => ['array'],
        'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
    ], [
        'leave_type_id.required' => 'Choose the type of leave you are applying for in section 6.A.',
        'leave_type_id.size' => 'Choose exactly one type of leave in section 6.A.',
        'end_date.after_or_equal' => 'The last day of leave cannot fall before the first day.',
    ], [
        'date_filed' => 'date of filing',
        'start_date' => 'first day of leave',
        'end_date' => 'last day of leave',
        'applicant_signature' => 'signature of applicant',
    ]);
```

**Line-by-line explanation:**

* `'leave_type_id' => ['required', 'array', 'size:1']` → must be a list with
  **exactly one** entry. The comment explains why: the on-screen form is a dropdown
  and the printed sheet is a checkbox list, so the rule is enforced on the server
  rather than relying on the type of input control.
* `'leave_type_id.*'` → the `.*` applies rules to **each item** of the array.
  `exists:leave_types,id` checks the value is a real leave type in the database —
  which stops somebody submitting an invented ID.
* `'end_date' => [..., 'after_or_equal:start_date']` → you cannot end leave before
  you start it.
* `'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']` → uploaded
  files must be one of four types and at most 5120 KB (5 MB). `mimes` checks the
  real file content, not just the extension.
* **The second array** is custom error messages, written in the language of the
  paper form ("section 6.A") rather than database column names.
* **The third array** renames the fields for error messages. The comment is
  precise: "The start date field is required" tells an employee looking at a box
  labelled "From" very little.

**Code (tidying the submission):**

```php
    $data['leave_type_id'] = (int) $data['leave_type_id'][0];

    // The CSC Form 6 layout prints every "In case of…" block at once, so the
    // browser posts a blank for each field the chosen leave type does not use.
    // Drop the empties...
    $data['details'] = array_filter(
        $data['details'] ?? [],
        static fn ($value) => $value !== null && $value !== '' && $value !== [],
    );

    $type = LeaveType::findOrFail($data['leave_type_id']);
    $leaveRequest = $this->applications->submit($request->user(), $type, $data);

    foreach ($request->file('documents', []) as $docType => $file) {
        $this->storeDocument($leaveRequest, $file, is_string($docType) ? $docType : 'supporting_document', $request->user()->id);
    }

    $message = 'Leave application submitted for review.';
    if ($leaveRequest->filing_warnings) {
        $message .= ' Note: '.implode(' ', $leaveRequest->filing_warnings);
    }

    return redirect()->route('leave.show', $leaveRequest)->with('status', $message);
}
```

**Line-by-line explanation:**

* `(int) $data['leave_type_id'][0]` → unwrap the one-item array back to a plain
  number for storage.
* `array_filter($data['details'], fn ($v) => $v !== null && $v !== '' && $v !== [])`
  → the form shows every "In case of sick leave / study leave / …" block at once, so
  the browser posts a blank for each block not used. This discards the blanks so
  only the boxes actually filled in are stored.
* `LeaveType::findOrFail(...)` → fetch the leave type, or produce a 404 if it has
  vanished.
* **`$this->applications->submit(...)`** → the controller stops here and hands over
  to the service. The controller's job was to validate the form; the *rules* live in
  the service. This is the SOLID layering the README describes.
* `$request->file('documents', [])` → the uploaded files, or an empty list.
* `redirect()->route('leave.show', $leaveRequest)` → the **POST/redirect/GET**
  pattern: after a successful submit, redirect, so refreshing the page does not file
  the leave a second time.

**Role/Permission involved:** `leave.apply` — **Employee, Department Head, HR and
Mayor** all file leave through this identical code.

---

## SECTION 20 — The ownership check on every leave page

**File:** `app/Http/Controllers/Leave/LeaveRequestController.php`

**Code:**

```php
private function authorizeView(Request $request, LeaveRequest $leaveRequest): void
{
    $user = $request->user();
    if ($leaveRequest->user_id === $user->id) {
        return;
    }
    if ($user->hasPermission('leave.requests.view-all')
        || $user->hasPermission('leave.certify.hr')
        || $user->hasPermission('leave.approve.final')) {
        return;
    }
    if ($user->hasPermission('leave.review.department')
        && $leaveRequest->user->employeeProfile?->department_id === $user->employeeProfile?->department_id) {
        return;
    }
    abort(403);
}
```

**Line-by-line explanation:**

This tiny method is the access-control rule for viewing **one specific**
application, and it is called by `show()`, `form6()`, `previewForm()`,
`timeline()` and `downloadDocument()`. Four ways to pass:

* `if ($leaveRequest->user_id === $user->id) return;` → **it is yours.** Every
  employee passes here for their own applications.
* the second `if` → **you hold a whole-LGU permission.** HR (`approve.final`,
  `certify.hr`) and the Mayor (`requests.view-all`) pass here.
* the third `if` → **you may review a department AND this applicant is in your
  department.** The two conditions joined by `&&` are what scopes a Department Head
  to their own office.
* `abort(403)` → everyone else is refused.

**Why this matters:** the route permission (`leave.view-own`) only proves the person
may look at *leave pages*. The application ID sits in the URL, where anyone could
change it. This method is what actually decides whose records they may open — it is
the defence against what security people call **IDOR** (insecure direct object
reference).

**Related, even stricter checks:**

```php
public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
{
    abort_unless($leaveRequest->user_id === $request->user()->id, 403);
    ...
}

public function uploadDocument(Request $request, LeaveRequest $leaveRequest): RedirectResponse
{
    abort_unless($leaveRequest->user_id === $request->user()->id, 403);
    ...
}
```

* `abort_unless($condition, 403)` → "stop with a 403 unless this is true."
* Note this is **stricter than `authorizeView`**: cancelling and uploading require
  strict ownership. Not even HR may cancel someone else's application or attach
  documents to it.

---

## SECTION 21 — LeaveApplicationService: the filing rules

**File:** `app/Services/Leave/LeaveApplicationService.php`

**Code (the constructor — five collaborators):**

```php
public function __construct(
    private readonly WorkingDayCalculator $calculator,
    private readonly LeavePolicyEngine $policy,
    private readonly LeaveCreditService $credits,
    private readonly ApprovalWorkflowService $workflow,
    private readonly AuditLogger $audit,
) {
}
```

**Line-by-line explanation:**

Each collaborator owns one concern: counting days, checking CSC policy, checking
credits, starting the approval chain, and recording the audit entry. The service
itself only *orchestrates* them.

**Code (counting the days):**

```php
public function submit(User $user, LeaveType $type, array $data): LeaveRequest
{
    $start = Carbon::parse($data['start_date']);
    $end = Carbon::parse($data['end_date']);
    $dateFiled = Carbon::parse($data['date_filed'] ?? now());
    // In the unit this type is granted in: working days for Vacation, Sick,
    // Forced and Special Privilege Leave; calendar days for the statutory
    // entitlements written as a span of time, such as maternity's 105.
    $workingDays = $this->calculator->countFor($type, $start, $end);
```

**Line-by-line explanation:**

* `Carbon::parse(...)` → Carbon is the date library Laravel uses; this converts the
  submitted text into a date object that can be compared and counted.
* `$data['date_filed'] ?? now()` → use the submitted filing date, or today.
* `countFor($type, $start, $end)` → the unit depends on the leave type (Section 22).

**Code (monetization — a real correctness fix):**

```php
    // Monetization is not an absence. What it converts is a number of
    // credits the employee names, and the dates on the form are only the
    // period it is claimed against -- so the day count comes from the
    // field they filled in, not from the calendar...
    if ($type->category === 'monetization') {
        $workingDays = (float) ($data['details']['days_to_monetize'] ?? 0);

        if ($workingDays <= 0) {
            throw ValidationException::withMessages([
                'details.days_to_monetize' => 'Enter how many leave credits to monetize.',
            ]);
        }
    }

    if ($workingDays <= 0) {
        throw ValidationException::withMessages([
            'end_date' => $type->counts_calendar_days
                ? 'The selected range contains no days.'
                : 'The selected range contains no working days (weekends and holidays are excluded).',
        ]);
    }
```

**Line-by-line explanation:**

* **Monetization** means converting unused leave credits into cash. It is not time
  off, so counting the calendar range would be wrong. The number comes from the
  employee's own "days to monetize" answer.
* `throw ValidationException::withMessages([...])` → throwing this from deep inside
  a service produces exactly the same red error message on the form as a controller
  validation failure would. The user sees a normal form error, not a crash.
* The second check catches a range made entirely of weekends and holidays, and
  words the message differently depending on whether this type counts calendar days.

**Code (policy and credit guards):**

```php
    $result = $this->policy->validate(
        $type, $data, $workingDays, $start, $dateFiled,
        $this->credits->sourceBalance($user, $type)?->balance,
        $user,
    );
    if ($result['errors']) {
        throw ValidationException::withMessages(['policy' => $result['errors']]);
    }

    // Hard credit guard at filing time (never allow filing beyond credits).
    if (! $this->credits->hasSufficientCredits($user, $type, $workingDays)) {
        $balance = $this->credits->sourceBalance($user, $type);
        throw ValidationException::withMessages([
            'leave_type_id' => sprintf('Insufficient %s credits: %.2f available, %.1f requested.',
                $type->credit_source, $balance?->balance ?? 0, $workingDays),
        ]);
    }
```

**Line-by-line explanation:**

* `$this->policy->validate(...)` → the CSC rule engine: minimum notice periods,
  required supporting documents, maximum durations. It returns errors, warnings, and
  whether a late-filing reason is required.
* `sourceBalance($user, $type)?->balance` → the credits this type draws from (see
  Section 23). `?->` guards types that deduct nothing.
* **The credit guard** → you cannot file beyond your credits, at filing time, not
  just at approval time.
* `sprintf('... %.2f available, %.1f requested.', ...)` → `%.2f` formats a number to
  two decimal places. The message states both figures so the employee knows exactly
  where they stand.

**Code (writing the record):**

```php
    return DB::transaction(function () use ($user, $type, $data, $start, $end, $dateFiled, $workingDays, $result, $profile) {
        $request = LeaveRequest::create([
            'reference_no' => LeaveRequest::nextReferenceNo(),
            'user_id' => $user->id,
            'leave_type_id' => $type->id,
            'date_filed' => $dateFiled,
            'start_date' => $start,
            'end_date' => $end,
            'working_days' => $workingDays,
            'details' => $data['details'] ?? [],
            'purpose' => $data['purpose'] ?? null,
            'commutation' => (bool) ($data['commutation'] ?? false),
            'is_late_filing' => $result['requires_late_reason'],
            'late_filing_reason' => $data['late_filing_reason'] ?? null,
            'filing_warnings' => $result['warnings'],
            'office_snapshot' => $profile?->department?->name,
            'position_snapshot' => $profile?->position?->title,
            'salary_snapshot' => $profile?->salary,
            'applicant_signature' => $data['applicant_signature'] ?? $user->name,
            'applicant_signature_hash' => $profile?->signature_hash,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $this->snapshotSignature($request, $profile?->signature_path);

        $this->workflow->initialize($request, $type);
        $this->audit->log('leave_submitted', $request, [], ['reference_no' => $request->reference_no], $user);

        return $request;
    });
}
```

**Line-by-line explanation:**

* `DB::transaction(function () { ... })` → **everything inside either all succeeds
  or is all undone.** If the workflow initialisation fails halfway, no orphan leave
  request is left behind.
* `use (...)` → PHP closures cannot see outer variables unless listed here.
* **`office_snapshot`, `position_snapshot`, `salary_snapshot`** → the three most
  interesting columns. These copy the employee's office, job title and salary **as
  they were on the day of filing**. If the person transfers office next year and you
  reprint the form, it must still show where they worked when they filed. A live
  lookup would rewrite history.
* `'status' => LeaveRequest::STATUS_PENDING` → it starts pending.
* `$this->snapshotSignature(...)` → see below.
* `$this->workflow->initialize($request, $type)` → hand off to the approval chain.

**Code (why the signature is copied, not referenced):**

```php
/**
 * Give the application its OWN copy of the signature it was filed with.
 *
 * Not a reference to the profile's file. The first version of this stored
 * the profile's path directly, which meant that replacing your signature
 * -- which deletes the file it replaces -- would have quietly broken the
 * signature on every application already filed, including ones already
 * approved and printed...
 */
private function snapshotSignature(LeaveRequest $request, ?string $source): void
{
    if ($source === null || ! Storage::disk('local')->exists($source)) {
        return;
    }

    $target = 'signatures/filed/'.$request->id.'.'.pathinfo($source, PATHINFO_EXTENSION);

    if (Storage::disk('local')->copy($source, $target)) {
        $request->update(['applicant_signature_path' => $target]);
    }
}
```

**Line-by-line explanation:**

* `if ($source === null || ! Storage::disk('local')->exists($source)) return;` →
  no signature on file, or the file is missing: do nothing, silently.
* `'signatures/filed/'.$request->id.'.'.pathinfo($source, PATHINFO_EXTENSION)` →
  the copy is named after **the application's own ID**, so the two files have
  independent lifetimes. `pathinfo(..., PATHINFO_EXTENSION)` keeps `.png` or `.jpg`.
* `Storage::disk('local')` → the private disk, outside the web root. Signature files
  are never directly reachable by URL; they are served through a controller that
  checks permissions.
* **A failed copy is not a failed application** — the `if` simply does not set the
  path, and the printed form falls back to the typed name. Nobody is turned away
  from filing leave because the disk is full.

**Code (cancelling):**

```php
public function cancel(LeaveRequest $request, User $actor): void
{
    if (! $request->isCancellable()) {
        throw ValidationException::withMessages(['status' => 'This request can no longer be cancelled.']);
    }

    DB::transaction(function () use ($request, $actor) {
        // If it had already been approved (shouldn't happen pre-final), reverse credits.
        if ($request->status === LeaveRequest::STATUS_APPROVED) {
            $this->credits->reverseDeduction($request, $actor);
        }
        $request->update(['status' => LeaveRequest::STATUS_CANCELLED, 'decided_at' => now()]);
        $this->audit->log('leave_cancelled', $request, [], [], $actor);
    });
}
```

**Line-by-line explanation:**

* `isCancellable()` → on the model: anything not already approved, rejected or
  cancelled.
* `reverseDeduction(...)` → a defensive branch. If an approved request somehow gets
  cancelled, the spent credits are given back rather than silently lost.
* Both steps are in one transaction, so the status change and the credit reversal
  can never disagree.

---

## SECTION 22 — Counting days: working days vs calendar days

**File:** `app/Services/Leave/WorkingDayCalculator.php`

**Code:**

```php
/**
 * The days a request consumes, in whichever unit this type is granted in.
 *
 * Not every entitlement is in working days. Maternity leave is 105 days
 * under RA 11210 and those are calendar days; counting them as working
 * days stretches 105 into about 147 calendar days, which is forty per cent
 * more leave than the law provides...
 */
public function countFor(\App\Models\LeaveType $type, Carbon $start, Carbon $end): float
{
    return $type->counts_calendar_days
        ? $this->countCalendarDays($start, $end)
        : $this->count($start, $end);
}

/** Every day in the range, weekends and holidays included. */
public function countCalendarDays(Carbon $start, Carbon $end): float
{
    if ($end->lt($start)) {
        return 0;
    }

    return (float) ($start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
}
```

**Line-by-line explanation:**

* `$type->counts_calendar_days` → a true/false column on the leave type. This is a
  legal distinction, not a preference: maternity (105 days, RA 11210), study leave,
  rehabilitation and adoption are written in the law as spans of *calendar* time,
  while Vacation, Sick, Forced and Special Privilege Leave are in *working* days
  under the Omnibus Rules.
* `$end->lt($start)` → "less than": a backwards range counts as 0 rather than a
  negative number.
* `$start->copy()` → **important**: Carbon date objects are mutable, so `copy()`
  prevents `startOfDay()` from modifying the caller's `$start` variable.
* `startOfDay()` → zero the time part, so a range entered at 3pm does not lose a day
  to rounding.
* `diffInDays(...) + 1` → the difference between Monday and Friday is 4, but the
  leave covers 5 days. The `+ 1` makes the range **inclusive** of both ends.

**Code (working days):**

```php
public function count(Carbon $start, Carbon $end): float
{
    if ($end->lt($start)) {
        return 0;
    }

    $holidays = $this->holidaysBetween($start, $end);
    $days = 0;
    foreach (CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()) as $day) {
        if ($day->isWeekend()) {
            continue;
        }
        if (isset($holidays[$day->toDateString()])) {
            continue;
        }
        $days++;
    }

    return (float) $days;
}
```

**Line-by-line explanation:**

* `$this->holidaysBetween($start, $end)` → **one** database query for the whole
  range, fetched before the loop. Querying inside the loop would mean one query per
  day.
* `CarbonPeriod::create($start, $end)` → produces every date in the range so the
  `foreach` can walk them one by one.
* `if ($day->isWeekend()) continue;` → `continue` means "skip to the next day
  without counting this one." Saturdays and Sundays are not working days.
* `isset($holidays[$day->toDateString()])` → the holidays were stored keyed by date
  string (`'2026-04-09' => true`), so this is an instant lookup instead of searching
  a list.
* `$days++` → count this day.

**Code (the holiday lookup and its cache):**

```php
private function holidaysBetween(Carbon $start, Carbon $end): array
{
    $key = $start->toDateString().'|'.$end->toDateString();
    if (isset($this->holidayCache[$key])) {
        return $this->holidayCache[$key];
    }

    $dates = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
        ->pluck('date')
        ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
        ->all();

    return $this->holidayCache[$key] = $dates;
}
```

**Line-by-line explanation:**

* `$key = start.'|'.end` → the cache is keyed by the exact range asked about.
* `$this->holidayCache` → an in-memory cache that lives only for this request.
  Useful because the preview and the actual submit ask the same question.
* `whereBetween('date', [...])` → SQL `BETWEEN`: only holidays inside the range.
* `mapWithKeys(fn ($d) => [dateString => true])` → converts a list of dates into a
  lookup table, which is what makes `isset()` in the loop fast.
* `return $this->holidayCache[$key] = $dates;` → assigns **and** returns in one line.

**Role/Permission involved:** The holiday calendar behind this is maintained by
**HR** (permission `holidays.manage`, routes `holidays.index` / `store` / `destroy`).

---

## SECTION 23 — Leave credits: the "never negative" rule

**File:** `app/Services/Leave/LeaveCreditService.php`

**Code (finding which balance a type draws from):**

```php
/** The credit-source balance a deductible request draws down. */
public function sourceBalance(User $user, LeaveType $type): ?LeaveBalance
{
    if (! $type->deductible || ! $type->credit_source) {
        return null;
    }
    $sourceType = $this->creditSourceType($type);

    return $sourceType ? $this->balanceFor($user, $sourceType) : null;
}

public function creditSourceType(LeaveType $type): ?LeaveType
{
    return match ($type->credit_source) {
        LeaveType::SOURCE_VACATION => LeaveType::where('code', 'VL')->first(),
        LeaveType::SOURCE_SICK => LeaveType::where('code', 'SL')->first(),
        default => null,
    };
}

public function hasSufficientCredits(User $user, LeaveType $type, float $days): bool
{
    if (! $type->deductible) {
        return true;
    }
    $balance = $this->sourceBalance($user, $type);

    return $balance ? (float) $balance->balance >= $days : true;
}
```

**Line-by-line explanation:**

* Some leave types spend credits (Vacation, Sick) and some do not (Maternity,
  Paternity — these are statutory entitlements, not a savings account). `deductible`
  is that flag.
* `credit_source` → **which** pot it spends from. Forced Leave and Special Privilege
  Leave, for example, draw on the Vacation balance.
* `match ($type->credit_source) { ... }` → PHP 8's `match`, a cleaner switch. It
  returns a value directly and requires an exact match.
* `default => null` → an unrecognised source deducts nothing rather than crashing.
* `hasSufficientCredits` → returns `true` for non-deductible types (nothing to
  check), otherwise compares balance against days requested.

**Code (the deduction, with locking):**

```php
public function deductForApproval(LeaveRequest $request, ?User $actor = null): void
{
    $type = $request->leaveType;
    if (! $type->deductible || ! $type->credit_source) {
        return;
    }

    DB::transaction(function () use ($request, $type, $actor) {
        $sourceType = $this->creditSourceType($type);
        if (! $sourceType) {
            return;
        }

        $balance = LeaveBalance::where('user_id', $request->user_id)
            ->where('leave_type_id', $sourceType->id)
            ->lockForUpdate()
            ->first();

        $balance ??= $this->balanceFor($request->user, $sourceType);

        // Idempotency: skip if a deduction ledger row already exists.
        $already = LeaveHistory::where('leave_request_id', $request->id)
            ->where('entry_type', 'deduction')->exists();
        if ($already) {
            return;
        }

        $days = (float) $request->working_days;
        if ((float) $balance->balance < $days) {
            throw new RuntimeException('Insufficient leave credits; cannot approve.');
        }

        $balance->used = (float) $balance->used + $days;
        $balance->balance = (float) $balance->balance - $days;
        $balance->save();

        LeaveHistory::create([
            'user_id' => $request->user_id,
            'leave_type_id' => $sourceType->id,
            'leave_request_id' => $request->id,
            'entry_type' => 'deduction',
            'days' => -$days,
            'balance_after' => $balance->balance,
            'remarks' => "Approved {$type->name} ({$request->reference_no})",
            'actor_id' => $actor?->id,
        ]);
    });
}
```

**Line-by-line explanation:**

* **`->lockForUpdate()`** → the most important line in the file. It issues an SQL
  `SELECT ... FOR UPDATE`, which tells the database "nobody else may touch this row
  until my transaction finishes." Without it, two officers approving two
  applications at the same instant could both read a balance of 5, both subtract 3,
  and leave the balance at 2 instead of −1. This is the concurrency safety the
  README advertises.
* `$balance ??= $this->balanceFor(...)` → `??=` means "assign only if currently
  null." Creates the balance row if the employee has none yet.
* **The idempotency guard** → if a `deduction` ledger row already exists for this
  application, stop. A retried job or a double-clicked button cannot deduct twice.
* `if ((float) $balance->balance < $days) throw new RuntimeException(...)` → the
  **never-negative invariant**, checked again here even though filing already
  checked it. Credits could have been spent elsewhere in between.
* `$balance->used + $days` and `$balance->balance - $days` → used goes up, remaining
  goes down.
* `LeaveHistory::create([... 'days' => -$days ...])` → a **ledger row**. The
  negative number marks a deduction, and `balance_after` records the running total,
  so the credit history is a proper audit trail rather than just a current number.

**Code (reversal and manual adjustment):**

```php
/** Reverse a deduction (e.g. cancellation after approval). */
public function reverseDeduction(LeaveRequest $request, ?User $actor = null): void
{
    DB::transaction(function () use ($request, $actor) {
        $deduction = LeaveHistory::where('leave_request_id', $request->id)
            ->where('entry_type', 'deduction')->first();
        if (! $deduction) {
            return;
        }

        $balance = LeaveBalance::where('user_id', $request->user_id)
            ->where('leave_type_id', $deduction->leave_type_id)
            ->lockForUpdate()->first();

        $days = abs((float) $deduction->days);
        $balance->used = max(0, (float) $balance->used - $days);
        $balance->balance = (float) $balance->balance + $days;
        $balance->save();

        LeaveHistory::create([..., 'entry_type' => 'reversal', 'days' => $days, ...]);
    });
}
```

**Line-by-line explanation:**

* Reads the original deduction row to learn exactly how many days to give back and
  from which pot — it does not recompute, it reverses what actually happened.
* `abs(...)` → the deduction was stored negative; flip it positive.
* `max(0, used - days)` → `used` can never go below zero even if the data were
  inconsistent.
* A **new** ledger row of type `reversal` is written. The original deduction row is
  never edited or deleted, which is what makes the ledger append-only.

**Code (HR's manual adjustment):**

```php
/** Manual HR adjustment with mandatory remark. */
public function adjust(User $user, LeaveType $type, float $days, string $remarks, ?User $actor = null): LeaveBalance
{
    return DB::transaction(function () use ($user, $type, $days, $remarks, $actor) {
        $balance = LeaveBalance::where('user_id', $user->id)
            ->where('leave_type_id', $type->id)->lockForUpdate()->first()
            ?? $this->balanceFor($user, $type);

        $newBalance = (float) $balance->balance + $days;
        if ($newBalance < 0) {
            throw new RuntimeException(sprintf(
                'cannot deduct %s days, only %s available.',
                number_format(abs($days), 2),
                number_format((float) $balance->balance, 2),
            ));
        }
        $balance->earned = (float) $balance->earned + max(0, $days);
        $balance->balance = $newBalance;
        $balance->save();

        LeaveHistory::create([..., 'entry_type' => 'adjustment', 'days' => $days, 'remarks' => $remarks, ...]);

        return $balance;
    });
}
```

**Line-by-line explanation:**

* `string $remarks` → **not** nullable. HR must say why. An unexplained credit
  adjustment is exactly the thing an audit would ask about.
* `$days` may be negative (a correction downwards) or positive (a grant).
* The error message carries **both figures** — the comment explains why: "would make
  the balance negative" tells an officer their number is wrong but not what a right
  one would be, so they go and look it up, which is the round trip the dialog exists
  to save.
* `$balance->earned + max(0, $days)` → only *positive* adjustments raise the
  lifetime "earned" total. A downward correction reduces the balance without
  rewriting history.

**Code (monthly accrual):**

```php
/** Monthly accrual for one user/type/period; idempotent via the period guard. */
public function accrue(User $user, LeaveType $type, string $period, ?User $actor = null): bool
{
    ...
    return (bool) DB::transaction(function () use ($user, $type, $period, $actor) {
        $exists = LeaveHistory::where('user_id', $user->id)
            ->where('leave_type_id', $type->id)
            ->where('entry_type', 'accrual')
            ->where('period', $period)->exists();
        if ($exists) {
            return false;
        }

        $rate = $this->monthlyAccrualRate($type->code === 'SL' ? LeaveType::SOURCE_SICK : LeaveType::SOURCE_VACATION);
        $balance = $this->balanceFor($user, $type);
        $balance->earned = (float) $balance->earned + $rate;
        $balance->balance = (float) $balance->balance + $rate;
        $balance->last_accrued_period = $period;
        $balance->save();

        LeaveHistory::create([..., 'entry_type' => 'accrual', 'days' => $rate, 'period' => $period, ...]);

        return true;
    });
}
```

**Line-by-line explanation:**

* `$period` → a month stamp such as `'2026-09'`.
* **The period guard** → if an accrual row already exists for this person, this
  type and this month, stop and return false. This means the monthly command can be
  run twice, or re-run after a crash, without giving anyone double credits.
* `$rate` → 1.25 days per month by default for both VL and SL (the CSC rate), read
  from System Settings so it can be changed without code.
* Returns true/false so the scheduled command can report how many accruals it made.

**Role/Permission involved:**
* Automatic deduction on approval → triggered by **HR** (`leave.approve.final`).
* Manual `adjust()` → **HR** only (`leave.balances.manage`, route `balances.adjust`).
* `accrue()` → the scheduled command `AccrueLeaveCredits`, run by the server, no user.

---

## SECTION 24 — ApprovalWorkflowService: who decides, and who is merely told

**File:** `app/Services/Leave/ApprovalWorkflowService.php`

This class carries the clearest statement of the role model in the whole codebase.

**Code (the constants):**

```php
/** Step 0 — a record that the applicant's department head was informed. */
public const STEP_DEPARTMENT = 'department';

/** Step 1 — the decision. Kept as "authorized": any holder may act. */
public const STEP = 'authorized';

/** Permission that authorizes deciding an application. */
public const STEP_PERMISSION = 'leave.approve.final';

/**
 * Permission that lets a head see their own office's leave.
 *
 * The slug still says "review" because it is written into route guards,
 * menu entries and existing installations' permission tables; what it
 * grants is now visibility, not authority. Nothing in this service asks
 * for it — a head cannot act on an application at all.
 */
public const DEPARTMENT_PERMISSION = 'leave.review.department';
```

**Line-by-line explanation:**

* `STEP_PERMISSION = 'leave.approve.final'` → **the whole authority model in one
  line.** The decision is gated on a *permission*, never on a role name. That is why
  the seeder could withdraw the Mayor's authority by deleting one array entry,
  with no code change anywhere.
* `DEPARTMENT_PERMISSION` → and the comment is explicit that this grants
  **visibility, not authority**.

**Code (starting the workflow):**

```php
public function initialize(LeaveRequest $request, LeaveType $type): void
{
    $head = $this->departmentHeadFor($request);

    if ($head !== null) {
        Approval::create([
            'leave_request_id' => $request->id,
            'step_no' => 0,
            'role_slug' => self::STEP_DEPARTMENT,
            'approver_id' => $head->id,
            'action' => Approval::ACTION_NOTIFIED,
            // The name as it stood on the day of filing — box 7.B of the
            // printed form reads this, not today's head of the office.
            'signature' => $head->name,
            'acted_at' => now(),
        ]);
    }

    Approval::create([
        'leave_request_id' => $request->id,
        'step_no' => 1,
        'role_slug' => self::STEP,
        'action' => Approval::ACTION_PENDING,
    ]);

    $request->update([
        'current_step' => 1,
        'status' => LeaveRequest::STATUS_PENDING,
    ]);

    $request->user->notify(new LeaveStatusNotification($request, 'submitted'));

    // Sent after the request is in the queue, not before: a head told about
    // an application that then failed to save would be told about nothing.
    $head?->notify(new DepartmentLeaveFiledNotification($request));
}
```

**Line-by-line explanation:**

* **Row 1 (step 0)** — created with `ACTION_NOTIFIED`, and `acted_at` already set.
  It is written **already closed**, so no query looking for pending work can ever
  find it. It exists for two reasons the comments give:
  * CSC Form 6 box 7.B must name whoever headed the office **on the day of filing** —
    `'signature' => $head->name` is that snapshot.
  * The employee's timeline can then state that their head was informed, with a
    timestamp, instead of asking them to take it on faith.
* **Row 2 (step 1)** — `ACTION_PENDING`, with **no approver**. This is the real
  queue entry that HR will act on. Nobody is assigned; whoever holds the permission
  may take it.
* `$request->update(['current_step' => 1, 'status' => STATUS_PENDING])` → there is
  only ever one step to wait on.
* `$request->user->notify(...)` → tell the applicant it was received.
* `$head?->notify(...)` → **last**, and after everything is saved. The comment
  explains the ordering: a head told about an application that then failed to save
  would have been told about nothing.

**Code (which head, and when there is none):**

```php
public function departmentHeadFor(LeaveRequest $request): ?User
{
    $department = $request->user?->employeeProfile?->department;

    if ($department?->head_user_id === null) {
        return null;
    }

    if ((int) $department->head_user_id === (int) $request->user_id) {
        return null;
    }

    return $department->head;
}
```

**Line-by-line explanation:**

* `$request->user?->employeeProfile?->department` → a chain of nullsafe hops:
  application → applicant → their profile → their office. Any missing link yields
  null rather than an error.
* **`$department?->head_user_id`** → the head is read from the **department record**,
  not from "whoever holds the Department Head role and happens to work there." One
  named person, and no ambiguity when an office has two people with the role.
* The third case → **the applicant IS the head.** Telling somebody what they have
  just done themselves is noise, and box 7.B would carry their own name twice.

**Code (the head's recommendation — and its scope check):**

```php
/**
 * The head of the applicant's office recommends on box 7.B.
 *
 * A RECOMMENDATION, not a decision. The application is already with HR and
 * stays there whichever way this goes: a head who does not recommend it
 * does not stop it, and HR is not bound by either answer...
 */
public function recommend(LeaveRequest $request, User $head, bool $favourable, ?string $reason = null): Approval
{
    $row = $request->approvals()
        ->where('role_slug', self::STEP_DEPARTMENT)
        ->firstOrFail();

    // Once only. A head who has recommended has signed box 7.B, and a
    // second answer would overwrite a signature already printed on forms
    // that have been filed.
    if (! in_array($row->action, [Approval::ACTION_NOTIFIED, Approval::ACTION_PENDING], true)) {
        throw ValidationException::withMessages([
            'recommendation' => 'You have already recorded a recommendation on this application.',
        ]);
    }

    $row->update([
        'approver_id' => $head->id,
        'action' => $favourable ? Approval::ACTION_RECOMMENDED : Approval::ACTION_NOT_RECOMMENDED,
        'comments' => $reason,
        'signature' => $head->name,
        'signature_path' => $this->snapshotSignature($row, $head),
        'acted_at' => now(),
    ]);
    ...
}
```

**Line-by-line explanation:**

* Note what this method **does not** touch: `status` and `current_step`. The
  application stays exactly where it was — with HR. That is what makes this a
  recommendation rather than a decision, and it mirrors the paper form, where 7.B
  recommends and 7.C/7.D approve or disapprove, signed by different people.
* The "once only" guard → a head who has signed box 7.B cannot change their answer,
  because forms carrying that signature may already have been printed and filed.
* `'signature' => $head->name` is **re-snapshotted** from whoever actually signed —
  which need not be whoever was notified, since an officer-in-charge may have taken
  over the office since filing.

**Code (the scope check — the key role rule):**

```php
/**
 * Whether this person may recommend on this application.
 *
 * The permission says "may review a department"; WHICH department comes
 * from who heads it, never from the request. Without that second check any
 * head could recommend on anybody in the LGU by opening the right
 * reference.
 */
public function canRecommend(User $user, LeaveRequest $request): bool
{
    if (! $user->hasPermission(self::DEPARTMENT_PERMISSION)) {
        return false;
    }

    // Nobody recommends on their own leave.
    if ($user->id === $request->user_id) {
        return false;
    }

    $officeId = $request->user?->employeeProfile?->department_id;

    return $officeId !== null
        && $user->headsDepartments()->whereKey($officeId)->exists();
}
```

**Line-by-line explanation:**

* **Check 1** → do they hold the permission at all? (Department Head role.)
* **Check 2** → nobody acts on their own leave, whatever they hold.
* **Check 3** → `$user->headsDepartments()->whereKey($officeId)->exists()` asks the
  database: "is this person named as head of *that specific* office?"
  `whereKey(...)` filters by primary key. `exists()` returns true/false.
* This is the pattern the codebase uses everywhere: **permission = what kind of
  thing you may do; database record = which specific thing.**

**Code (who may decide):**

```php
/**
 * Whoever holds the permission decides — which is HR, and only HR.
 *
 * Gated on the permission rather than on a role slug, so an administrator
 * who grants it to somebody else does not have to change any code, and so
 * withdrawing it from the Mayor took one seeder line rather than a rewrite.
 */
public function canDecide(User $user): bool
{
    return $user->hasPermission(self::STEP_PERMISSION);
}
```

**Line-by-line explanation:** One line, and the comment explains the whole design
philosophy. No role name. Grant `leave.approve.final` to anyone and they can decide;
withdraw it and they cannot.

**Code (recording the decision):**

```php
public function act(LeaveRequest $request, User $actor, string $action, array $extra = []): LeaveRequest
{
    // Already approved, rejected or cancelled? Nothing may change it.
    if ($request->isFinal()) {
        throw ValidationException::withMessages([
            'status' => 'This application has already been decided and can no longer be changed.',
        ]);
    }

    // An employee may never act on their own application, whatever else
    // they hold. Checked before anything else...
    if ($request->user_id === $actor->id) {
        throw ValidationException::withMessages(['status' => 'You cannot decide your own leave application.']);
    }

    // There is one step and one authority. A department head reaching this
    // — from a stale queue page, or by posting the route directly — is
    // refused here rather than merely being absent from a list.
    if (! $this->canDecide($actor)) {
        throw ValidationException::withMessages(['status' => 'You are not authorized to decide leave applications.']);
    }

    $approval = $request->approvals()->where('step_no', 1)->first();
    if (! $approval || $approval->action !== Approval::ACTION_PENDING) {
        throw ValidationException::withMessages(['status' => 'There is no pending decision to act on.']);
    }
```

**Line-by-line explanation:** Four gates before anything is written, in this order:

1. `isFinal()` → a decided application is immutable.
2. **self-approval** → checked before the permission check, because it is the one
   rule with no exception. An HR officer filing their own leave cannot approve it.
3. `canDecide($actor)` → **the permission is re-checked here even though the route
   already checked it.** The comment explains why: the queue page and the decision
   are two separate requests, and only the second one changes data. Somebody with a
   stale page open, or posting the URL directly, is refused here.
4. a pending step must actually exist.

**Code (the locked transaction):**

```php
    return DB::transaction(function () use ($request, $actor, $action, $extra, $approval) {
        // Re-read under the transaction so two officers acting at the same
        // moment cannot both pass the pending check above.
        $locked = Approval::whereKey($approval->id)->lockForUpdate()->first();
        if (! $locked || $locked->action !== Approval::ACTION_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Another authorized officer has just decided this application.',
            ]);
        }

        $locked->update([
            'approver_id' => $actor->id,
            'action' => $this->normalizeAction($action),
            'comments' => $extra['comments'] ?? null,
            'days_with_pay' => $extra['days_with_pay'] ?? null,
            'days_without_pay' => $extra['days_without_pay'] ?? null,
            'certified_balances' => $extra['certified_balances'] ?? null,
            'signature' => $extra['signature'] ?? $actor->name,
            'acted_at' => now(),
        ]);
```

**Line-by-line explanation:**

* **The pending check is done twice on purpose.** The first was outside the
  transaction and is cheap; this one re-reads the row *with a lock*, so if two HR
  officers click Approve in the same second, the second one finds the row already
  decided and gets a clear message instead of overwriting the first decision.
* `certified_balances` → the VL/SL figures **as certified at the moment of
  decision**, stored on the approval row. This is why reprinting the form years
  later shows what was certified rather than what the ledger says today.

**Code (the three outcomes):**

```php
        if ($action === 'returned') {
            // Sent back to the employee for revision; the step reopens.
            $locked->update(['action' => Approval::ACTION_PENDING, 'acted_at' => null, 'approver_id' => null]);
            $request->update(['status' => LeaveRequest::STATUS_RETURNED]);
            $this->finish($request, $actor, 'returned');

            return $request;
        }

        if ($action === 'rejected') {
            $request->update([
                'status' => LeaveRequest::STATUS_REJECTED,
                'disapproval_reason' => $extra['comments'] ?? null,
                'decided_at' => now(),
            ]);
            $this->finish($request, $actor, 'rejected');

            return $request;
        }

        // Approved — final, with the pay split and the automatic deduction.
        $request->update([
            'status' => LeaveRequest::STATUS_APPROVED,
            'days_with_pay' => $extra['days_with_pay'] ?? $request->working_days,
            'days_without_pay' => $extra['days_without_pay'] ?? 0,
            'decided_at' => now(),
        ]);
        $this->credits->deductForApproval($request, $actor);
        $this->finish($request, $actor, 'approved');

        return $request;
    });
}
```

**Line-by-line explanation:**

* **Returned** → the approval row is put *back* to pending with the approver and
  timestamp cleared, so the step reopens for a corrected resubmission. The request's
  own status becomes `returned` so the employee sees it needs work.
* **Rejected** → final. The comment becomes the `disapproval_reason` printed on the
  form.
* **Approved** → final, records the with-pay / without-pay split (defaulting to all
  days with pay), stamps `decided_at`, and **then** calls `deductForApproval()`.
  Because this is all inside one transaction, the approval and the credit deduction
  either both happen or neither does.
* `$this->finish(...)` → writes the audit entry and notifies the applicant.

**Function/Purpose:** One pending step, one authority, decided once, with the
credits moved atomically and every outcome notified and audited.

**Role/Permission involved:** The single most role-relevant class in the codebase:

| Role | What this service lets them do |
|---|---|
| Employee | files; is notified; cannot act |
| Department Head | is notified (`ACTION_NOTIFIED`), may `recommend()` on their **own office only**; `act()` refuses them |
| HR | `canDecide()` returns true → approve / disapprove / return |
| Mayor | no `leave.approve.final`, so `act()` refuses them; signs the printed form as head of agency |
| System Administrator | no leave permissions at all; never appears here |

---

## SECTION 25 — ApprovalController: the two role-specific endpoints

**File:** `app/Http/Controllers/Leave/ApprovalController.php`

**Code (HR's queue):**

```php
/**
 * Everything awaiting HR's decision.
 *
 * One audience now. The department head step became a notification, so
 * there is no second, narrower queue and no branch here deciding which of
 * the two a visitor gets — the route admits only holders of
 * `leave.approve.final`, and every one of them sees the same list.
 */
public function queue(Request $request): View
{
    $requests = LeaveRequest::with('leaveType', 'user.employeeProfile.department')
        ->whereIn('status', [
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_DEPT_REVIEW,
            LeaveRequest::STATUS_RETURNED,
        ])
        ->latest()
        ->paginate(config('lists.per_page'));

    return view('leave.review', [
        'requests' => $requests,
        'title' => 'Leave Approvals',
        'decides' => true,
    ]);
}
```

**Line-by-line explanation:**

* `->with('leaveType', 'user.employeeProfile.department')` → **eager loading**. It
  fetches the related rows in a few queries up front instead of one query per row
  while rendering (the "N+1 problem"). The dots walk the relationship chain.
* `whereIn('status', [...])` → the three statuses that still need HR. The comment
  explains why `STATUS_DEPT_REVIEW` is still listed: old installations may carry
  requests filed under the previous two-step flow, and a request created by a queued
  job mid-deploy should not become invisible.
* `->latest()` → newest first. `->paginate(config('lists.per_page'))` → page size
  from configuration rather than hardcoded.

**Code (recording the decision):**

```php
public function act(Request $request, LeaveRequest $leaveRequest): RedirectResponse
{
    $data = $request->validate([
        'action' => ['required', 'in:approved,rejected,returned'],
        'comments' => ['nullable', 'string', 'max:1000'],
        'days_with_pay' => ['nullable', 'numeric', 'min:0'],
        'days_without_pay' => ['nullable', 'numeric', 'min:0'],
        'signature' => ['nullable', 'string', 'max:150'],
    ]);

    $extra = [...];

    // The officer deciding is the officer certifying — one step, one
    // person, and the credit balances are snapshotted onto the decision so
    // the printed form states what was certified rather than what the
    // ledger happens to say when somebody reprints it.
    $extra['certified_balances'] = $this->certification($leaveRequest);

    $this->workflow->act($leaveRequest, $request->user(), $data['action'], $extra);

    return back()->with('status', 'Decision recorded.');
}
```

**Line-by-line explanation:**

* `'in:approved,rejected,returned'` → only these three words are accepted. Anything
  else is rejected before reaching the service.
* `$this->certification($leaveRequest)` → reads the applicant's current VL and SL
  balances and stamps them, with a timestamp, onto the decision.
* `$this->workflow->act(...)` → the controller does not decide anything itself; it
  validates the form and delegates, so all four safety gates from Section 24 apply.

**Code (the head's recommendation endpoint):**

```php
public function recommend(Request $request, LeaveRequest $leaveRequest): RedirectResponse
{
    abort_unless($this->workflow->canRecommend($request->user(), $leaveRequest), 403);

    // Nothing to recommend on once it is decided...
    abort_if($leaveRequest->isFinal(), 422,
        'This application has already been decided.');

    $data = $request->validate([
        'recommendation' => ['required', 'in:recommended,not_recommended'],
        // Required only when they are NOT recommending it: the printed
        // form rules a line for "For disapproval due to" and leaves the
        // approval box bare, because a refusal is the one that needs a
        // reason on it.
        'reason' => ['nullable', 'string', 'max:500', 'required_if:recommendation,not_recommended'],
    ], [], ['reason' => 'reason']);

    $favourable = $data['recommendation'] === 'recommended';

    $this->workflow->recommend($leaveRequest, $request->user(), $favourable, ...);

    return back()->with('status', $favourable
        ? 'Recommended for approval. HR still decides the application.'
        : 'Recorded as not recommended. HR still decides the application.');
}
```

**Line-by-line explanation:**

* `abort_unless($this->workflow->canRecommend(...), 403)` → **the scope check**. The
  route already proved they hold `leave.review.department`; this proves this
  particular application belongs to the office they actually head.
* `abort_if($leaveRequest->isFinal(), 422, ...)` → once HR has signed 7.C or 7.D,
  advice about a closed question is refused. 422 means "I understood the request but
  cannot process it."
* `'required_if:recommendation,not_recommended'` → a reason is compulsory **only**
  when they are declining to recommend, which mirrors the paper form: the
  disapproval box has a ruled line for a reason, the approval box does not.
* Both success messages end with **"HR still decides the application"** — the
  interface itself tells the head their recommendation is not a decision.

---

# PART 4 — ADMINISTRATION: how a person GETS a role

## SECTION 26 — UserController: creating accounts and assigning roles

**File:** `app/Http/Controllers/Admin/UserController.php`

**Code (only the five roles may be assigned):**

```php
/** Only the five roles the form offers are accepted from a submission. */
private function roleRules(): array
{
    return [
        'roles' => ['required', 'array', 'min:1'],
        'roles.*' => ['integer', Rule::exists('roles', 'id')->where(
            fn ($q) => $q->whereIn('slug', Role::ASSIGNABLE)
        )],
    ];
}
```

**Line-by-line explanation:**

* **This is where a person gets a role.** `'roles' => ['required', 'array', 'min:1']`
  → every account must have at least one role. There is no such thing as a
  role-less account.
* `Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('slug', Role::ASSIGNABLE))`
  → each submitted role ID must exist **and** its slug must be one of the five in
  `Role::ASSIGNABLE`. So even if somebody edits the HTML and posts the ID of some
  other role row, the submission is rejected.
* This is the server-side twin of the dropdown. The dropdown decides what is
  *offered*; this decides what is *accepted*. Hiding a control is not access control.

**Code (creating the account):**

```php
public function store(Request $request): RedirectResponse
{
    $data = $request->validate(array_merge([
        'name' => ['required', 'string', 'max:255'],
        'username' => ['required', 'alpha_dash', 'min:3', 'max:255', 'unique:users,username'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
    ], $this->roleRules(), $this->profileRules()), self::messages());

    // The employee number is the system's to issue, not the form's to
    // send. The field is shown read-only, but readonly is a hint to the
    // browser and nothing more -- so the number is taken here and whatever
    // arrived in the request is discarded.
    $data['employee_no'] = EmployeeProfile::nextEmployeeNo();

    // One first-time password for every new account...
    $first = config('security.first_password');

    $user = User::create([
        'name' => $data['name'],
        'username' => $data['username'],
        'email' => $data['email'],
        'password' => Hash::make($first),
        'status' => User::STATUS_ACTIVE,
        'must_change_password' => true,
        'email_verified_at' => now(),
    ]);

    // Only profile columns, rather than everything that came off the form.
    $user->employeeProfile()->create(
        Arr::only($data, array_merge(['employee_no'], array_keys($this->profileRules())))
    );
    $this->rbac->syncUserRoles($user, $this->keepUnassignable($user, $data['roles']));
    $this->audit->log('user_created', $user, [], ['email' => $user->email, 'temp_password' => '[STANDARD]']);

    return redirect()->route('users.index')
        ->with('status', "User created. {$data['name']} signs in with the first-time password {$first} and sets their own before they can go any further.");
}
```

**Line-by-line explanation:**

* `array_merge([...], $this->roleRules(), $this->profileRules())` → three rule sets
  combined: account fields, roles, and the CSC Form 6 profile fields.
* `'username' => [..., 'alpha_dash', 'unique:users,username']` → letters, numbers,
  dashes and underscores only, and no duplicates.
* **`$data['employee_no'] = EmployeeProfile::nextEmployeeNo();`** → overwrites
  whatever the form sent. The comment says exactly why: `readonly` is a hint to the
  browser and nothing more. A number is assigned once, never edited, never reissued —
  archiving keeps the profile row, so a resigned employee's number stays counted.
* `'must_change_password' => true` → hands them straight to `ForcePasswordChange`.
* `Arr::only($data, [...])` → copy **only** the profile columns into the profile
  row, so nothing unexpected from the form can slip into the database.
* **`$this->rbac->syncUserRoles($user, ...)`** → the actual role assignment, which
  also bumps the RBAC cache version so the new permissions apply immediately.
* `'temp_password' => '[STANDARD]'` → the audit entry deliberately does not record
  the password.

**Code (the guard against self-promotion):**

```php
public function update(Request $request, User $user): RedirectResponse
{
    $data = $request->validate(array_merge([...], $this->roleRules(), $this->profileRules()), self::messages());

    // Editing your own roles is a way to grant yourself authority. The
    // guard came with the roles from the form that used to own them.
    $ownAccount = $request->user()->id === $user->id;

    $old = $user->getAttributes();
    $user->update(['name' => $data['name'], 'email' => $data['email']]);
    $user->employeeProfile?->update(Arr::only($data, array_keys($this->profileRules())));

    if (! $ownAccount) {
        $this->rbac->syncUserRoles($user, $this->keepUnassignable($user, $data['roles']));
    }
    $this->audit->log('user_updated', $user, $old, $user->getChanges());

    return redirect()->route('users.index')->with('status', $ownAccount
        ? 'User updated. Your own roles were left alone.'
        : 'User updated.');
}
```

**Line-by-line explanation:**

* **`$ownAccount`** → an administrator editing their own account has their profile
  saved but their **roles silently skipped**. This is a privilege-escalation guard:
  otherwise an administrator could tick "HR" on their own account and start reading
  everyone's leave records.
* The message says so explicitly rather than pretending the save was complete.
* `$old = $user->getAttributes()` before, `$user->getChanges()` after → the audit row
  records old → new.

**Code (the same guard on individual permission overrides):**

```php
public function updateAccess(Request $request, User $user): RedirectResponse
{
    // Users cannot edit their own access (privilege-escalation guard).
    if ($request->user()->id === $user->id) {
        return back()->with('error', 'You cannot change your own access.');
    }

    // Allow and deny are two checkboxes for a value with three states, so
    // both can be ticked -- and the save used to apply deny and drop the
    // allow without saying so. It is refused now, and says which one.
    $data = $request->validate([
        'allow' => ['array'],
        'allow.*' => [
            'exists:permissions,id',
            Rule::notIn($request->input('deny', [])),
        ],
        'deny' => ['array'],
        'deny.*' => ['exists:permissions,id'],
    ], [
        'allow.*.not_in' => 'A permission cannot be both allowed and denied. '
            .'Leave both unticked to inherit it from the role.',
    ]);

    $pivot = [];
    foreach ($data['allow'] ?? [] as $id) {
        $pivot[$id] = ['type' => 'allow'];
    }
    foreach ($data['deny'] ?? [] as $id) {
        $pivot[$id] = ['type' => 'deny'];
    }
    $user->directPermissions()->sync($pivot);
    $this->rbac->bumpVersion();
    $this->audit->log('user_access_changed', $user, [], $data);

    return redirect()->route('users.access', $user)->with('status', 'Access updated.');
}
```

**Line-by-line explanation:**

* **The self-edit refusal is absolute here** — not "silently skipped" but refused
  with a message, because this page exists only to change permissions.
* `Rule::notIn($request->input('deny', []))` → a permission ticked as *allow* must
  not also appear in the *deny* list. Three states (inherit / allow / deny) expressed
  as two checkboxes means both can be ticked; this catches it and says so, rather
  than quietly applying one.
* `$pivot[$id] = ['type' => 'allow']` → builds the `permission_user` rows. Note the
  deny loop runs second, so if the validation were ever bypassed, deny would win —
  consistent with `RbacService`.
* `$user->directPermissions()->sync($pivot)` → the overrides become exactly this set.
* **`$this->rbac->bumpVersion()`** → without this line the change would not take
  effect for up to 5 minutes.

**Code (not demoting people by accident):**

```php
/**
 * Keep any role the account already holds that this form cannot offer.
 *
 * Without this, opening an existing Super Admin in the editor and pressing
 * Save would quietly demote them: the picker cannot show the role, so it is
 * absent from the submission, and a plain sync would remove it.
 */
private function keepUnassignable(User $user, array $submitted): array
{
    $hidden = $user->roles()
        ->whereNotIn('slug', Role::ASSIGNABLE)
        ->pluck('roles.id')
        ->all();

    return array_values(array_unique(array_merge($submitted, $hidden)));
}
```

**Line-by-line explanation:**

* `whereNotIn('slug', Role::ASSIGNABLE)` → find roles the person holds that the form
  cannot display (a legacy Super Admin, for example).
* `array_merge($submitted, $hidden)` → add them back before syncing, so the invisible
  role survives a save. Because `sync()` removes anything not in the list, without
  this line pressing Save on such an account would silently strip the role.

**Code (archive, never delete):**

```php
public function archive(User $user): RedirectResponse
{
    Archive::create([
        'archivable_type' => User::class,
        'archivable_id' => $user->id,
        'snapshot' => $user->toArray(),
        'archived_by' => request()->user()->id,
    ]);
    $user->delete(); // soft delete
    $this->audit->log('user_archived', $user);

    return back()->with('status', 'User archived.');
}
```

**Line-by-line explanation:**

* `Archive::create([... 'snapshot' => $user->toArray() ...])` → stores a full copy
  of the row as it was, plus who archived it.
* `$user->delete();` → because the model uses `SoftDeletes`, this only sets
  `deleted_at`. The row, and every leave application and audit entry pointing at it,
  survives untouched.

**Role/Permission involved:** Every method here is behind `users.manage` — the
**System Administrator** alone. This controller is the *only* place a role is handed
out, which is why the self-edit guards matter so much.

---

## SECTION 27 — RoleController: editing what a role may do

**File:** `app/Http/Controllers/Admin/RoleController.php`

**Code:**

```php
/**
 * The wildcard is never assignable from this form.
 *
 * `*` satisfies every permission check in the application. It was rendered
 * as an ordinary checkbox on the role form, so one click could grant any
 * role unrestricted access to users, security, settings and every
 * employee's leave record — permanently, with an audit line as the only
 * trace. No role holds it now and none can be given it here.
 *
 * Refused on the way in as well as hidden on the way out: this form can be
 * replayed with any permission id in it, and hiding a control is not access
 * control.
 */
public const NEVER_ASSIGNABLE = ['*'];

/** Every permission the form may offer, grouped by module. */
private function assignablePermissions()
{
    return Permission::whereNotIn('slug', self::NEVER_ASSIGNABLE)
        ->orderBy('module')->orderBy('name')->get()->groupBy('module');
}

public function update(Request $request, Role $role): RedirectResponse
{
    $data = $request->validate([
        'name' => ['required', 'string', 'max:100'],
        'description' => ['nullable', 'string', 'max:255'],
        'parent_id' => ['nullable', 'exists:roles,id', 'different:'.$role->id],
        'permissions' => ['array'],
        'permissions.*' => [
            'integer',
            Rule::exists('permissions', 'id')->whereNotIn('slug', self::NEVER_ASSIGNABLE),
        ],
    ]);

    $old = $role->getAttributes();
    $role->update($data);
    $this->rbac->syncRolePermissions($role, $data['permissions'] ?? []);
    $this->audit->log('role_updated', $role, $old, $role->getChanges());

    return redirect()->route('roles.index')->with('status', 'Role updated.');
}
```

**Line-by-line explanation:**

* **The wildcard is blocked twice** — hidden from the form by
  `assignablePermissions()`, and refused on submission by the `Rule::exists(...)
  ->whereNotIn('slug', ...)` in `update()`. The comment states the principle:
  a form can be replayed with any ID in it, so hiding a checkbox is not security.
* `'parent_id' => [..., 'different:'.$role->id]` → a role cannot be its own parent.
  That, plus the `$seen` loop guards in `RbacService`, keeps inheritance from
  looping forever.
* `$this->rbac->syncRolePermissions($role, ...)` → sets the role's permissions and
  bumps the cache version, so **every user holding that role is re-evaluated at
  once**. This is how the README's "changes behavior immediately" claim is honoured.

**Code (deletion, kept and kept refusing):**

```php
public function destroy(Role $role): RedirectResponse
{
    if ($role->is_system) {
        return back()->with('error', 'The five LGU roles cannot be deleted.');
    }
    $this->audit->log('role_deleted', $role, $role->getAttributes(), []);
    $role->delete();
    $this->rbac->bumpVersion();

    return redirect()->route('roles.index')->with('status', 'Role deleted.');
}
```

**Line-by-line explanation:**

* All five roles are `is_system`, so in practice this always refuses — but as the
  comment says, **the check is what makes that true, rather than the seeding.**
  The route exists because a replayed form would hit it.

**Role/Permission involved:** `rbac.manage` — **System Administrator** only. Note
they can *edit* what roles may do, but they cannot *create* a sixth role, and they
cannot grant the wildcard.

---

## SECTION 28 — The audit trail

**File:** `app/Traits/Auditable.php`

**Code:**

```php
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            app(AuditLogger::class)->log('created', $model, [], $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->getOriginal(), $changes);
            app(AuditLogger::class)->log('updated', $model, $old, $changes);
        });

        static::deleted(function ($model) {
            app(AuditLogger::class)->log('deleted', $model, $model->getAttributes(), []);
        });
    }
}
```

**Line-by-line explanation:**

* A **trait** is reusable code you drop into a class with `use Auditable;`. It is
  used by `User`, `Role`, `Permission` and `LeaveRequest`.
* `bootAuditable()` → Laravel automatically calls any method named `boot<TraitName>`
  when the model starts up. That is how this wires itself in with no extra code.
* `static::created(...)`, `static::updated(...)`, `static::deleted(...)` → **model
  events**. Laravel fires these after every save/update/delete, so the audit trail
  cannot be forgotten by a developer writing a new controller.
* `unset($changes['updated_at']); if ($changes === []) return;` → every save touches
  the timestamp. Without this, saving a record with no real change would still write
  a meaningless audit row.
* `array_intersect_key($model->getOriginal(), $changes)` → keeps only the *old*
  values of columns that actually changed, so the audit row is a tidy before/after
  pair rather than a dump of the whole record.

**File:** `app/Services/Security/AuditLogger.php`

**Code:**

```php
private const REDACTED_KEYS = [
    'password', 'password_confirmation', 'current_password',
    'token', 'remember_token', 'otp', 'code', 'code_hash', 'secret',
];

public function log(
    string $action,
    ?Model $auditable = null,
    array $oldValues = [],
    array $newValues = [],
    ?User $actor = null,
): AuditLog {
    $actor ??= Auth::user();
    $request = app()->runningInConsole() ? null : request();

    return AuditLog::create([
        'user_id' => $actor?->id,
        'role_snapshot' => $actor ? app(\App\Services\Rbac\RbacService::class)->userRoleSlugs($actor)->implode(',') : null,
        'action' => $action,
        'auditable_type' => $auditable ? $auditable::class : null,
        'auditable_id' => $auditable?->getKey(),
        'old_values' => $this->redact($oldValues),
        'new_values' => $this->redact($newValues),
        'ip' => $request?->ip(),
        'user_agent' => substr((string) $request?->userAgent(), 0, 500),
        'url' => substr((string) $request?->fullUrl(), 0, 255),
    ]);
}

private function redact(array $values): ?array
{
    if ($values === []) {
        return null;
    }
    foreach ($values as $key => $value) {
        if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
            $values[$key] = '[REDACTED]';
        }
    }

    return $values;
}
```

**Line-by-line explanation:**

* `$actor ??= Auth::user();` → if the caller did not name an actor, use whoever is
  signed in. Allowing it to be passed in matters at login time, when the session is
  not yet fully established.
* `app()->runningInConsole() ? null : request()` → a scheduled command has no HTTP
  request, so IP and browser are simply null rather than crashing.
* **`'role_snapshot' => ... userRoleSlugs($actor)->implode(',')`** → records **the
  roles the person held at that moment**, e.g. `"hr,employee"`. If their role changes
  next year, the old audit rows still show the authority under which they acted. This
  is exactly the kind of detail an auditor asks for.
* `'auditable_type' => $auditable::class` and `'auditable_id' => ...->getKey()` →
  a **polymorphic** pointer: these two columns together can refer to a row in *any*
  table (a user, a role, a leave request).
* `$this->redact(...)` → **passwords, tokens, OTP codes and hashes are replaced with
  `[REDACTED]` before being written.** An audit log that recorded password changes in
  full would itself be a vulnerability.
* `substr(..., 0, 255)` → truncate long URLs to fit the column.

**Role/Permission involved:** Written for everyone; read by the **System
Administrator** (`audit.view`, route `audit.index`). Each person reads their own rows
through `audit.view-own` (route `my-audit-log`), which takes no ID at all.

---

## SECTION 29 — Reports: a permission per report

**File:** `app/Services/Reports/ReportService.php`

**Code:**

```php
/**
 * The permission is the point. Before this every report was gated on
 * [reports.generate], which an account with no leave permission at all
 * could open, and export... each entry names the permission its *subject*
 * requires, not merely the right to run a report.
 */
const CATALOGUE = [
    'employee-leave' => [..., 'permission' => 'leave.requests.view-all', 'group' => 'leave', ...],
    'department'     => [..., 'permission' => 'reports.department',      'group' => 'department', ...],
    'intrusion'      => [..., 'permission' => 'reports.security',        'group' => 'security', ...],
    ...
];

public static function visibleTo(User $user): array
{
    $groups = array_fill_keys(array_keys(self::GROUPS), []);

    // Heading an office is a fact about the record, not a permission. A
    // head who is not named on any department would otherwise be offered
    // three reports that then refuse...
    $office = self::officeHeadedBy($user);

    foreach (self::CATALOGUE as $key => $report) {
        if (! $user->hasPermission($report['permission'])) {
            continue;
        }
        if ($report['group'] === 'department' && $office === null) {
            continue;
        }
        $groups[$report['group']][$key] = $report;
    }

    return array_filter($groups);
}
```

**Line-by-line explanation:**

* **Two permissions guard every report**: `reports.generate` on the route, and the
  report's own `permission` naming what its *subject* requires. So the System
  Administrator holds `reports.generate` but not `leave.requests.view-all`, and
  therefore sees the four security reports and none of the six leave ones. The
  Mayor is the mirror image.
* `array_fill_keys(array_keys(self::GROUPS), [])` → pre-creates the groups in their
  declared order, so the page always reads in the same order.
* `if ($report['group'] === 'department' && $office === null) continue;` → the same
  rule as the dashboard: the **role** gets you the report, the **department record**
  decides whether there is anything behind it. A head not named on any department is
  simply not offered the three department reports.
* `array_filter($groups)` → drop groups that ended up empty.

**Role/Permission involved:**

| Role | Reports offered |
|---|---|
| HR | the six leave reports (`leave.requests.view-all` + `reports.generate`) |
| Mayor | the same six leave reports |
| Department Head | the three department reports — **only if named as head of an office** |
| System Administrator | the four security reports only |
| Employee | none (no `reports.generate`) |

---

## SECTION 30 — The API surface

**File:** `routes/api.php`

**Code:**

```php
Route::prefix('v1')->name('api.')->group(function () {
    Route::post('auth/login', [AuthApiController::class, 'login'])->name('auth.login');
    Route::post('auth/otp/verify', [AuthApiController::class, 'verifyOtp'])->name('auth.otp');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthApiController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthApiController::class, 'logout'])->name('auth.logout');

        Route::get('security/alerts', [SecurityApiController::class, 'alerts'])->name('security.alerts');
        Route::get('security/stats', [SecurityApiController::class, 'stats'])->name('security.stats');
    });
});

// Session-authenticated alert polling for the web UI bell...
// the bell asks every fifteen seconds, or four times a minute, and sixty is
// well clear of that while still bounding what a stuck or tampered-with tab
// can ask for.
Route::middleware(['web', 'auth', 'throttle:60,1'])
    ->get('/internal/security/alerts', [SecurityApiController::class, 'alerts'])
    ->name('web.security.alerts');
```

**Line-by-line explanation:**

* `Route::prefix('v1')` → every URL starts `/api/v1/...`. Versioning means a future
  v2 can change shape without breaking existing clients.
* `->name('api.')` → prefixes every route name, so they are `api.auth.login` etc.
* `auth/login` and `auth/otp/verify` are **outside** the auth middleware — you
  cannot require a token to get a token.
* `Route::middleware('auth:sanctum')` → everything else requires a **Sanctum API
  token** (a bearer token), not a browser session.
* `throttle:60,1` on the internal bell route → 60 requests per minute. The comment
  explains the arithmetic: the bell polls every 15 seconds (4/minute), so 60 is
  generous for honest use while still bounding a stuck or tampered-with tab. This
  route is also the one the IDS exempts from rate *counting* (`isRateExempt`) —
  because the system polling itself is not evidence of an attack — so it carries a
  real throttle instead.

---

# PART 5 — ROLES AND THEIR FUNCTIONS

## The master table

| Role | Where Found in Code | What It Means | Functions / Permissions |
|---|---|---|---|
| **Employee** | `app/Models/Role.php:ASSIGNABLE[0]` (slug `employee`); created in `database/seeders/RolePermissionSeeder.php::run()`; permissions = `EMPLOYEE_BASELINE` | A regular LGU staff member who files and tracks their own leave | `dashboard.view`, `leave.apply`, `leave.view-own`, `leave.cancel`, `audit.view-own` |
| **Department Head** | `Role.php:ASSIGNABLE[1]` (slug `department-head`); seeder `$deptHead`, `parent_id = employee` | Head of an office. Sees their office's leave; **approves none of it** | baseline **+** `leave.review.department`, `reports.generate`, `reports.department` |
| **HR** | `Role.php:ASSIGNABLE[2]` (slug `hr`); seeder `$hr`, `parent_id = employee` | Human Resources. The **only** role that decides leave | baseline **+** `employees.view/manage/view-salary`, `departments.manage`, `positions.manage`, `holidays.manage`, `leave.requests.view-all`, `leave.balances.manage`, `leave-types.manage`, `leave.certify.hr`, **`leave.approve.final`**, `reports.generate` |
| **Municipal Mayor** | `Role.php:ASSIGNABLE[3]` (slug `mayor`); seeder `$mayor`, `parent_id = employee` | Oversees leave across the LGU; signs the printed form as head of agency | baseline **+** `leave.requests.view-all`, `reports.generate` |
| **System Administrator** | `Role.php:ASSIGNABLE[4]` (slug `system-admin`); seeder `$sysAdmin`, **no parent** | Runs the system: accounts, devices, security, settings, backups | `dashboard.view`, `users.manage/block/reset-password/assign-roles/history`, `rbac.manage`, `settings.manage`, `devices.manage`, `security.dashboard/blocked-ips/intrusions`, `audit.view`, `activity.view`, `backup.run`, `reports.generate`, `reports.security` |

**How the system determines which role a user has**

1. The administrator ticks roles on `admin/users/form` → `UserController::store()`
   or `update()`, validated against `Role::ASSIGNABLE` by `roleRules()`.
2. `RbacService::syncUserRoles()` writes rows into the `role_user` table and bumps
   the RBAC cache version.
3. On each request, `RbacService::effectivePermissions($user)` reads `role_user`,
   expands each role through `rolePermissionMap()` (which resolves the `parent_id`
   inheritance chain), adds any `permission_user` rows of type `allow`, and subtracts
   any of type `deny`.
4. `PermissionMiddleware` (and the sidebar, dashboard and workflow) then ask
   `$user->hasPermission('some.slug')`.

**Nothing in the application asks "is this person HR?"** — every decision asks
"does this person hold this permission?"

---

## Role 1 — Employee

**Where:** slug `employee`, `Role::ASSIGNABLE[0]`. Permissions granted at
`RolePermissionSeeder::run()` → `$grant($employee, self::EMPLOYEE_BASELINE);`

**What it means:** anyone on the payroll. It is also the **parent role** of
Department Head, HR and Mayor, so all three automatically inherit everything here.

**What they can do:**

| Action | Permission | Where enforced |
|---|---|---|
| Open their dashboard | `dashboard.view` | `DashboardService::forUser()` → `ownPane()` |
| File a leave application | `leave.apply` | `routes/leave.php` → `LeaveRequestController::store()` |
| View their own applications, credits, history | `leave.view-own` | `LeaveRequestController::index()` / `authorizeView()` |
| Print their CSC Form 6 | `leave.view-own` | `LeaveRequestController::form6()` |
| Upload supporting documents | `leave.view-own` + strict ownership | `uploadDocument()` — `abort_unless($leaveRequest->user_id === $request->user()->id, 403)` |
| Cancel their own pending application | `leave.cancel` | `cancel()` — same strict ownership check |
| Keep a signature for the form | `leave.view-own` | `SignatureController`, routes with **no ID in the path** |
| Read their own audit trail | `audit.view-own` | `MyAuditController` — scope from the session |

**What they cannot do:** see anybody else's leave (`authorizeView()` aborts 403),
approve anything, open any admin page.

---

## Role 2 — Department Head

**Where:** slug `department-head`, `parent_id = employee`. Granted at
`$grant($deptHead, [...self::EMPLOYEE_BASELINE, 'leave.review.department', 'reports.generate', 'reports.department'])`.

**What it means:** the head of an office. The seeder's own description is *"Sees
leave filed in own department; approves none of it."*

**The critical two-layer rule:** the **permission** says they may review *a*
department. The **database record** `departments.head_user_id` says *which one*.
Both are always checked:

```php
// ApprovalWorkflowService::canRecommend()
if (! $user->hasPermission(self::DEPARTMENT_PERMISSION)) return false;  // layer 1
if ($user->id === $request->user_id) return false;                      // no self
$officeId = $request->user?->employeeProfile?->department_id;
return $officeId !== null
    && $user->headsDepartments()->whereKey($officeId)->exists();        // layer 2
```

**What they can do:**

| Action | Where |
|---|---|
| Everything an Employee can (inherited) | via `parent_id` |
| Be **notified** when their staff file leave | `ApprovalWorkflowService::initialize()` → `DepartmentLeaveFiledNotification` |
| Have their name snapshotted onto box 7.B | `Approval` row, `ACTION_NOTIFIED`, `'signature' => $head->name` |
| View their own office's applications | `LeaveRequestController::authorizeView()`, third branch (departments must match) |
| See a department pane on their dashboard | `DashboardService::forUser()` → `departmentPane()` |
| See Leave Rankings for their office | `RankingController` — scope from the department record |
| Run the three department reports | `ReportService::visibleTo()` — **only if named as head of an office** |
| Write a **recommendation** on box 7.B | `ApprovalController::recommend()` → `ApprovalWorkflowService::recommend()` |

**What they cannot do — and how it is enforced three times over:**

* Not in the menu — `config/menu.php` gates Leave Approvals on `leave.approve.final`.
* Not on the route — `routes/leave.php` gates `review.index` and `review.act` on the
  same permission.
* Not in the service — `ApprovalWorkflowService::act()` calls `canDecide()` again:
  *"A department head reaching this — from a stale queue page, or by posting the
  route directly — is refused here rather than merely being absent from a list."*
* They also cannot reach another office's applications: `canRecommend()` and
  `authorizeView()` both compare department IDs.

---

## Role 3 — HR

**Where:** slug `hr`, `parent_id = employee`. Granted the longest permission list in
`RolePermissionSeeder::run()`.

**What it means:** the operational core, and **the only role that can decide a leave
application**.

**What they can do:**

| Action | Permission | Where |
|---|---|---|
| Everything an Employee can | inherited | `parent_id` |
| **Approve / disapprove / return** an application | `leave.approve.final` | `ApprovalController::act()` → `ApprovalWorkflowService::act()` |
| See the approval queue | `leave.approve.final` | `ApprovalController::queue()` |
| Print a **blank** CSC Form 6 | `leave.approve.final` | `LeaveRequestController::blankForm6()` |
| Certify credit balances onto the decision | `leave.certify.hr` | `ApprovalController::certification()` |
| Read every application in the LGU | `leave.requests.view-all` | `LeaveRequestController::all()`, `authorizeView()` |
| Manage employee records incl. salary | `employees.view/manage/view-salary` | `EmployeeController`, `UserController` profile fields |
| Manage departments, positions, holidays | `departments.manage`, `positions.manage`, `holidays.manage` | `DepartmentController`, `PositionController`, `HolidayController` |
| Adjust leave balances (reason required) | `leave.balances.manage` | `BalanceController::adjust()` → `LeaveCreditService::adjust()` |
| Configure leave types and policies | `leave-types.manage` | `LeaveTypeController` |
| Run the six leave reports | `reports.generate` + `leave.requests.view-all` | `ReportService::visibleTo()` |
| See the whole-LGU management dashboard | `leave.approve.final` | `DashboardService::forUser()` → `managementPane()` |

**What they cannot do:**

* Approve **their own** leave — `act()` refuses before it even checks permissions:
  *"An employee may never act on their own application, whatever else they hold."*
* Change an already-decided application — `isFinal()` refuses.
* Approve beyond somebody's credits — `deductForApproval()` throws.
* Touch users, roles, settings, devices, backups, security logs — no permission.
* Run the four security reports — no `reports.security`.

---

## Role 4 — Municipal Mayor

**Where:** slug `mayor`, `parent_id = employee`. Granted exactly two extra
permissions: `leave.requests.view-all` and `reports.generate`.

**What it means:** **oversight, not decision.** The seeder comment is unusually
direct:

> The Mayor OVERSEES leave; HR decides it. No `leave.approve.final`, so the Leave
> Approvals queue is not merely hidden from the Mayor — the route guard and
> `ApprovalWorkflowService::canDecide()` both refuse them, which is the only version
> of this that means anything.
>
> The Mayor's signature has not left the process. It is at the foot of the printed
> CSC Form No. 6, as head of agency — which is where it belongs on that form.

**What they can do:** everything an Employee can (they file their own leave), plus
read **every** application in the LGU (`leave.all`), plus run the six leave reports,
plus sign the printed form as head of agency.

**What they cannot do:** decide any application. Note the deliberate design detail in
`DashboardService::forUser()` — the management pane is gated on `leave.approve.final`
rather than `leave.requests.view-all` **specifically so the Mayor does not get it**:

> Seeing everything and being answerable for everything are different jobs; this gate
> follows the second one.

---

## Role 5 — System Administrator

**Where:** slug `system-admin`, **no `parent_id`** — it does not inherit the employee
baseline.

**What it means:** operates the system rather than working in the LGU. This is the
bootstrap account created by `CoreUserSeeder`:

```php
$admin = User::updateOrCreate(['email' => 'superadmin@alicia.gov.ph'], [
    'name' => 'System Administrator',
    'username' => 'superadmin',
    'password' => Hash::make(env('SEED_SUPERADMIN_PASSWORD', 'ChangeMe!Alicia2026')),
    'must_change_password' => true,
    ...
]);
$admin->roles()->syncWithoutDetaching(Role::where('slug', 'system-admin')->first());
```

**What they can do:**

| Action | Permission |
|---|---|
| Create, edit, archive, restore accounts | `users.manage` |
| Block / unblock accounts by hand | `users.block` |
| Reset passwords to the first-time password | `users.reset-password` |
| Assign roles and per-user overrides | `users.assign-roles` |
| View anybody's login / audit / activity history | `users.history` |
| Edit what each role may do | `rbac.manage` |
| Change system settings (lockout, OTP TTL, IDS, accrual rates) | `settings.manage` |
| Manage the LAN device allow-list | `devices.manage` |
| Security dashboard, blocked IPs, intrusion logs | `security.*` |
| Read the whole audit and activity logs | `audit.view`, `activity.view` |
| Create and download backups | `backup.run` |
| Run the four security reports | `reports.security` |

**What they cannot do — the separation of duties that makes this design serious:**

* **No leave permission at all.** They cannot see anybody's leave records, balances,
  or applications. The person who runs the servers cannot read the Mayor's sick leave.
* **No wildcard.** `RolePermissionSeeder` ends: *"No role holds `*` … there is now no
  permission anywhere that satisfies every check."* `RoleController::NEVER_ASSIGNABLE`
  refuses to grant it.
* **Cannot create a sixth role.** `routes/admin.php` gives `RoleController` only
  `index`, `edit`, `update`, `destroy` — no `create`, no `store`.
* **Cannot delete the five roles.** `destroy()` refuses anything `is_system`.
* **Cannot promote themselves.** `UserController::update()` skips roles on their own
  account; `updateAccess()` refuses outright.
* **Cannot permanently delete a user.** There is no delete route — only archive.
* Their `/dashboard` redirects to the Security Dashboard, because they hold no leave
  permission and the page would otherwise be an empty frame.

---

# PART 6 — HOW THE FILES CONNECT

## Following one leave application end to end

```
1. resources/views/leave/create.blade.php     employee fills the form
        │  POST /leave  (CSRF token attached by Blade)
        ▼
2. bootstrap/app.php → BlockedIp → AuthorizedDevice → IntrusionDetection
        │  free-text fields judged by the GENTLE rules, so "Family emergency --
        │  urgent" is not read as SQL
        ▼
3. routes/leave.php   permission:leave.apply
        │  → PermissionMiddleware → User::hasPermission() → RbacService
        ▼
4. LeaveRequestController::store()            validates the form
        ▼
5. LeaveApplicationService::submit()          the rules
        ├── WorkingDayCalculator::countFor()  working vs calendar days
        │        └── Holiday model            (calendar maintained by HR)
        ├── LeavePolicyEngine::validate()     CSC rules, notice periods, documents
        ├── LeaveCreditService::hasSufficientCredits()
        ├── DB::transaction {
        │      LeaveRequest::create()         + office/position/salary snapshots
        │      snapshotSignature()            copies the signature file by request id
        │      ApprovalWorkflowService::initialize()
        │           ├── Approval step 0  ACTION_NOTIFIED   (head's name snapshot)
        │           ├── Approval step 1  ACTION_PENDING    (HR's queue entry)
        │           ├── LeaveStatusNotification  → applicant
        │           └── DepartmentLeaveFiledNotification → head
        │      AuditLogger::log('leave_submitted')
        │   }
        ▼
6. redirect → GET /leave/{id}  → authorizeView() → leave/show.blade.php
```

Then, separately:

```
7. Department Head opens their dashboard
        DashboardService::departmentPane()    scoped by departments.head_user_id
   (optionally) POST /leave/{id}/recommend
        permission:leave.review.department    ← layer 1
        ApprovalWorkflowService::canRecommend() ← layer 2 (do they head THAT office?)
        → Approval row becomes RECOMMENDED / NOT_RECOMMENDED
        → status and current_step are NOT touched: HR still decides

8. HR opens GET /review        permission:leave.approve.final
        ApprovalController::queue()
   POST /review/{id}/act
        ApprovalController::act()
        └── ApprovalWorkflowService::act()
              gate 1  isFinal()?                already decided → refuse
              gate 2  own application?          refuse
              gate 3  canDecide()?              permission re-checked
              gate 4  a pending step exists?
              DB::transaction {
                 lockForUpdate() on the approval row   ← two officers at once
                 update the Approval (approver, signature, certified balances)
                 update the LeaveRequest (status, pay split, decided_at)
                 LeaveCreditService::deductForApproval()
                      lockForUpdate() on the balance   ← concurrency safety
                      idempotency guard                ← never deduct twice
                      never-negative guard
                      LeaveHistory ledger row
                 AuditLogger::log('leave_approved')
                 LeaveStatusNotification → applicant
              }
```

## The permission lookup path (used by everything)

```
route  'permission:leave.approve.final'
   → PermissionMiddleware::handle()
       → User::hasPermission($slug)
           → RbacService::userHasPermission()
               → RbacService::effectivePermissions()
                    cache key  rbac.user.{id}.v{version}
                    ├── DB role_user              which roles
                    ├── rolePermissionMap()       roles + inherited parents
                    │        cache key  rbac.map.v{version}
                    ├── DB permission_user 'allow'   individual grants
                    └── DB permission_user 'deny'    subtracted LAST
   → allowed  → $next($request)
   → denied   → IntrusionLog(category: 'privilege') + abort(403)
```

Any write through `RbacService` (`syncUserRoles`, `syncRolePermissions`,
`grantUserPermission`, `revokeUserPermission`) calls `bumpVersion()`, which changes
the `v{version}` part of every cache key at once — so a role change takes effect on
the very next request.

## Which file connects to which

| This file | Connects to | How |
|---|---|---|
| `bootstrap/app.php` | every middleware | registers and orders them; defines the `permission` alias |
| `routes/web.php` | `routes/leave.php`, `routes/admin.php` | `require` inside the `auth + otp.verified + force.pwchange` group |
| `routes/*.php` | `PermissionMiddleware` | `->middleware('permission:slug')` |
| `PermissionMiddleware` | `RbacService` | via `User::hasPermission()` |
| `RbacService` | tables `role_user`, `permission_role`, `permission_user`, `roles` | direct query-builder reads |
| `RolePermissionSeeder` | `Role`, `Permission` + pivot tables | creates the five roles and their grants |
| `Role::ASSIGNABLE` | `UserController::roleRules()`, `Role::scopeAssignable()` | the list of roles that may be offered and accepted |
| `config/menu.php` | `partials/sidebar.blade.php` | `$itemVisible()` calls `hasPermission()` per entry |
| `DashboardController` | `DashboardService::forUser()` | picks panes by permission |
| `LeaveRequestController` | `LeaveApplicationService` | `store()` delegates all rules |
| `LeaveApplicationService` | `WorkingDayCalculator`, `LeavePolicyEngine`, `LeaveCreditService`, `ApprovalWorkflowService`, `AuditLogger` | injected in the constructor |
| `ApprovalController` | `ApprovalWorkflowService` | `act()` and `recommend()` delegate |
| `ApprovalWorkflowService` | `LeaveCreditService`, `AuditLogger`, notifications | deducts, audits and notifies on approval |
| `departments.head_user_id` | `User::headsDepartments()`, `canRecommend()`, `departmentPane()`, `ReportService::officeHeadedBy()` | decides **which** office a head is scoped to |
| `Auditable` trait | `AuditLogger` | model events fire automatically on create/update/delete |
| `LoginSecurityService` | `IntrusionLog`, `AuditLogger`, `SecurityAlerter` | a lockout is logged as an intrusion and alerted |
| `IntrusionDetectionService` | `BlockedIp`, `SystemSetting`, `SecurityAlerter` | auto-blocks and alerts |

## The repeated patterns, and why they repeat

Some checks look duplicated. Each repetition is deliberate:

1. **Permission checked on the route AND in the service.**
   `review.act` carries `permission:leave.approve.final`, and
   `ApprovalWorkflowService::act()` calls `canDecide()` again. The queue page and
   the decision are two separate requests, and only the second changes data.

2. **Credits checked at filing AND at approval.**
   `LeaveApplicationService::submit()` and `LeaveCreditService::deductForApproval()`
   both check. Time passes between filing and approval, and credits may be spent
   elsewhere in between.

3. **Pending status checked before AND inside the transaction.**
   The first is cheap; the second re-reads the row under `lockForUpdate()` so two HR
   officers clicking at the same instant cannot both decide.

4. **Self-action refused in several places.**
   `canRecommend()`, `act()`, `UserController::update()`, `updateAccess()`. Each is a
   different kind of self-dealing: recommending on your own leave, approving your own
   leave, promoting your own account, widening your own permissions.

5. **The wildcard blocked twice.** Hidden from the role form *and* refused on
   submission — "hiding a control is not access control."

6. **Ownership checked on the route AND in the controller.** The route permission
   proves you may look at leave pages; `authorizeView()` proves *this* application is
   one you may see. The ID is in the URL, where anyone can change it.

7. **Trusted IPs checked in two middleware.** `BlockedIpMiddleware` and
   `IntrusionDetectionService::maybeAutoBlock()` both call `isTrustedIp()`, so the
   server can neither ban itself nor be banned.

---

## One-paragraph summary

Authority in this system is data, not code. The five roles live in
`Role::ASSIGNABLE` and are created by `RolePermissionSeeder`; what each may do is a
list of permission slugs in that same seeder; `RbacService` resolves a person's
effective permissions from their roles, their roles' parents, and their individual
allow/deny overrides; and every guard in the application — route middleware, sidebar
entries, dashboard panes, report catalogue and the approval workflow — asks only
`hasPermission('some.slug')`. Where a permission is not specific enough — *which*
department may this head see? — the answer comes from a database record
(`departments.head_user_id`), never from anything in the request. That is why the
Mayor's authority to approve leave could be withdrawn by deleting a single line from
a seeder array, and why a Department Head who posts the approval URL directly is
refused by the service and not merely left off a menu.
