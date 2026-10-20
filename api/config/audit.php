<?php

return [

    /*
    | Audit log entries older than this many days are deleted by the daily
    | model:prune run. 0 keeps them forever.
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

];
