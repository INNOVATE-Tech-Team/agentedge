<?php
// Polls — schema and first-poll seed.
//
// Kept out of local_db.php on purpose: the live DB's schema-version gate is
// permanently closed (see the NOTE in local_db()), so new tables are created
// by cheap, idempotent statements that run on every connection. local_db()
// only needs to require this file and call polls_ensure_schema().
//
// Everything about a poll is data, so a future Back Office editor is plain
// CRUD on these four tables:
//   polls          one row per poll (status draft/open/closed, optional dates)
//   poll_steps     ordered steps; type 'allocate' or 'multi_select'
//   poll_options   options belonging to a step
//   poll_responses one row per agent per poll, answers as JSON
if (defined('AGENTEDGE_POLLS_SCHEMA_LOADED')) return;
define('AGENTEDGE_POLLS_SCHEMA_LOADED', true);

function polls_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS polls (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        slug        TEXT    NOT NULL UNIQUE,
        title       TEXT    NOT NULL,
        subtitle    TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','open','closed')),
        opens_at    TEXT    NOT NULL DEFAULT '',   -- UTC 'Y-m-d H:i:s' or ''
        closes_at   TEXT    NOT NULL DEFAULT '',   -- UTC 'Y-m-d H:i:s' or ''
        config_json TEXT    NOT NULL DEFAULT '{}', -- eyebrow, header label, thank-you copy
        created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS poll_steps (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        poll_id     INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        step_key    TEXT    NOT NULL,
        position    INTEGER NOT NULL DEFAULT 0,
        type        TEXT    NOT NULL CHECK (type IN ('allocate','multi_select')),
        title       TEXT    NOT NULL DEFAULT '',
        body        TEXT    NOT NULL DEFAULT '',   -- lede copy under the title
        config_json TEXT    NOT NULL DEFAULT '{}', -- allocate: {bills,value}; multi_select: {notice}
        UNIQUE (poll_id, step_key)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS poll_options (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        step_id     INTEGER NOT NULL REFERENCES poll_steps(id) ON DELETE CASCADE,
        opt_key     TEXT    NOT NULL,
        label       TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        position    INTEGER NOT NULL DEFAULT 0,
        UNIQUE (step_id, opt_key)
    )");
    // user_id is the stable Perfex staff id ('s123') when the session carries
    // one, else 'e:<lowercased email>'. email is ALSO unique per poll so the
    // same person cannot vote twice by logging in through two methods (one
    // with a staff id, one without). display_name is a snapshot.
    $pdo->exec("CREATE TABLE IF NOT EXISTS poll_responses (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        poll_id      INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        user_id      TEXT    NOT NULL,
        email        TEXT    NOT NULL,
        display_name TEXT    NOT NULL,
        is_staff     INTEGER NOT NULL DEFAULT 0,
        answers_json TEXT    NOT NULL,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE (poll_id, user_id),
        UNIQUE (poll_id, email)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_poll_responses_poll ON poll_responses(poll_id)");
    // Remembers which slugs have ever been seeded so a poll an admin later
    // deletes is not silently resurrected on the next request.
    $pdo->exec("CREATE TABLE IF NOT EXISTS poll_seed_log (
        slug      TEXT PRIMARY KEY,
        seeded_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    polls_seed_defaults($pdo);
}

// Poll definitions shipped with the app. Add more entries here only for
// polls that should exist on every install; one-off polls can be added by
// hand (docs/polls-howto.md).
function polls_seed_definitions(): array {
    return [[
        'slug'     => 'spend-100',
        'title'    => 'Spend $100',
        'subtitle' => 'INNOVATE App workshop',
        'status'   => 'draft',   // nothing is visible to agents until opened in Back Office
        'config'   => [
            'eyebrow'        => 'Live on Zoom · Everyone votes',
            'header'         => 'INNOVATE App workshop',
            'thanks_eyebrow' => 'The INNOVATE App workshop',
            'footer'         => 'Built by INNOVATE agents, for INNOVATE agents. Starting today.',
        ],
        'steps' => [
            [
                'key'    => 'spend',
                'type'   => 'allocate',
                'title'  => 'Spend $100',
                'pill'   => 'Spend $100',
                'body'   => 'You get 5 bills. Each one is worth $20. Drag them onto the areas you want built first. Stack them all on one if it matters that much.',
                'config' => ['bills' => 5, 'value' => 20],
                'options' => [
                    ['leads',        'Leads and clients',      ''],
                    ['listings',     'Listings and marketing', ''],
                    ['transactions', 'Transactions',           ''],
                    ['money',        'Money and business',     ''],
                    ['community',    'Community and growth',   ''],
                ],
            ],
            [
                'key'    => 'next',
                'type'   => 'multi_select',
                'title'  => 'Build it with us',
                'pill'   => 'Build it with us',
                'body'   => 'Tap everything that sounds good. You can pick more than one, or none.',
                'config' => ['notice' => 'Send ideas anytime. Email darren@innovateonline.com with the one thing you’d build.'],
                'options' => [
                    ['council', 'Join the app council', 'A small group of agents who review ideas monthly and see every screen first.'],
                    ['beta',    'Be a beta tester',     'Get early versions on your phone and tell us what breaks.'],
                ],
            ],
        ],
    ]];
}

// Idempotent by slug. A poll that already exists (edited or not) is never
// touched, and a slug that was seeded once is never re-created.
function polls_seed_defaults(PDO $pdo): void {
    foreach (polls_seed_definitions() as $def) {
        $chk = $pdo->prepare("SELECT 1 FROM polls WHERE slug = ? UNION ALL SELECT 1 FROM poll_seed_log WHERE slug = ?");
        $chk->execute([$def['slug'], $def['slug']]);
        if ($chk->fetchColumn()) continue;

        $pdo->exec("BEGIN IMMEDIATE");
        try {
            // Re-check inside the write lock so two concurrent first requests
            // cannot both seed.
            $chk->execute([$def['slug'], $def['slug']]);
            if ($chk->fetchColumn()) { $pdo->exec("COMMIT"); continue; }

            $pdo->prepare("INSERT INTO polls (slug,title,subtitle,status,config_json) VALUES (?,?,?,?,?)")
                ->execute([$def['slug'], $def['title'], $def['subtitle'], $def['status'], json_encode($def['config'], JSON_UNESCAPED_UNICODE)]);
            $pollId = (int)$pdo->lastInsertId();
            foreach ($def['steps'] as $si => $st) {
                $cfg = $st['config'] + ['pill' => $st['pill']];
                $pdo->prepare("INSERT INTO poll_steps (poll_id,step_key,position,type,title,body,config_json) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$pollId, $st['key'], $si + 1, $st['type'], $st['title'], $st['body'], json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
                $stepId = (int)$pdo->lastInsertId();
                foreach ($st['options'] as $oi => [$k, $label, $desc]) {
                    $pdo->prepare("INSERT INTO poll_options (step_id,opt_key,label,description,position) VALUES (?,?,?,?,?)")
                        ->execute([$stepId, $k, $label, $desc, $oi + 1]);
                }
            }
            $pdo->prepare("INSERT INTO poll_seed_log (slug) VALUES (?)")->execute([$def['slug']]);
            $pdo->exec("COMMIT");
        } catch (\Throwable $e) {
            try { $pdo->exec("ROLLBACK"); } catch (\Throwable $ignore) {}
            // Never take the whole app down because a seed failed.
            error_log('polls_seed_defaults(' . $def['slug'] . '): ' . $e->getMessage());
        }
    }
}
