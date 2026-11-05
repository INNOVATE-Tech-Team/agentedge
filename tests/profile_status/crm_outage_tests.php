<?php
// CRM outage behaviour. run_all.sh STOPS the mock CRM container before this
// runs (so every Perfex lookup fails exactly as in a real outage, in PHP's
// default exception mode) and restarts it afterwards.
//   feature branch: must pass — submissions complete fully, no 500s, the
//                   non-CRM notifications still go out, no growth emails.
//   baseline:       run in report mode to document current production
//                   behaviour (expected to fail: fatal error after saving).
require __DIR__ . '/lib_test.php';
require_once APP_DIR . '/db.php';
require_once APP_DIR . '/local_db.php';
require_once APP_DIR . '/lib/notifications.php';

if (crm()) { fwrite(STDERR, "The mock CRM is reachable — stop agentedge-profile-mockcrm first (run_all.sh does this).\n"); exit(2); }
reset_db(); seed_base();

section('OUT-1 onboarding agent self-submits during a CRM outage');
$e = 'out.onb@test.invalid';
add_queue($e, 'Test Outage', 'active'); add_role($e, 'agent');
$n0 = max_notif();
$r = http_req($e, 'POST', '/api/intake.php', intake_post_body($e, ['full_name' => 'Test Outage']));
$rows = notifs_since($n0);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true, 'request succeeds (no application error)', "HTTP {$r['code']} " . substr($r['body'], 0, 160));
ok((int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e]) === 1, 'intake fully saved and marked submitted');
$subjects = array_column($rows, 'subject');
$has = fn($needle) => (bool)array_filter($subjects, fn($s) => strpos($s, $needle) !== false);
ok($has('completed their intake form') && $has('Intake form submitted') && $has('Intake Form Submitted:'), 'leadership, BIC/MC Leader and summary emails all still queued (no partial run)', $subjects);
ok(count(growth_notifs($rows)) === 0, 'no growth-network emails (sponsor chain unavailable) — no error either');
$n1 = max_notif();
$r = http_req($e, 'POST', '/api/intake.php', intake_post_body($e, ['full_name' => 'Test Outage', 'bio' => 'Second save.']));
ok($r['code'] === 200 && count(new_agent_notifs(notifs_since($n1))) === 0, 'a second save sends nothing more');

section('OUT-2 public intake form during a CRM outage');
$e2 = 'out.public@test.invalid';
$qid = add_queue($e2, 'Test Outagepublic', 'active');
$r = http_req(null, 'POST', '/api/intake_public.php', full_row($e2, 'Test Outagepublic') + ['qid' => $qid, 'token' => intake_link_token($qid)]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true, 'public submission succeeds', "HTTP {$r['code']} " . substr($r['body'], 0, 160));
ok((int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e2]) === 1, 'saved and submitted');

section('OUT-3 existing agent saves during a CRM outage');
$e3 = 'out.exist@test.invalid';
add_role($e3, 'agent');
insert_intake(full_row($e3, 'Test Outageexisting', ['bio' => '']), 0);
$n0 = max_notif();
$r = http_req($e3, 'POST', '/api/intake.php', intake_post_body($e3, ['full_name' => 'Test Outageexisting']));
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true, 'save succeeds', "HTTP {$r['code']} " . substr($r['body'], 0, 160));
ok(count(new_agent_notifs(notifs_since($n0))) === 0, 'no new-agent or growth-network emails');

exit(summary());
