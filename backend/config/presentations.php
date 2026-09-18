<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Open to members
    |--------------------------------------------------------------------------
    |
    | While this is false, the whole feature is admin-only: the Presentations
    | item is hidden from the member sidebar and the member routes are closed to
    | anyone who is not an admin. Admins still get the full member-side
    | experience, so the flow can be rehearsed end to end before anyone else
    | sees it.
    |
    | Guest pages are deliberately NOT gated. A test guest is an ordinary person
    | with a link and no account, and gating them would make the thing
    | untestable — a showing is only reachable by someone who was given its URL.
    |
    | Flip to true to release it.
    |
    */

    'open_to_members' => (bool) env('PRESENTATIONS_OPEN_TO_MEMBERS', false),

    /*
    |--------------------------------------------------------------------------
    | Booking timezone
    |--------------------------------------------------------------------------
    |
    | Everything is scheduled in Eastern time: an admin types 7:00 PM, and 7:00
    | PM is what the company means. Timestamps are still STORED in UTC — this
    | only decides how they are read in and written out.
    |
    | Note this is America/New_York, not a fixed "EST". Literal EST is UTC-5 all
    | year, but the east coast is on EDT (UTC-4) from March to November, so a
    | fixed offset would make every summer showing an hour off from what a clock
    | in New York actually reads. The zone handles the switch, and the label
    | shown to people follows it — EST in winter, EDT in summer.
    |
    */

    'timezone' => env('PRESENTATIONS_TIMEZONE', 'America/New_York'),

    /*
    |--------------------------------------------------------------------------
    | Members running their own showings
    |--------------------------------------------------------------------------
    |
    | Lets a member schedule a recording an admin has released for the purpose,
    | and get their own link for their own team. Separate from
    | `open_to_members`: a member can be allowed to *use* presentations without
    | being allowed to *create* them.
    |
    | The cap is per member and counts only showings still ahead of them. It is
    | there so one enthusiastic person cannot fill the calendar with a thousand
    | rooms; the number is generous for anybody working normally.
    |
    */

    'members_can_schedule' => (bool) env('PRESENTATIONS_MEMBERS_CAN_SCHEDULE', true),

    'member_upcoming_limit' => (int) env('PRESENTATIONS_MEMBER_LIMIT', 20),

    /*
    |--------------------------------------------------------------------------
    | Pre-roll window
    |--------------------------------------------------------------------------
    |
    | How long before the scheduled start the video file becomes fetchable, so
    | a waiting guest's browser can buffer the opening and playback begins
    | cleanly rather than spinning.
    |
    | This is a deliberate, narrow relaxation of "no video before the start".
    | Somebody who grabs the file two minutes early gains essentially nothing,
    | and the alternative is every guest watching a loading spinner at the
    | moment the room fills. Widen it only with that trade in mind.
    |
    */

    'preload_seconds' => (int) env('PRESENTATIONS_PRELOAD_SECONDS', 120),

    /*
    | How far either side of "now" the unified console keeps a room open —
    | about to start, or only just finished. A conversation should not vanish
    | the moment a call ends, because that is exactly when the follow-up happens.
    */

    'console_window_minutes' => (int) env('PRESENTATIONS_CONSOLE_WINDOW', 30),

    /*
    |--------------------------------------------------------------------------
    | Default call to action
    |--------------------------------------------------------------------------
    |
    | What a new showing asks its guests to do, unless the admin picks
    | otherwise. 'join' sends guests to the partner sign-up at /join/{code},
    | credited to the partner who invited them.
    |
    */

    'default_cta' => env('PRESENTATIONS_DEFAULT_CTA', 'join'),

    /*
    | How long after a guest's last heartbeat we still call them "watching".
    | Comfortably more than the 20s beat so one dropped request does not make
    | somebody vanish from their sponsor's panel.
    */
    'presence_window_seconds' => 60,

];
