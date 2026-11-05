<?php
// Feature tests for profile completeness vs. intake submission (feature
// branch only — uses the new shared functions in lib/agent_profile.php).
// ISOLATED DEV CONTAINER ONLY: synthetic @test.invalid data, schema-only DB,
// SendGrid key blank and no outbound network.
require __DIR__ . '/lib_test.php';
require_once APP_DIR . '/db.php';
require_once APP_DIR . '/local_db.php';
require_once APP_DIR . '/lib/agent_profile.php';

reset_db(); seed_base();
// Apply the explicit migration first (the app itself never alters tables).
@mkdir(DATA_DIR . '/bk', 0777, true);
exec('php ' . APP_DIR . '/scripts/migrations/20261105_profile_status.php --db=' . DATA_DIR . '/agentedge.db --apply --backup-dir=' . DATA_DIR . '/bk 2>&1', $migOut, $migRc);
if (!ok($migRc === 0, 'migration applied to the synthetic DB', implode("\n", $migOut))) exit(summary());
profile_schema_cache_reset();
function fresh_status(string $email): array { profile_track_cache_reset(); return profile_status($email); }
function missing_keys(array $m): array { $k = array_column($m, 'key'); sort($k); return $k; }

// ── Fixtures ────────────────────────────────────────────────────────────────
$F = [
  // email => [row overrides, submitted, queue status|null, expected track, expected status, expected missing keys]
  'imp.complete@test.invalid'   => [['bio' => '', 'nar_number' => '', 'referring_agent' => ''], 0, null, 'existing', 'complete', []],
  'imp.allfields@test.invalid'  => [[], 0, null, 'existing', 'complete', []],
  'imp.incomplete@test.invalid' => [['city' => '', 'zip' => '', 'bio' => ''], 0, null, 'existing', 'incomplete', ['city', 'zip']],
  'imp.1900@test.invalid'       => [['birthday' => '1900-06-28', 'bio' => ''], 0, null, 'existing', 'complete', []],
  'imp.1900leap@test.invalid'   => [['birthday' => '1900-02-29'], 0, null, 'existing', 'complete', []],
  'imp.badbday@test.invalid'    => [['birthday' => 'June 28'], 0, null, 'existing', 'incomplete', ['birthday']],
  'imp.badday@test.invalid'     => [['birthday' => '1980-02-30'], 0, null, 'existing', 'incomplete', ['birthday']],
  'imp.submitted.missing@test.invalid' => [['state' => ''], 1, null, 'existing', 'incomplete', ['state']],
  'q.completed@test.invalid'    => [['bio' => ''], 1, 'completed', 'existing', 'complete', []],
  'q.cancelled@test.invalid'    => [[], 0, 'cancelled', 'existing', 'complete', []],
  'q.active.draft@test.invalid' => [['referring_agent' => ''], 0, 'active', 'onboarding', 'draft', ['referring_agent']],
  'q.active.sub@test.invalid'   => [[], 1, 'active', 'onboarding', 'submitted', []],
  'dup.one@test.invalid'        => [['full_name' => 'Test Duplicate', 'phone' => '5550142424', 'zip' => ''], 0, null, 'existing', 'incomplete', ['zip']],
  'dup.two@test.invalid'        => [['full_name' => 'Test Duplicate', 'phone' => '5550142424'], 0, null, 'existing', 'complete', []],
  'link.complete@test.invalid'  => [['bio' => ''], 0, null, 'existing', 'complete', []],
  'link.incomplete@test.invalid'=> [['city' => '', 'bio' => '', 'nar_number' => ''], 0, null, 'existing', 'incomplete', ['city']],
  'link.rules@test.invalid'     => [['zip' => ''], 0, null, 'existing', 'incomplete', ['zip']],
  // Historical submission preserved, current profile missing emergency contact
  'hist.noemerg@test.invalid'   => [['emergency_name' => '', 'emergency_phone' => '', 'bio' => ''], 1, null, 'existing', 'incomplete', ['emergency_name', 'emergency_phone']],
  'hold.complete@test.invalid'  => [[], 0, null, 'existing', 'complete', []],
  'hold.onb@test.invalid'       => [['referring_agent' => ''], 0, 'active', 'onboarding', 'draft', ['referring_agent']],
];
$i = 0;
foreach ($F as $email => [$over, $sub, $qs]) {
    $name = $over['full_name'] ?? ('Test Agent' . chr(65 + $i++));
    insert_intake(full_row($email, $name, $over), $sub);
    if ($qs) add_queue($email, $name, $qs);
    add_role($email, 'agent');
    tx("INSERT INTO innovate_roster (agent_name, email, state_code, market_center, active) VALUES (?, ?, 'SC', 'Testville', 1)", [$name, $email]);
}

// ── 1. Shared rules ─────────────────────────────────────────────────────────
section('1. Shared required-field rules');
$legacy14 = ['full_name','phone','license_number','nar_number','office_location','birthday','address_line1','city','state','zip','emergency_name','emergency_phone','bio','referring_agent'];
ok(array_keys(ONBOARDING_REQUIRED_FIELDS) === $legacy14, 'onboarding rule is exactly the previous 14 fields, same order');
ok(count(EXISTING_AGENT_REQUIRED_FIELDS) === 11 && !isset(EXISTING_AGENT_REQUIRED_FIELDS['bio']) && !isset(EXISTING_AGENT_REQUIRED_FIELDS['nar_number']) && !isset(EXISTING_AGENT_REQUIRED_FIELDS['referring_agent']), 'existing-agent rule: 11 fields, no Bio / NAR # / Referring Agent');
ok(array_keys(EXISTING_AGENT_RECOMMENDED_FIELDS) === ['bio', 'nar_number'], 'Bio and NAR # are recommended (optional) for existing agents');
foreach ($F as $email => [, , , $track, $status, $miss]) {
    $ps = fresh_status($email);
    ok($ps['track'] === $track && $ps['status'] === $status && missing_keys($ps['missing']) === (function ($m) { sort($m); return $m; })($miss),
       "$email → $track / $status" . ($miss ? ' (missing ' . implode(',', $miss) . ')' : ''), $ps);
}
$lbl = fn($e) => fresh_status($e)['label'];
ok($lbl('q.active.draft@test.invalid') === 'Draft' && $lbl('q.active.sub@test.invalid') === 'Submitted' && $lbl('imp.complete@test.invalid') === 'Complete' && $lbl('imp.incomplete@test.invalid') === 'Incomplete', 'labels: Draft / Submitted (onboarding), Complete / Incomplete (existing)');

// ── 2. Birthday 1900 placeholder ────────────────────────────────────────────
section('2. 1900 birth year = unknown');
ok(birthday_year_unknown('1900-06-28') && !birthday_year_unknown('1985-04-12'), '1900 detected as unknown year, real years not');
ok(agent_birth_year('1900-06-28') === null && agent_birth_year('1985-04-12') === 1985 && agent_birth_year('garbage') === null, 'agent_birth_year() never returns 1900');
ok(birthday_parts('1900-02-29') !== null && birthday_parts('1981-02-29') === null && birthday_parts('1980-13-01') === null, 'month/day validated (Feb 29 allowed only for unknown or leap years)');
ok(in_array('birth_year_unknown', array_column(fresh_status('imp.1900@test.invalid')['flags'], 'key'), true), 'flagged for cleanup');
ok(tq1('SELECT birthday FROM agent_intake WHERE email=?', ['imp.1900@test.invalid']) === '1900-06-28', 'stored value preserved');

// ── 3. Existing agents updating profiles: no new-agent emails, no intake flip ─
section('3. Existing agents updating their profiles');
$e = 'imp.incomplete@test.invalid';
$n0 = tq1('SELECT COALESCE(MAX(id),0) FROM notification_queue');
$r = http_req($e, 'POST', '/api/intake.php', intake_post_body($e, ['full_name' => 'Test AgentC', 'bio' => '']));
$new = notifs_since((int)$n0);
ok(($r['json']['ok'] ?? false) && ($r['json']['profile_status']['status'] ?? '') === 'complete', 'self-save filling the last fields → Complete', $r['body']);
ok((int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e]) === 0, 'intake-submitted flag NOT flipped for an existing agent');
ok(count(new_agent_notifs($new)) === 0, 'zero new-agent emails', $new);
ok(count($new) >= 1 && count(array_filter($new, fn($r) => strpos($r['subject'], NOT_NEW_AGENT_SUBJECT) === false)) === 0, 'only the existing "updated their profile" heads-up (unchanged behavior)', $new);

$e = 'imp.complete@test.invalid';
$n0 = max_notif();
$r = http_req($e, 'POST', '/api/profile.php', ['full_name' => 'Test AgentA', 'phone' => '5550100999']);
ok($r['code'] === 200 && (int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e]) === 0 && count(new_agent_notifs(notifs_since($n0))) === 0, 'My Profile save: no flip, no new-agent emails', $r['body']);

$e = 'link.rules@test.invalid';
$n0 = max_notif();
$r = http_req('admin@test.invalid', 'POST', '/api/intake.php', ['email' => $e] + intake_post_body($e, ['full_name' => 'Test AgentO', 'zip' => '']));
ok(($r['json']['ok'] ?? false) && count(notifs_since($n0)) === 0 && (int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e]) === 0, 'staff Edit Profile on an existing agent: no flip, no emails', $r['body']);
ok(missing_keys(fresh_status($e)['missing']) === ['zip'], 'staff save with zip blank keeps it Incomplete (missing zip)');

// ── 4. Completion links ─────────────────────────────────────────────────────
section('4. Completion links: already-complete Draft profile (bug fix)');
$pdo = local_db();
$t = profile_link_issue($pdo, 'link.complete@test.invalid', 'admin@test.invalid');
$r = http_req(null, 'GET', '/api/complete_profile_action.php?token=' . $t);
ok(($r['json']['ok'] ?? false) && $r['json']['missing'] === [] && ($r['json']['status'] ?? '') === 'complete', 'link shows nothing required and status Complete', $r['body']);
ok(array_column($r['json']['optional'] ?? [], 'key') === ['bio', 'languages', 'mls_board'], 'Bio offered as optional; Referring Agent never asked', $r['json']['optional'] ?? null);
$r = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'single', 'email' => 'link.complete@test.invalid']);
ok(($r['json']['ok'] ?? true) === false && strpos($r['json']['error'] ?? '', 'already complete') !== false, 'sending a link to a complete profile is refused', $r['body']);

section('4b. Completion links: incomplete existing agent');
$e = 'link.incomplete@test.invalid';
$t = profile_link_issue($pdo, $e, 'admin@test.invalid');
$r = http_req(null, 'GET', '/api/complete_profile_action.php?token=' . $t);
ok(missing_keys($r['json']['missing'] ?? []) === ['city'] && array_column($r['json']['optional'], 'key') === ['bio', 'nar_number', 'languages', 'mls_board'], 'asks for City (required); Bio + NAR # optional', $r['body']);
$r = http_req(null, 'POST', '/api/complete_profile_action.php', ['token' => $t, 'fields' => ['bio' => 'x']]);
ok($r['code'] === 400, 'required field left blank → rejected', $r['body']);
$n0 = max_notif();
$r = http_req(null, 'POST', '/api/complete_profile_action.php', ['token' => $t, 'fields' => ['city' => 'Testville', 'full_name' => 'HACKED NAME']]);
ok(($r['json']['ok'] ?? false) && ($r['json']['status'] ?? '') === 'complete', 'filling City → Complete', $r['body']);
ok((int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e]) === 0 && count(notifs_since($n0)) === 0, 'no intake flip and no emails from the link');
ok(tq1('SELECT full_name FROM agent_intake WHERE email=?', [$e]) !== 'HACKED NAME', 'a link cannot overwrite a field already on file');

section('4c. Completion link rules (expiry, superseding, cooldown, override)');
$e = 'link.rules@test.invalid';
$r = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'single', 'email' => $e]);
ok(($r['json']['ok'] ?? false) === true, 'first link sent (email suppressed in dev)', $r['body']);
$tok1 = tq('SELECT token, expires_at, created_at FROM profile_completion_tokens WHERE email=? ORDER BY rowid DESC LIMIT 1', [$e])[0];
$ttl = (strtotime($tok1['expires_at']) - strtotime($tok1['created_at'])) / 86400;
ok(abs($ttl - 30) < 0.01, 'new link expires after 30 days', $tok1);
$r = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'single', 'email' => $e]);
ok(($r['json']['cooldown'] ?? false) === true && ($r['json']['can_override'] ?? null) === false, 'resend within 7 days blocked; admin cannot override', $r['body']);
$r = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'single', 'email' => $e, 'override_cooldown' => true]);
ok($r['code'] === 403, 'override attempt by a non-Super-Admin → 403', $r['body']);
$r = http_req('super@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'single', 'email' => $e]);
ok(($r['json']['cooldown'] ?? false) === true && ($r['json']['can_override'] ?? null) === true, 'Super Admin is told they may override', $r['body']);
$r = http_req('super@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'single', 'email' => $e, 'override_cooldown' => true]);
ok(($r['json']['ok'] ?? false) && ($r['json']['cooldown_overridden'] ?? false), 'Super Admin explicit override sends a new link', $r['body']);
ok((int)tq1('SELECT cooldown_override FROM profile_completion_tokens WHERE email=? ORDER BY rowid DESC LIMIT 1', [$e]) === 1, 'override recorded on the new link');
$r = http_req(null, 'GET', '/api/complete_profile_action.php?token=' . $tok1['token']);
ok(($r['json']['link_state'] ?? '') === 'revoked', 'previous link invalidated (superseded)', $r['body']);
$tok2 = tq1('SELECT token FROM profile_completion_tokens WHERE email=? ORDER BY rowid DESC LIMIT 1', [$e]);
ok((http_req(null, 'GET', '/api/complete_profile_action.php?token=' . $tok2)['json']['ok'] ?? false) === true, 'replacement link works');
tx("UPDATE profile_completion_tokens SET expires_at = datetime('now','-1 minute') WHERE token=?", [$tok2]);
$r = http_req(null, 'GET', '/api/complete_profile_action.php?token=' . $tok2);
ok(($r['json']['link_state'] ?? '') === 'expired', 'expired link refused', $r['body']);
$r = http_req(null, 'POST', '/api/complete_profile_action.php', ['token' => $tok2, 'fields' => ['zip' => '29000']]);
ok($r['code'] === 400 && tq1('SELECT zip FROM agent_intake WHERE email=?', [$e]) === '', 'expired link cannot save');
// Legacy link (issued before these rules): no expires_at → keeps working; NOT auto-expired.
tx("INSERT INTO profile_completion_tokens (token, email, created_at, created_by) VALUES ('legacytoken000000000000000000000000000000000000', 'imp.badbday@test.invalid', datetime('now','-100 days'), 'staff@test.invalid')");
$r = http_req(null, 'GET', '/api/complete_profile_action.php?token=legacytoken000000000000000000000000000000000000');
ok(($r['json']['ok'] ?? false) === true, 'pre-existing (legacy) links are NOT expired automatically', $r['body']);
ok(http_req(null, 'GET', '/api/complete_profile_action.php?token=nope')['json']['link_state'] === 'invalid', 'unknown token → invalid');

section('4d. Completion links never go to agents actively onboarding');
$e = 'q.active.draft@test.invalid';
$r = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'single', 'email' => $e]);
ok(($r['json']['ok'] ?? true) === false && strpos($r['json']['error'] ?? '', 'Onboarding Queue') !== false, 'single send refused (points to Resend Intake)', $r['body']);
tx("INSERT INTO profile_completion_tokens (token, email, created_by) VALUES ('onbtoken0000000000000000000000000000000000000000', ?, 'x')", [$e]);
$r = http_req(null, 'GET', '/api/complete_profile_action.php?token=onbtoken0000000000000000000000000000000000000000');
ok(($r['json']['link_state'] ?? '') === 'onboarding', 'an older link for an onboarding agent is redirected to the intake form', $r['body']);

section('4e. Bulk send: preview + confirmation + cooldown');
$r = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'bulk_preview']);
$rec = array_column($r['json']['recipients'] ?? [], 'email');
ok(($r['json']['ok'] ?? false) && in_array('imp.badbday@test.invalid', $rec, true) && in_array('dup.one@test.invalid', $rec, true), 'preview lists incomplete existing agents', $r['body']);
ok(!in_array('q.active.draft@test.invalid', $rec, true) && ($r['json']['skipped_onboarding'] ?? 0) >= 1, 'onboarding agents excluded');
ok(!in_array('link.rules@test.invalid', $rec, true) && in_array('link.rules@test.invalid', array_column($r['json']['skipped_cooldown'], 'email'), true), 'agents inside the 7-day cooldown skipped and listed');
ok(!in_array('imp.complete@test.invalid', $rec, true) && !in_array('dup.two@test.invalid', $rec, true), 'complete agents excluded');
ok(count($rec) === count(array_unique($rec)), 'each agent listed once');
$tokBefore = (int)tq1('SELECT COUNT(*) FROM profile_completion_tokens');
$r2 = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'bulk_incomplete']);
ok(($r2['json']['ok'] ?? true) === false && (int)tq1('SELECT COUNT(*) FROM profile_completion_tokens') === $tokBefore, 'send without a confirmed preview → refused, nothing sent', $r2['body']);
$r2 = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'bulk_incomplete', 'confirm_hash' => str_repeat('0', 64)]);
ok(($r2['json']['stale_preview'] ?? false) === true, 'stale/mismatched preview → refused');
$r2 = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'bulk_incomplete', 'confirm_hash' => $r['json']['confirm_hash']]);
ok(($r2['json']['sent'] ?? -1) === count($rec) && (int)tq1('SELECT COUNT(*) FROM profile_completion_tokens') === $tokBefore + count($rec), 'confirmed send goes to exactly the previewed list', $r2['body']);
$r3 = http_req('admin@test.invalid', 'POST', '/api/send_profile_completion.php', ['action' => 'bulk_preview']);
ok(count($r3['json']['recipients']) === 0, 'immediate second preview: everyone now in cooldown (no duplicate emails)', $r3['body']);

// ── 5. Duplicate records ────────────────────────────────────────────────────
section('5. Duplicate agent records');
ok(fresh_status('dup.one@test.invalid')['status'] === 'incomplete' && fresh_status('dup.two@test.invalid')['status'] === 'complete', 'same-name/same-phone profiles are evaluated independently');
$d2 = (int)tq1("SELECT COUNT(*) FROM profile_completion_tokens WHERE email='dup.two@test.invalid'");
ok($d2 === 0, 'link activity for one duplicate never touches the other');

// ── 6. Onboarding Queue transitions ─────────────────────────────────────────
section('6. Onboarding Queue transitions');
$e = 'q.active.sub@test.invalid';
ok(fresh_status($e)['label'] === 'Submitted', 'active + submitted → Submitted');
tx("UPDATE onboard_queue SET status='completed' WHERE agent_email=?", [$e]);
ok(fresh_status($e)['label'] === 'Complete' && fresh_status($e)['track'] === 'existing', 'queue completed → existing agent, Complete');
tx("UPDATE onboard_queue SET status='active' WHERE agent_email=?", [$e]);
ok(fresh_status($e)['label'] === 'Submitted', 'reopened to active → back to onboarding labels');
tx("INSERT INTO onboard_queue (agent_email, agent_name, added_by, status) VALUES ('  IMP.Complete@Test.Invalid ', 'x', 'admin@test.invalid', 'cancelled')");
ok(fresh_status('imp.complete@test.invalid')['track'] === 'existing', 'a cancelled queue row does not make an agent onboarding');
tx("UPDATE onboard_queue SET status='active' WHERE agent_email='  IMP.Complete@Test.Invalid '");
ok(fresh_status('imp.complete@test.invalid')['track'] === 'onboarding', 'queue email matched case/whitespace-insensitively');
tx("DELETE FROM onboard_queue WHERE agent_email='  IMP.Complete@Test.Invalid '");

// ── 7. Agents page labels, tabs, filter ─────────────────────────────────────
section('7. Agents page');
profile_track_cache_reset();
$r = http_req('admin@test.invalid', 'GET', '/backoffice_agents.php');
ok($r['code'] === 200, 'page renders', substr($r['body'], 0, 300));
foreach (['onb-draft' => 'Onboarding · Draft', 'onb-submitted' => 'Onboarding · Submitted', 'complete' => 'Complete', 'incomplete' => 'Incomplete'] as $tab => $txt) {
    ok(strpos($r['body'], 'data-tab="' . $tab . '">' . $txt . '</button>') !== false, "tab: $txt");
}
ok(strpos($r['body'], 'id="agFlagBirthYear"') !== false, 'birth-year-unknown filter present');
preg_match_all('/<tr class="data-row"[^>]*data-tab="([a-z-]+)" data-flags="([^"]*)" data-intake="\d"\s+data-search="([^"]*)"/', $r['body'], $m, PREG_SET_ORDER);
$rowTab = []; $rowFlags = [];
foreach ($m as $mm) { if (preg_match('/[a-z0-9.]+@test\.invalid/', $mm[3], $em)) { $rowTab[$em[0]] = $mm[1]; $rowFlags[$em[0]] = $mm[2]; } }
$expTab = ['draft' => 'onb-draft', 'submitted' => 'onb-submitted', 'complete' => 'complete', 'incomplete' => 'incomplete'];
$bad = [];
foreach (array_keys($F) as $email) {
    $want = $expTab[fresh_status($email)['status']];
    if (($rowTab[$email] ?? null) !== $want) $bad[] = "$email: page=" . ($rowTab[$email] ?? 'none') . " want=$want";
}
ok(!$bad, 'every agent row carries the right status tab', $bad);
ok(($rowFlags['imp.1900@test.invalid'] ?? '') === 'birth_year_unknown' && ($rowFlags['imp.complete@test.invalid'] ?? 'x') === '', 'birth-year flag on the right rows only');

// ── 8. Same answer on every screen ──────────────────────────────────────────
section('8. Cross-screen consistency (missing fields)');
$csv = http_req('admin@test.invalid', 'GET', '/agent_audit_export.php')['body'];
$csvRows = [];
foreach (array_slice(array_map('str_getcsv', explode("\n", trim($csv))), 1) as $c) if (isset($c[1])) $csvRows[strtolower($c[1])] = $c;
$bad = [];
foreach (array_keys($F) as $email) {
    $ps = fresh_status($email);
    $lib = array_column($ps['missing'], 'label'); sort($lib);
    // login popup (as the agent)
    $pop = http_req($email, 'GET', '/api/profile_completeness.php')['json'];
    $popL = array_column($pop['missing'] ?? [], 'label'); sort($popL);
    // agent detail panel (admin)
    $det = (string)(http_req('admin@test.invalid', 'GET', '/api/agent_detail.php?email=' . urlencode($email))['json']['html'] ?? '');
    $detOk = strpos($det, h_label($ps)) !== false;
    // audit export (only rows it flags appear)
    $csvOk = true;
    if (isset($csvRows[$email])) {
        $cl = array_filter(explode('; ', $csvRows[$email][7] ?? '')); sort($cl);
        $csvOk = $csvRows[$email][6] === $ps['label'] && $cl === $lib;
    } elseif (!in_array($ps['status'], ['complete', 'submitted'], true)) { $csvOk = false; }
    if ($popL !== $lib || !$detOk || !$csvOk) $bad[] = $email . ' lib=' . implode(',', $lib) . ' popup=' . implode(',', $popL) . ' detail=' . ($detOk ? 'ok' : 'MISMATCH') . ' csv=' . ($csvOk ? 'ok' : 'MISMATCH');
}
function h_label(array $ps): string { return '">' . ($ps['track'] === 'onboarding' ? 'Onboarding · ' . $ps['label'] : $ps['label']); }
ok(!$bad, 'login popup, detail panel, audit export and shared rules agree for every agent', $bad);
$det = (string)(http_req('admin@test.invalid', 'GET', '/api/agent_detail.php?email=q.active.draft%40test.invalid')['json']['html'] ?? '');
ok(strpos($det, 'Send Completion Link') === false, 'no "Send Completion Link" button for an agent who is onboarding');
$det = (string)(http_req('admin@test.invalid', 'GET', '/api/agent_detail.php?email=imp.badbday%40test.invalid')['json']['html'] ?? '');
ok(strpos($det, 'Send Completion Link') !== false, '"Send Completion Link" still offered for an incomplete existing agent');

// ── 9. Needs Review holds ───────────────────────────────────────────────────
section('9. Needs Review holds');
$h = 'hold.complete@test.invalid';
$r = http_req($h, 'POST', '/api/agent_review_hold.php', ['action' => 'place', 'email' => $h, 'reason_code' => 'duplicate']);
ok($r['code'] === 403, 'an agent cannot place a hold', $r['body']);
ok(http_req($h, 'GET', '/api/agent_review_hold.php?email=' . urlencode($h))['code'] === 403, 'an agent cannot read holds');
$r = http_req('admin@test.invalid', 'POST', '/api/agent_review_hold.php', ['action' => 'place', 'email' => $h, 'reason_code' => 'nonsense']);
ok($r['code'] === 400, 'invalid reason rejected');
$r = http_req('admin@test.invalid', 'POST', '/api/agent_review_hold.php', ['action' => 'place', 'email' => $h, 'reason_code' => 'duplicate', 'note' => 'Shares phone with another profile']);
ok(($r['json']['ok'] ?? false) === true, 'admin places a hold', $r['body']);
$holdId = (int)($r['json']['hold_id'] ?? 0);
$r = http_req('admin@test.invalid', 'POST', '/api/agent_review_hold.php', ['action' => 'place', 'email' => $h, 'reason_code' => 'identity']);
ok(($r['json']['ok'] ?? true) === false, 'no second concurrent hold for the same agent');
$ps = fresh_status($h);
ok($ps['needs_review'] && $ps['display_label'] === 'Needs Review' && $ps['status'] === 'complete' && $ps['label'] === 'Complete', 'display = Needs Review; completeness still computed (Complete) underneath', $ps);
ok((int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$h]) === 0, 'placing a hold changed no agent record');
http_req($h, 'POST', '/api/intake.php', intake_post_body($h, ['full_name' => 'Test Holdcomplete', 'city' => '']));
http_req($h, 'POST', '/api/profile.php', ['full_name' => 'Test Holdcomplete', 'phone' => '5550100888']);
$ps = fresh_status($h);
ok($ps['needs_review'] && $ps['status'] === 'incomplete', 'agent profile edits do not remove the hold (completeness updates underneath)', $ps);
http_req($h, 'POST', '/api/intake.php', intake_post_body($h, ['full_name' => 'Test Holdcomplete']));
ok(fresh_status($h)['needs_review'] && fresh_status($h)['status'] === 'complete', 'still held after the agent completes the profile again');
tx("UPDATE agent_intake SET zip='' WHERE email=?", [$h]);
$tok = profile_link_issue($pdo, $h, 'admin@test.invalid');
http_req(null, 'POST', '/api/complete_profile_action.php', ['token' => $tok, 'fields' => ['zip' => '29000']]);
ok(fresh_status($h)['needs_review'] && fresh_status($h)['status'] === 'complete', 'completion-link save does not remove the hold');
http_req('admin@test.invalid', 'POST', '/api/agent_review_hold.php', ['action' => 'place', 'email' => 'hold.onb@test.invalid', 'reason_code' => 'identity', 'note' => 'Check email']);
$ps = fresh_status('hold.onb@test.invalid');
ok($ps['needs_review'] && $ps['track'] === 'onboarding' && $ps['status'] === 'draft', 'held onboarding agent: Needs Review shown, onboarding status (Draft) kept separately');
$page = http_req('admin@test.invalid', 'GET', '/backoffice_agents.php')['body'];
ok(preg_match('/data-tab="needs-review"[^>]*data-intake="0"\s+data-search="[^"]*hold\.complete@test\.invalid/', $page) === 1, 'Agents page: held agent sits in the Needs Review tab');
ok(strpos($page, 'Possible duplicate record') !== false, 'Agents page shows the hold reason under the badge');
preg_match('/<div class="rs-num">(\d+)<\/div>\s*<div class="rs-lbl">Needs Review<\/div>/', $page, $mm);
ok(($mm[1] ?? '') === '2', 'Needs Review tile counts 2', $mm);
$det = (string)(http_req('admin@test.invalid', 'GET', '/api/agent_detail.php?email=' . urlencode($h))['json']['html'] ?? '');
ok(strpos($det, 'Resolve hold') !== false && strpos($det, 'Needs Review') !== false, 'detail panel shows the hold with a Resolve action');
$r = http_req('admin@test.invalid', 'POST', '/api/agent_review_hold.php', ['action' => 'resolve', 'hold_id' => $holdId, 'note' => '']);
ok($r['code'] === 400 && fresh_status($h)['needs_review'], 'resolving requires a note');
$r = http_req($h, 'POST', '/api/agent_review_hold.php', ['action' => 'resolve', 'hold_id' => $holdId, 'note' => 'me']);
ok($r['code'] === 403 && fresh_status($h)['needs_review'], 'an agent cannot resolve their own hold');
$r = http_req('admin@test.invalid', 'POST', '/api/agent_review_hold.php', ['action' => 'resolve', 'hold_id' => $holdId, 'note' => 'Confirmed separate people']);
ok(($r['json']['ok'] ?? false) && !fresh_status($h)['needs_review'] && fresh_status($h)['display_label'] === 'Complete', 'admin resolves with a note → back to Complete');
$hist = http_req('admin@test.invalid', 'GET', '/api/agent_review_hold.php?email=' . urlencode($h))['json'];
ok(count($hist['history'] ?? []) === 1 && ($hist['history'][0]['resolution_note'] ?? '') === 'Confirmed separate people', 'resolved hold kept as history (audit trail)');

section('10. Review signals (suggestions only)');
$page = http_req('admin@test.invalid', 'GET', '/backoffice_agents.php')['body'];
$flagsOf = function ($email) use (&$page) { return preg_match('/data-flags="([^"]*)" data-intake="\d"\s+data-search="[^"]*' . preg_quote($email, '/') . '/', $page, $m) ? $m[1] : null; };
ok(strpos((string)$flagsOf('dup.one@test.invalid'), 'suggested_review') !== false && strpos((string)$flagsOf('dup.two@test.invalid'), 'suggested_review') !== false, 'same name + phone: both duplicates suggested for review');
ok(strpos((string)$flagsOf('imp.allfields@test.invalid'), 'suggested_review') === false, 'clean profile not suggested');
ok(fresh_status('dup.two@test.invalid')['display_label'] === 'Complete', 'a suggestion alone never changes the status (no hold placed)');
$det = (string)(http_req('admin@test.invalid', 'GET', '/api/agent_detail.php?email=dup.one%40test.invalid')['json']['html'] ?? '');
ok(strpos($det, 'Suggested for review') !== false && strpos($det, 'Place hold') !== false, 'detail panel shows the suggestion and a Place hold control');
tx("UPDATE agent_intake SET emergency_name = full_name WHERE email = 'imp.allfields@test.invalid'");
$page = http_req('admin@test.invalid', 'GET', '/backoffice_agents.php')['body'];
ok(strpos((string)$flagsOf('imp.allfields@test.invalid'), 'suggested_review') !== false, 'emergency contact = agent → suggested');
tx("UPDATE agent_intake SET emergency_name = 'Pat Example' WHERE email = 'imp.allfields@test.invalid'");

section('11. Seeding holds from a reviewed CSV (no names in code)');
$csv = '/tmp/holds-test.csv';
file_put_contents($csv, "email,reason_code,note\ndup.one@test.invalid,duplicate,Same name/phone as another profile\ndup.two@test.invalid,duplicate,Same name/phone as another profile\nhold.onb@test.invalid,identity,already held\n");
$seed = fn($a) => (string)shell_exec('php ' . APP_DIR . '/scripts/review_holds_seed.php --db=' . DATA_DIR . '/agentedge.db --csv=' . $csv . ' ' . $a . ' 2>&1');
$n0 = (int)tq1('SELECT COUNT(*) FROM agent_review_holds');
$o = $seed('');
ok((int)tq1('SELECT COUNT(*) FROM agent_review_holds') === $n0 && strpos($o, 'Dry run: 2 hold(s) would be placed, 1 already held') !== false, 'dry run changes nothing and reports what it would do', $o);
$o = $seed('--apply');
ok(strpos($o, '--by') !== false && (int)tq1('SELECT COUNT(*) FROM agent_review_holds') === $n0, '--apply requires --by (who is placing them)', $o);
$o = $seed('--apply --by=admin@test.invalid');
ok((int)tq1('SELECT COUNT(*) FROM agent_review_holds') === $n0 + 2 && fresh_status('dup.one@test.invalid')['needs_review'], 'apply places the 2 new holds (skips the already-held agent)', $o);
$seed('--apply --by=admin@test.invalid');
ok((int)tq1('SELECT COUNT(*) FROM agent_review_holds') === $n0 + 2, 're-running is safe (no duplicates)');
file_put_contents($csv, "email,reason_code,note\nx@test.invalid,bogus,\n");
ok(strpos($seed('--apply --by=admin@test.invalid'), 'Nothing applied') !== false, 'a bad CSV applies nothing');
ok(tq('SELECT email, submitted FROM agent_intake WHERE email IN (?,?) ORDER BY email', ['dup.one@test.invalid', 'dup.two@test.invalid']) == [['email' => 'dup.one@test.invalid', 'submitted' => 0], ['email' => 'dup.two@test.invalid', 'submitted' => 0]], 'seeding modified no agent records and merged nothing');
$code = '';
foreach (['lib/agent_profile.php', 'backoffice_agents.php', 'api/agent_review_hold.php', 'api/agent_detail.php', 'scripts/review_holds_seed.php'] as $f) $code .= file_get_contents(APP_DIR . '/' . $f);
ok(!preg_match('/[a-z0-9._%+-]+@(gmail|yahoo|hotmail|outlook|aol|icloud|comcast|msn|live)\./i', $code) && !preg_match('/\b(email|full_name)\s*(=|IN)\s*\(?\s*[\'"][^\'"]+@/i', $code), 'no agent emails or per-agent conditions hard-coded in the application');

section('12. Historical intake submission vs current completeness');
$e = 'hist.noemerg@test.invalid';
$ps = fresh_status($e);
ok($ps['intake_submitted'] && $ps['display_label'] === 'Incomplete' && missing_keys($ps['missing']) === ['emergency_name', 'emergency_phone'], 'submitted historically, shown Incomplete with the missing fields', $ps);
http_req($e, 'POST', '/api/profile.php', ['full_name' => 'Test AgentHist', 'phone' => '5550100777']);
ok((int)tq1('SELECT submitted FROM agent_intake WHERE email=?', [$e]) === 1 && tq1('SELECT submitted_at FROM agent_intake WHERE email=?', [$e]) === '2026-07-01 12:00:00', 'submitted flag and date preserved through edits');
$page = http_req('admin@test.invalid', 'GET', '/backoffice_agents.php')['body'];
ok(preg_match('/data-tab="incomplete"[^>]*data-intake="1"\s+data-search="[^"]*' . preg_quote($e, '/') . '/', $page) === 1, 'Agents page: Incomplete tab, intake-history filter value = submitted');
ok(strpos($page, 'Intake submitted Jul 1, 2026') !== false, 'Agents page shows the historical submission date under the status');
$det = (string)(http_req('admin@test.invalid', 'GET', '/api/agent_detail.php?email=' . urlencode($e))['json']['html'] ?? '');
ok(strpos($det, 'Intake submitted Jul 1, 2026 (historical)') !== false && strpos($det, 'missing Emergency Contact Name, Emergency Contact Phone') !== false, 'detail panel shows both: missing fields and historical submission');
$csvOut = http_req('admin@test.invalid', 'GET', '/agent_audit_export.php')['body'];
$hdr = str_getcsv(strtok($csvOut, "\n"));
ok(array_slice($hdr, -2) === ['Needs Review', 'Intake Submitted (historical)'] && array_slice($hdr, 0, 7) === ['Name', 'Email', 'Market Center', 'State', 'Missing Start Date', 'Missing Birthdate', 'Profile Status'], 'audit export: new columns appended, original columns unchanged', $hdr);

exit(summary());

