<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone new users, teams, and availability schedules start in, and
    | the fallback used when displaying a time for someone who has not chosen
    | one. Timestamps are always stored in UTC; this only affects what people
    | see and what a new schedule is written in.
    |
    */

    'default_timezone' => env('SCHEDULING_TIMEZONE', 'America/Chicago'),

    /*
    |--------------------------------------------------------------------------
    | Default Holiday Country
    |--------------------------------------------------------------------------
    |
    | New users start marked unavailable on this country's public holidays.
    | Set to null to have nobody opt in by default. Individuals can turn any
    | holiday off under Availability, Advanced settings.
    |
    */

    'default_holiday_country' => env('SCHEDULING_HOLIDAY_COUNTRY', 'US'),

    /*
    |--------------------------------------------------------------------------
    | Reminder Lead Times
    |--------------------------------------------------------------------------
    |
    | How long before a booking starts, in minutes, invitees and hosts are
    | reminded about it.
    |
    */

    'reminder_lead_times' => [24 * 60, 60],

    /*
    |--------------------------------------------------------------------------
    | Minimum Notice Floor
    |--------------------------------------------------------------------------
    |
    | The least notice, in minutes, any booking can be made with. An event
    | type may ask for more, never less: a slot starting sooner than this is
    | not offered, so a host is never booked for a meeting minutes away.
    |
    */

    'minimum_notice_floor_minutes' => 15,

];
