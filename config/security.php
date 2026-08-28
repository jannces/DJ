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
];
