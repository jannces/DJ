<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Routes the rate detector does not count
    |--------------------------------------------------------------------------
    |
    | The IDS watches how many requests an address makes in a minute. The
    | system's own polling should not be part of that count: the notification
    | bell asks for new alerts every fifteen seconds on every open tab, which
    | is the system talking to itself, not a person hammering the server.
    |
    | This exempts those requests from *counting only*. They are still scanned
    | for signatures like every other request, and the route carries a real
    | throttle -- a bound on what it will serve, rather than a detector that
    | reports what it already served.
    |
    | Paths, not route names: the IDS is global middleware and runs before the
    | router has resolved anything, so there is no route to name yet. Anything
    | an address outside the LGU could reach belongs nowhere near this list.
    |
    */

    'rate_exempt_paths' => [
        'api/internal/security/alerts',
    ],

    /*
    |--------------------------------------------------------------------------
    | The first-time password
    |--------------------------------------------------------------------------
    |
    | Every account is created with this, and every administrator password
    | reset returns to it. The employee is then held on the change-password
    | screen at their first sign-in until they set their own -- see the
    | ForcePasswordChange middleware -- so nobody keeps it.
    |
    | It replaced a random fourteen-character password shown once in a flash
    | message. That was stronger on paper and worse in practice: the message
    | disappeared on the next page, and a password nobody can read back is a
    | password that gets written on paper or asked for again.
    |
    | It is not a secret and must not be treated as one. Anyone who knows a
    | username and this word has the first half of a sign-in. The second half
    | is the one-time code, which is emailed to the employee's own address
    | (auth.otp_enabled, on by default) -- so keep that setting on, or this
    | word is the only thing standing between a name and an account.
    |
    | Set FIRST_PASSWORD in .env to change it for a deployment. Changing it
    | affects accounts created from then on; it does not alter one already
    | issued, because the stored value is a hash and there is nothing to
    | rewrite.
    |
    */

    'first_password' => env('FIRST_PASSWORD', 'OneAlicia2026'),
];
