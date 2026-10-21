<?php

return [

    /*
    | Platform admin routes need a token that passed a two-factor code within
    | this many minutes (login with a code, or reconfirming one).
    */

    'two_factor_ttl_minutes' => (int) env('ADMIN_TWO_FACTOR_TTL_MINUTES', 720),

    /*
    | Admin actions (suspending a user, resetting their two-factor, ...) need a
    | fresher code than lookups: within this many minutes.
    */

    'action_two_factor_ttl_minutes' => (int) env('ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES', 15),

    /*
    | A read-only "view as user" session (StartImpersonation) ends after this
    | many minutes. It isn't renewed: the admin starts a new one.
    */

    'impersonation_minutes' => (int) env('ADMIN_IMPERSONATION_MINUTES', 30),

];
