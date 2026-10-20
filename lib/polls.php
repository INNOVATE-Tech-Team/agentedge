<?php
// Polls — shared logic for the agent pages, the aggregate endpoint and the
// Back Office. Pure functions over a PDO handle where possible, so the
// validation and one-vote rules are testable without a web session
// (tests/polls_test.php).
if (defined('AGENTEDGE_POLLS_LOADED')) return;
define('AGENTEDGE_POLLS_LOADED', true);

require_once __DIR__ . '/polls_schema.php';

const POLL_STATUSES   = ['draft', 'open', 'closed'];
const POLL_STEP_TYPES = ['allocate', 'multi_select'];

function polls_now_utc(): string { return gmdate('Y-m-d H:i:s'); }

// ── Loading ─────────────────────────────────────────────────────────────────

// A poll with its steps and options, or null. Option/step order is stable.
function polls_load(PDO $pdo, string $slug): ?array {
    $st = $pdo->prepare("SELECT * FROM polls WHERE slug = ?");
    $st->execute([$slug]);
    $poll = $st->fetch(PDO::FETCH_ASSOC);
    return $poll ? polls_hydrate($pdo, $poll) : null;
}

function polls_load_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM polls WHERE id = ?");
    $st->execute([$id]);
    $poll = $st->fetch(PDO::FETCH_ASSOC);
    return $poll ? polls_hydrate($pdo, $poll) : null;
}

function polls_hydrate(PDO $pdo, array $poll): array {
    $poll['id'] = (int)$poll['id'];
    $poll['config'] = json_decode($poll['config_json'] ?: '{}', true) ?: [];
    $steps = $pdo->prepare("SELECT * FROM poll_steps WHERE poll_id = ? ORDER BY position, id");
    $steps->execute([$poll['id']]);
    $poll['steps'] = [];
    $optSt = $pdo->prepare("SELECT opt_key, label, description FROM poll_options WHERE step_id = ? ORDER BY position, id");
    foreach ($steps->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $s['id'] = (int)$s['id'];
        $s['config'] = json_decode($s['config_json'] ?: '{}', true) ?: [];
        $optSt->execute([$s['id']]);
        $s['options'] = $optSt->fetchAll(PDO::FETCH_ASSOC);
        $poll['steps'][] = $s;
    }
    return $poll;
}

// ── Open / closed ───────────────────────────────────────────────────────────

// Open = status 'open' AND inside the optional open/close window. Dates are
// UTC strings, which sort lexicographically.
function polls_is_open(array $poll, ?string $nowUtc = null): bool {
    $now = $nowUtc ?? polls_now_utc();
    if (($poll['status'] ?? '') !== 'open') return false;
    if (($poll['opens_at'] ?? '') !== '' && $poll['opens_at'] > $now) return false;
    if (($poll['closes_at'] ?? '') !== '' && $poll['closes_at'] <= $now) return false;
    return true;
}

// ── Identity ────────────────────────────────────────────────────────────────

// Roster membership decides "agent" vs "staff": an active innovate_roster row
// for the login email is an agent; anyone else (super admins, office staff)
// is staff and is excluded from agent-facing totals.
function polls_is_staff(PDO $pdo, string $email): bool {
    $st = $pdo->prepare("SELECT 1 FROM innovate_roster WHERE LOWER(TRIM(email)) = ? AND active = 1 LIMIT 1");
    $st->execute([strtolower(trim($email))]);
    return !$st->fetchColumn();
}

// Stable voter identity from the session agent array (auth.php):
// the Perfex staff id if present (> 0), else the lowercased email.
function polls_identity(PDO $pdo, array $agent): ?array {
    $email = strtolower(trim((string)($agent['email'] ?? '')));
    if ($email === '') return null;           // cannot enforce one-vote without it
    $id = (int)($agent['id'] ?? 0);
    $name = trim((string)($agent['name'] ?? ''));
    return [
        'user_id'      => $id > 0 ? 's' . $id : 'e:' . $email,
        'email'        => $email,
        'display_name' => $name !== '' ? $name : $email,
        'is_staff'     => polls_is_staff($pdo, $email) ? 1 : 0,
    ];
}

function polls_find_response(PDO $pdo, int $pollId, array $identity): ?array {
    $st = $pdo->prepare("SELECT * FROM poll_responses WHERE poll_id = ? AND (user_id = ? OR email = ?) LIMIT 1");
    $st->execute([$pollId, $identity['user_id'], $identity['email']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['answers'] = json_decode($r['answers_json'], true) ?: [];
    return $r;
}

// ── Validation ──────────────────────────────────────────────────────────────

// Validates an `answers` payload against the poll definition:
//   {"<step_key>": {"alloc": {"<opt_key>": int}}}   for allocate
//   {"<step_key>": {"picks": ["<opt_key>", ...]}}   for multi_select
// Returns ['ok'=>bool, 'error'=>string, 'answers'=>normalized]. Normalized
// answers contain every step and (for allocate) every option, so stored rows
// have one shape no matter what the client sent. Strict on types: only real
// non-negative integers count as bills.
function polls_validate_answers(array $poll, $answers): array {
    $fail = fn(string $m) => ['ok' => false, 'error' => $m, 'answers' => []];
    if (!is_array($answers)) return $fail('Answers must be an object.');

    $stepKeys = array_column($poll['steps'], 'step_key');
    foreach (array_keys($answers) as $k) {
        if (!in_array((string)$k, $stepKeys, true)) return $fail('Unknown step.');
    }

    $out = [];
    foreach ($poll['steps'] as $step) {
        $key = $step['step_key'];
        if (!isset($answers[$key]) || !is_array($answers[$key])) return $fail('Missing answers for step "' . $key . '".');
        $a = $answers[$key];
        $optKeys = array_column($step['options'], 'opt_key');

        if ($step['type'] === 'allocate') {
            $bills = (int)($step['config']['bills'] ?? 0);
            if (!isset($a['alloc']) || !is_array($a['alloc'])) return $fail('Missing allocation.');
            $norm = array_fill_keys($optKeys, 0);
            $sum = 0;
            foreach ($a['alloc'] as $optKey => $n) {
                if (!in_array((string)$optKey, $optKeys, true)) return $fail('Unknown option.');
                if (!is_int($n) || $n < 0 || $n > $bills) return $fail('Invalid bill count.');
                $norm[(string)$optKey] = $n;
                $sum += $n;
            }
            if ($sum !== $bills) return $fail('You must spend exactly ' . $bills . ' bills.');
            $out[$key] = ['alloc' => $norm];
        } elseif ($step['type'] === 'multi_select') {
            $picks = $a['picks'] ?? null;
            if (!is_array($picks) || !array_is_list($picks)) return $fail('Invalid selection.');
            $seen = [];
            foreach ($picks as $p) {
                if (!is_string($p) || !in_array($p, $optKeys, true)) return $fail('Unknown option.');
                if (isset($seen[$p])) return $fail('Duplicate option.');
                $seen[$p] = true;
            }
            // Store in the poll's option order for stable output.
            $out[$key] = ['picks' => array_values(array_filter($optKeys, fn($k) => isset($seen[$k])))];
        } else {
            return $fail('Unsupported step type.');
        }
    }
    return ['ok' => true, 'error' => '', 'answers' => $out];
}

// ── Submit ──────────────────────────────────────────────────────────────────

// Full server-side submit: poll open, not already voted, answers valid, then
// insert. The unique indexes make the one-vote rule hold even under a race:
// the loser of a double-submit gets 'already_voted', never a second row.
// Returns ['ok'=>bool, 'code'=>string, 'error'=>string].
function polls_submit(PDO $pdo, array $poll, array $identity, $answers, ?string $nowUtc = null): array {
    if (!polls_is_open($poll, $nowUtc)) {
        return ['ok' => false, 'code' => 'closed', 'error' => 'This poll is not open.'];
    }
    if (polls_find_response($pdo, $poll['id'], $identity)) {
        return ['ok' => false, 'code' => 'already_voted', 'error' => 'You have already responded to this poll.'];
    }
    $v = polls_validate_answers($poll, $answers);
    if (!$v['ok']) return ['ok' => false, 'code' => 'invalid', 'error' => $v['error']];

    try {
        $pdo->prepare("INSERT INTO poll_responses (poll_id,user_id,email,display_name,is_staff,answers_json,created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute([
                $poll['id'], $identity['user_id'], $identity['email'], $identity['display_name'],
                (int)$identity['is_staff'], json_encode($v['answers'], JSON_UNESCAPED_UNICODE),
                $nowUtc ?? polls_now_utc(),
            ]);
    } catch (\PDOException $e) {
        // SQLSTATE 23000 = integrity constraint violation (the unique indexes).
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'code' => 'already_voted', 'error' => 'You have already responded to this poll.'];
        }
        throw $e;
    }
    return ['ok' => true, 'code' => 'saved', 'error' => ''];
}

// ── Aggregates ──────────────────────────────────────────────────────────────

// Per-option totals across responses. $includeStaff=false is the agent-facing
// mode. Returns only numbers and poll-definition labels: nothing here reads
// or returns a name, email or user id.
function polls_totals(PDO $pdo, array $poll, bool $includeStaff = false): array {
    $sql = "SELECT answers_json FROM poll_responses WHERE poll_id = ?" . ($includeStaff ? '' : ' AND is_staff = 0');
    $st = $pdo->prepare($sql);
    $st->execute([$poll['id']]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);

    $steps = [];
    foreach ($poll['steps'] as $step) {
        $opts = [];
        foreach ($step['options'] as $o) {
            $opts[$o['opt_key']] = ['key' => $o['opt_key'], 'label' => $o['label'], 'dollars' => 0, 'people' => 0];
        }
        $steps[$step['step_key']] = ['step_key' => $step['step_key'], 'type' => $step['type'], 'title' => $step['title'], 'options' => $opts];
    }
    $value = [];
    foreach ($poll['steps'] as $s) $value[$s['step_key']] = (int)($s['config']['value'] ?? 0);

    foreach ($rows as $json) {
        $a = json_decode($json, true);
        if (!is_array($a)) continue;
        foreach ($steps as $sk => &$s) {
            if (!isset($a[$sk])) continue;
            if ($s['type'] === 'allocate') {
                foreach (($a[$sk]['alloc'] ?? []) as $ok => $n) {
                    if (!isset($s['options'][$ok]) || !is_int($n) || $n <= 0) continue;
                    $s['options'][$ok]['dollars'] += $n * $value[$sk];
                    $s['options'][$ok]['people']++;
                }
            } else {
                foreach (($a[$sk]['picks'] ?? []) as $ok) {
                    if (isset($s['options'][$ok])) $s['options'][$ok]['people']++;
                }
            }
        }
        unset($s);
    }
    foreach ($steps as &$s) $s['options'] = array_values($s['options']);
    unset($s);
    return ['voters' => count($rows), 'steps' => array_values($steps)];
}

// The only thing an agent-facing API may return about results. Gated on the
// caller having responded themselves; staff are excluded from the numbers.
// Built from an explicit whitelist so no person-level field can leak in.
function polls_public_aggregate(PDO $pdo, array $poll, array $identity): ?array {
    if (!polls_find_response($pdo, $poll['id'], $identity)) return null;
    $t = polls_totals($pdo, $poll, false);
    $steps = [];
    foreach ($t['steps'] as $s) {
        $opts = [];
        foreach ($s['options'] as $o) {
            $opts[] = ['key' => $o['key'], 'label' => $o['label'], 'dollars' => (int)$o['dollars'], 'people' => (int)$o['people']];
        }
        $steps[] = ['step_key' => $s['step_key'], 'type' => $s['type'], 'title' => $s['title'], 'options' => $opts];
    }
    return ['voters' => (int)$t['voters'], 'steps' => $steps];
}

// ── Presentation helpers ────────────────────────────────────────────────────

// Plain-text summary of one response, same shape the prototype copied to the
// clipboard: name, "$X on Option" lines (largest first), then picks.
function polls_response_text(array $poll, array $resp): string {
    $lines = [$resp['display_name'] . (!empty($resp['is_staff']) ? ' (staff)' : '')];
    $picks = [];
    foreach ($poll['steps'] as $step) {
        $a = $resp['answers'][$step['step_key']] ?? [];
        $labels = array_column($step['options'], 'label', 'opt_key');
        if ($step['type'] === 'allocate') {
            $value = (int)($step['config']['value'] ?? 0);
            $alloc = $a['alloc'] ?? [];
            arsort($alloc);
            foreach ($alloc as $k => $n) if ($n > 0) $lines[] = '$' . ($n * $value) . ' on ' . ($labels[$k] ?? $k);
        } else {
            foreach (($a['picks'] ?? []) as $k) $picks[] = $labels[$k] ?? $k;
        }
    }
    $lines[] = 'Next steps: ' . ($picks ? implode(', ', $picks) : 'none picked');
    return implode("\n", $lines);
}

// Every response for a poll with decoded answers — superadmin routes only.
function polls_all_responses(PDO $pdo, int $pollId): array {
    $st = $pdo->prepare("SELECT * FROM poll_responses WHERE poll_id = ? ORDER BY created_at, id");
    $st->execute([$pollId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['answers'] = json_decode($r['answers_json'], true) ?: [];
    return $rows;
}

// Active roster agents (name + email) who have no response to this poll.
function polls_not_voted(PDO $pdo, int $pollId): array {
    $st = $pdo->prepare(
        "SELECT r.agent_name AS name, LOWER(TRIM(r.email)) AS email
           FROM innovate_roster r
          WHERE r.active = 1 AND TRIM(r.email) <> ''
            AND NOT EXISTS (SELECT 1 FROM poll_responses p WHERE p.poll_id = ? AND p.email = LOWER(TRIM(r.email)))
          GROUP BY LOWER(TRIM(r.email))
          ORDER BY r.agent_name COLLATE NOCASE"
    );
    $st->execute([$pollId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// RFC-4180 CSV of responses: one row per person, a column per allocate option
// (dollars) and per multi_select option (Yes/blank). Cells starting with
// = + - @ are prefixed with a quote so a name can't run as a spreadsheet
// formula.
function polls_csv(array $poll, array $responses): string {
    $esc = function ($v): string {
        $v = (string)$v;
        if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $v = "'" . $v;
        return '"' . str_replace('"', '""', $v) . '"';
    };
    $head = ['Name', 'Email', 'Staff', 'Submitted (UTC)'];
    foreach ($poll['steps'] as $s) foreach ($s['options'] as $o) {
        $head[] = $s['title'] . ' — ' . $o['label'] . ($s['type'] === 'allocate' ? ' ($)' : '');
    }
    $out = [implode(',', array_map($esc, $head))];
    foreach ($responses as $r) {
        $row = [$r['display_name'], $r['email'], $r['is_staff'] ? 'staff' : '', $r['created_at']];
        foreach ($poll['steps'] as $s) {
            $a = $r['answers'][$s['step_key']] ?? [];
            $value = (int)($s['config']['value'] ?? 0);
            foreach ($s['options'] as $o) {
                if ($s['type'] === 'allocate') $row[] = (int)($a['alloc'][$o['opt_key']] ?? 0) * $value;
                else $row[] = in_array($o['opt_key'], $a['picks'] ?? [], true) ? 'Yes' : '';
            }
        }
        $out[] = implode(',', array_map($esc, $row));
    }
    return implode("\r\n", $out) . "\r\n";
}
