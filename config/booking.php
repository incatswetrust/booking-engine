<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active user window
    |--------------------------------------------------------------------------
    |
    | §64: a user is considered "active" (for Platform Admin's binary
    | Active/Inactive indicator) when last_activity_at falls within this
    | many days of now.
    |
    */

    'active_user_window_days' => (int) env('ACTIVE_USER_WINDOW_DAYS', 30),

];
