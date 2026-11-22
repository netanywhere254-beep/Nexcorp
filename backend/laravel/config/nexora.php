<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Organization invitations
    |--------------------------------------------------------------------------
    |
    | Invitations are delivered by email and expire after this many days.
    | Keep the window short; an administrator can always issue a new one.
    |
    */
    'invitation_ttl_days' => max(1, min(30, (int) env('NEXORA_INVITATION_TTL_DAYS', 7))),
];
