<?php
// An agent's Marketing Number and Office Number: the business lines INNOVATE's
// website shows instead of the cell in agent_intake.phone.
//
// Stored on agent_extra (marketing_number, office_number), next to the other
// self-service extras. The agent edits both on My Profile (profile.php), staff
// in the Edit Profile modal (agent_profile.php, backoffice_agents.php); every
// one of those saves through api/agent_extra.php, which validates with
// us_phone_normalize() below. api/agent_profile_export.php sends them to the
// website's nightly sync as marketing_phone / office_phone
// (innovate-advantage-server PR #216, migration 355). The website shows the
// Marketing Number, else the cell; the Office Number fills its office line.
if (defined('AGENTEDGE_AGENT_PHONES_LOADED')) return;
define('AGENTEDGE_AGENT_PHONES_LOADED', true);

// Adds the two columns when they are missing. Same once-per-request,
// fast-path-is-read-only shape as local_db_ensure_onboard_stages(): a PRAGMA
// on every call, an ALTER only on the first request after deploy. Kept here
// instead of in local_db()'s always-run block so this change doesn't have to
// carry local_db.php, which has drifted far between main and the live box.
// The live local_db.php already adds marketing_number (from the self-service
// field that shipped first); the ALTER for it here only runs on databases
// that don't have it.
function agent_phones_ensure_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = array_column($pdo->query("PRAGMA table_info(agent_extra)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($cols) {
            foreach (['marketing_number', 'office_number'] as $col) {
                if (!in_array($col, $cols, true)) {
                    // Another request may add it between the PRAGMA and here:
                    // "duplicate column" is the only error to expect, so ignore it.
                    try { $pdo->exec("ALTER TABLE agent_extra ADD COLUMN $col TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
                }
            }
        }
        $done = true;
    } catch (\Throwable $e) {
        error_log('agent_phones_ensure_columns failed: ' . $e->getMessage());
    }
}

// A US phone number in the format AgentEdge's phone fields show:
// "(843) 555-1234". Accepts any punctuation and a leading +1/1.
//   ''      blank input (the agent has no such number)
//   string  the formatted number
//   null    anything else: not 10 digits, or an area code / exchange that
//           starts with 0 or 1 (no US number does)
function us_phone_normalize(string $raw): ?string {
    if (trim($raw) === '') return '';
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    if (!preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $d)) return null;
    return '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6);
}

// For the export: the formatted number when it is a valid US number, else the
// stored text as is. Values saved before the strict check (Nov 2026) can be
// anything; the website treats fewer than 10 digits as "no number".
function us_phone_for_export(?string $stored): string {
    $stored = trim((string)$stored);
    return us_phone_normalize($stored) ?? $stored;
}
