<?php
// Onboarding regression suite. Runs UNCHANGED against both the baseline
// (current production code) and the feature branch, and writes an outcome
// signature per scenario to the JSON file given as argv[1]. run_all.sh then
// diffs the two: every ONB-* signature must be identical (legitimate new-agent
// onboarding preserved); the EXIST-* scenarios are the known bug and are
// expected to differ (baseline sends new-agent emails, feature does not).
require __DIR__ . '/lib_test.php';
require_once APP_DIR . '/db.php';
require_once APP_DIR . '/local_db.php';
require_once APP_DIR . '/lib/onboarding.php';
require_once APP_DIR . '/lib/notifications.php';

$out = $argv[1] ?? '/tmp/regression.json';
$sig = [];
function subjects_sig(array $rows): array {
    // Normalized: subject patterns + recipient count (names stripped).
    $c = [];
    foreach ($rows as $r) {
        $k = preg_replace('/(Test [A-Za-z]+|[a-z0-9.]+@test\.invalid)/', '<agent>', $r['subject']);
        $c[$k] = ($c[$k] ?? 0) + 1;
    }
    ksort($c);
    return $c;
}

reset_db(); seed_base();
// Every scenario agent has the same synthetic sponsor chain in the mock CRM,
// so the growth-network ("New Agent in Your Growth Network") email path is
// exercised and compared too.
$ok = crm_reset(['onb.public@test.invalid', 'onb.public2@test.invalid', 'onb.self@test.invalid', 'onb.partial@test.invalid',
                 'onb.staffedit@test.invalid', 'onb.override@test.invalid', 'exist.self@test.invalid', 'onb.blankresub@test.invalid']);
if (!ok($ok, 'mock CRM reachable and seeded (synthetic sponsor chain)')) { exit(summary()); }

// ONB-1: public intake via the tokenized queue link, all 14 fields
section('ONB-1 public intake form (queue link), complete');
$e = 'onb.public@test.invalid';
$qid = add_queue($e, 'Test Public', 'active');
$n0 = max_notif();
$body = full_row($e, 'Test Public') + ['qid' => $qid, 'token' => intake_link_token($qid), 'full_time' => true, 'show_on_internet' => true];
$r = http_req(null, 'POST', '/api/intake_public.php', $body);
$row = tq('SELECT submitted FROM agent_intake WHERE email=?', [$e])[0] ?? [];
$sig['ONB-1'] = ['http' => $r['code'], 'ok' => $r['json']['ok'] ?? null, 'submitted' => (int)($row['submitted'] ?? -1),
                 'queue_rows' => (int)tq1('SELECT COUNT(*) FROM onboard_queue WHERE agent_email=?', [$e]), 'notifs' => subjects_sig(notifs_since($n0))];
ok(($r['json']['ok'] ?? false) === true, 'public submission accepted', $r['body']);
ok(count(growth_notifs(notifs_since($n0))) === 2, 'growth-network email to each sponsor in the chain (2)', notifs_since($n0));
ok((int)($row['submitted'] ?? 0) === 1, 'intake marked submitted');

// ONB-2: public intake missing Referring Agent is rejected
section('ONB-2 public intake form missing Referring Agent');
$e2 = 'onb.public2@test.invalid';
$qid2 = add_queue($e2, 'Test Publictwo', 'active');
$b2 = full_row($e2, 'Test Publictwo', ['referring_agent' => '']) + ['qid' => $qid2, 'token' => intake_link_token($qid2)];
$r = http_req(null, 'POST', '/api/intake_public.php', $b2);
$sig['ONB-2'] = ['http' => $r['code'], 'error' => $r['json']['error'] ?? null, 'row' => (int)tq1('SELECT COUNT(*) FROM agent_intake WHERE email=?', [$e2])];
ok($r['code'] === 400 && strpos($r['json']['error'] ?? '', 'referring_agent') !== false, 'rejected with referring_agent missing', $r['body']);

// ONB-3: resubmitting a complete public intake is blocked
section('ONB-3 duplicate public submission');
$n0 = max_notif();
$r = http_req(null, 'POST', '/api/intake_public.php', $body);
ok(count(notifs_since($n0)) === 0, 'duplicate submission sends no notifications at all');
$sig['ONB-3'] = ['http' => $r['code'], 'error' => $r['json']['error'] ?? null];
ok($r['code'] === 409, 'duplicate blocked (409)', $r['body']);

// ONB-4: onboarding agent completes their own Intake Form (logged in)
section('ONB-4 onboarding agent self-submits via Intake Form');
$e4 = 'onb.self@test.invalid';
add_queue($e4, 'Test Selfsave', 'active');
add_role($e4, 'agent');
insert_intake(full_row($e4, 'Test Selfsave', ['referring_agent' => '']), 0);
$n0 = max_notif();
$r = http_req($e4, 'POST', '/api/intake.php', intake_post_body($e4, ['full_name' => 'Test Selfsave']));
$after = tq('SELECT submitted, submitted_at FROM agent_intake WHERE email=?', [$e4])[0];
$first = notifs_since($n0);
$n1 = max_notif();
$r2 = http_req($e4, 'POST', '/api/intake.php', intake_post_body($e4, ['full_name' => 'Test Selfsave', 'bio' => 'Edited bio, second save.']));
$second = notifs_since($n1);
$sig['ONB-4'] = ['http' => $r['code'], 'submitted' => (int)$after['submitted'], 'has_submitted_at' => $after['submitted_at'] !== null,
                 'first_save_new_agent' => subjects_sig(new_agent_notifs($first)), 'second_save_new_agent' => subjects_sig(new_agent_notifs($second))];
ok((int)$after['submitted'] === 1, 'submitted flipped on completing save', $r['body']);
ok(count(new_agent_notifs($first)) > 0, 'new-agent emails queued on the flip');
ok(count(growth_notifs($first)) === 2, 'growth-network emails sent once to the sponsor chain', $first);
ok(count(new_agent_notifs($second)) === 0, 'no repeat new-agent emails on later save');
ok(count(growth_notifs($second)) === 0, 'no repeat growth-network emails on later save');

// ONB-5: onboarding agent saves without Referring Agent → stays Draft
section('ONB-5 onboarding agent saves incomplete form');
$e5 = 'onb.partial@test.invalid';
add_queue($e5, 'Test Partial', 'active'); add_role($e5, 'agent');
$n0 = max_notif();
$r = http_req($e5, 'POST', '/api/intake.php', intake_post_body($e5, ['full_name' => 'Test Partial', 'referring_agent' => '']));
$sub = (int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e5]);
$sig['ONB-5'] = ['http' => $r['code'], 'submitted' => $sub, 'new_agent' => subjects_sig(new_agent_notifs(notifs_since($n0)))];
ok($sub === 0, 'stays not submitted');

// ONB-6: staff edit of an onboarding agent with all 14 fields
section('ONB-6 staff edits an onboarding agent');
$e6 = 'onb.staffedit@test.invalid';
add_queue($e6, 'Test Staffedit', 'active');
insert_intake(full_row($e6, 'Test Staffedit', ['referring_agent' => '']), 0);
$n0 = max_notif();
$r = http_req('admin@test.invalid', 'POST', '/api/intake.php', ['email' => $e6] + intake_post_body($e6, ['full_name' => 'Test Staffedit']));
$sub = (int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e6]);
$sig['ONB-6'] = ['http' => $r['code'], 'submitted' => $sub, 'notifs' => subjects_sig(notifs_since($n0))];
ok($sub === 1, 'staff save completing the form marks it submitted (unchanged)', $r['body']);
ok(count(notifs_since($n0)) === 0, 'staff edit queues no emails (unchanged)');

// ONB-7: Onboarding Queue "mark intake submitted" override
section('ONB-7 queue override: mark intake submitted');
$e7 = 'onb.override@test.invalid';
$q7 = add_queue($e7, 'Test Override', 'active');
insert_intake(full_row($e7, 'Test Override', ['bio' => '']), 0);
$n0 = max_notif();
$r = http_req('admin@test.invalid', 'POST', '/api/onboard_action.php?action=mark_intake_submitted', ['queue_id' => $q7]);
$sub = (int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e7]);
$sig['ONB-7'] = ['http' => $r['code'], 'submitted' => $sub, 'notifs' => subjects_sig(notifs_since($n0))];
ok(($r['json']['ok'] ?? false) === true && $sub === 1, 'override marks submitted', $r['body']);

// ONB-8: Queue list exposes intake_submitted the same way
section('ONB-8 queue list intake_submitted flag');
$r = http_req('admin@test.invalid', 'GET', '/api/onboard_action.php?action=list_queue&filter=active');
$flags = [];
foreach (($r['json']['rows'] ?? $r['json']['queue'] ?? $r['json'] ?? []) as $qr) if (is_array($qr) && isset($qr['agent_email'])) $flags[$qr['agent_email']] = (int)$qr['intake_submitted'];
ksort($flags);
$sig['ONB-8'] = ['http' => $r['code'], 'flags' => $flags];
ok($r['code'] === 200 && count($flags) >= 6, 'queue list returned with intake_submitted flags', substr($r['body'], 0, 200));

// ONB-9: intake reminder cron targets (dry run — sends nothing)
section('ONB-9 intake reminder cron (dry run)');
$old = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
tx("UPDATE onboard_queue SET intake_sent_at=? WHERE status='active'", [$old]);
add_queue('onb.done@test.invalid', 'Test Done', 'completed', $old);
$cron = shell_exec('php ' . APP_DIR . '/cron/intake_reminders.php --dry-run 2>&1');
preg_match_all('/WOULD SEND reminder \d+\/\d+ to #\d+ (.+?) </', $cron, $m);
$who = $m[1]; sort($who);
$sig['ONB-9'] = ['would_send' => $who];
ok(in_array('Test Partial', $who, true) && !in_array('Test Selfsave', $who, true) && !in_array('Test Done', $who, true), 'reminders go only to active, unsubmitted agents', $cron);

// ONB-10: production's 2026-09-18 fix — a record stamped submitted=1 with its
// required fields still blank must NOT be blocked as a duplicate; the agent
// can resubmit through the public form.
section('ONB-10 submitted-but-blank record can resubmit (public form)');
$e10 = 'onb.blankresub@test.invalid';
$qid10 = add_queue($e10, 'Test Blankresub', 'active');
insert_intake(['email' => $e10, 'full_name' => 'Test Blankresub'], 1);
$n0 = max_notif();
$b10 = full_row($e10, 'Test Blankresub') + ['qid' => $qid10, 'token' => intake_link_token($qid10)];
$r = http_req(null, 'POST', '/api/intake_public.php', $b10);
$row10 = tq('SELECT submitted, phone FROM agent_intake WHERE email=?', [$e10])[0] ?? [];
$sig['ONB-10'] = ['http' => $r['code'], 'ok' => $r['json']['ok'] ?? null, 'submitted' => (int)($row10['submitted'] ?? -1),
                  'saved' => ($row10['phone'] ?? '') !== '', 'notifs' => subjects_sig(notifs_since($n0))];
ok(($r['json']['ok'] ?? false) === true && ($row10['phone'] ?? '') !== '', 'blank-but-submitted record is not dead-ended: resubmission accepted and saved', $r['body']);

// EXIST-1 (EXPECTED TO DIFFER): an existing agent (no queue row) completes their own profile
section('EXIST-1 existing agent self-saves a complete profile (bug case)');
$e8 = 'exist.self@test.invalid';
add_role($e8, 'agent');
insert_intake(full_row($e8, 'Test Tenured', ['bio' => '', 'referring_agent' => '']), 0);
$n0 = max_notif();
$r = http_req($e8, 'POST', '/api/intake.php', intake_post_body($e8, ['full_name' => 'Test Tenured']));
$rows = notifs_since($n0);
$sig['EXIST-1'] = ['submitted' => (int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e8]), 'new_agent' => subjects_sig(new_agent_notifs($rows))];
echo "  INFO  submitted=" . $sig['EXIST-1']['submitted'] . ", new-agent emails queued=" . count(new_agent_notifs($rows)) . "\n";

file_put_contents($out, json_encode($sig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
exit(summary());
