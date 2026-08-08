<?php
// One-time seed for the launch_roster table (Launch Schedule / Launch
// Coaching tabs), imported 2026-07-30 from two sources Darren provided:
//   1. "My notes _ Lead Team, Coaches, etc - New Agents.csv" — a coaching
//      roster where columns were used inconsistently between its two
//      blocks (the first block's 3rd column is a real LAUNCH TRUE/FALSE
//      flag; the second, unheaded block reuses that column for freeform
//      status text instead). Reproduced as notes rather than guessed at.
//   2. The August 10, 2026 LAUNCH start-date list (agents starting when
//      LAUNCH's cadence moves to 2x/week).
//
// Reconciliation done by hand against both lists — flagged inline so it's
// easy to spot-check and correct in the Launch Coaching tab once live:
//   - Madison Baldwin appeared twice in the CSV (as a new recruit, and
//     later as "on Dan's team, did not participate due to being a
//     teacher"); merged into a single row.
//   - "Rosie Yardonova" (CSV) / "Rosie Yoranova" / "Rosie Yordanova" (Aug
//     10 list, two different spellings) treated as the same person —
//     spelled "Rosie Yordanova" here since that's the more common form
//     in the Aug 10 list. Verify with Darren.
//   - Kristie Wright is tagged "Market Leader" in the CSV — kept on the
//     roster but flagged, since market leaders don't typically run
//     through LAUNCH.
//   - Shawn Gentile's CSV "Coach" column literally read "Not able to
//     attend", which isn't a coach name — moved to notes instead of
//     invented as a coach.
//   - Six names (Madison Johnson, Anna VanDuzer, Kate Brown, Lauren
//     Carra, Samantha Harrison, Dave Snyder) only appear on the Aug 10
//     list, not in the CSV — added as new rows with no coach/notes.
// local_db.php only runs this if launch_roster is empty, so edits made
// afterward via the Launch Coaching page are never overwritten.
function launch_roster_seed(): array {
    $aug10 = '2026-08-10';
    return [
        ['agent_name' => 'Madison Baldwin',    'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => '',      'notes' => "New agent recruited to be on Dan's team. Teacher, was waiting for Summer for LAUNCH. Later noted as: on Dan's team, did not participate in LAUNCH when she joined due to being a teacher."],
        ['agent_name' => 'Matt Gorham',        'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => '',      'notes' => 'New agent recruited to be on Dan\'s team. Active agent in NJ.'],
        ['agent_name' => 'Kristie Wright',     'state' => 'NC', 'office' => 'TRIAD',          'start_date' => '',      'notes' => 'Market Leader — flag for review, may not need to run through LAUNCH.'],
        ['agent_name' => 'Cliff Todd',         'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => '',      'notes' => 'Reactivating agent. Not sure his history.'],
        ['agent_name' => 'Nicole LaRue',       'state' => 'NJ', 'office' => 'Chester',        'start_date' => '',      'notes' => 'Active agent in Delaware. Just licensed in NJ.'],
        ['agent_name' => 'Veronica Smyth',     'state' => 'SC', 'office' => 'Conway',         'start_date' => $aug10,  'notes' => "Originally noted as 'will begin LAUNCH in Aug' — now scheduled for the Aug 10, 2026 start."],
        ['agent_name' => 'Sarah Seastrunk',    'state' => 'NC', 'office' => 'Raleigh',        'start_date' => '',      'notes' => "Shawn's team."],
        ['agent_name' => 'Malisha Leach',      'state' => 'PA', 'office' => 'Doylestown',     'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Shawn Gentile',      'state' => 'PA', 'office' => 'Doylestown',     'start_date' => $aug10,  'notes' => "Original sheet's Coach column read 'Not able to attend' — flag for review, not a real coach name."],
        ['agent_name' => 'Bill Lowe',          'state' => 'PA', 'office' => 'Doylestown',     'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Nicole Mikula',      'state' => 'PA', 'office' => 'Doylestown',     'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Mandee Hammerstein', 'state' => 'PA', 'office' => 'Doylestown',     'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Ron Monroe',         'state' => 'PA', 'office' => 'Harleysville',   'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Brent Moser',        'state' => 'PA', 'office' => 'North Wales',    'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Allison Cooper',     'state' => 'SC', 'office' => 'Murrells Inlet', 'start_date' => $aug10,  'notes' => ''],
        ['agent_name' => 'Joy Smith',          'state' => 'SC', 'office' => 'Murrells Inlet', 'start_date' => '',      'notes' => 'Experienced agent considering taking LAUNCH again.'],
        ['agent_name' => 'Rosie Yordanova',    'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => $aug10,  'notes' => "Originally noted as 'will need in August (going to Bulgaria)'. Name appears as Yardonova/Yoranova/Yordanova across source lists — verify correct spelling."],
        ['agent_name' => 'Fernanda Azevedo',   'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => $aug10,  'notes' => "Originally noted as 'will need to wait (just had a baby)' — now scheduled for the Aug 10, 2026 start."],
        ['agent_name' => 'Kevin Field',        'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => $aug10,  'notes' => ''],
        ['agent_name' => 'Addie Woodbury',     'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Komal Patel',        'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Mike Carpenter',     'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => '',      'notes' => ''],
        ['agent_name' => 'Jordan Bagwell',     'state' => 'SC', 'office' => 'Pro Drive',      'start_date' => '',      'notes' => "On Dan's team."],
        ['agent_name' => 'Madison Johnson',    'state' => 'SC', 'office' => 'Hartsville',     'start_date' => $aug10,  'notes' => ''],
        ['agent_name' => 'Anna VanDuzer',      'state' => 'SC', 'office' => '',               'start_date' => $aug10,  'notes' => ''],
        ['agent_name' => 'Kate Brown',         'state' => 'PA', 'office' => '',               'start_date' => $aug10,  'notes' => ''],
        ['agent_name' => 'Lauren Carra',       'state' => 'PA', 'office' => '',               'start_date' => $aug10,  'notes' => ''],
        ['agent_name' => 'Samantha Harrison',  'state' => 'PA', 'office' => '',               'start_date' => $aug10,  'notes' => ''],
        ['agent_name' => 'Dave Snyder',        'state' => 'PA', 'office' => '',               'start_date' => $aug10,  'notes' => ''],
    ];
}
