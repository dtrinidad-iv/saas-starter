<?php

return [

    /*
    | Platform admin routes need a token that passed a two-factor code within
    | this many minutes (login with a code, or reconfirming one).
    */

    'two_factor_ttl_minutes' => (int) env('ADMIN_TWO_FACTOR_TTL_MINUTES', 720),

];
