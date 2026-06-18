<?php
// AgentEdge-owned SQLite database — settings, link configs, agent notes, etc.
// Stored at data/agentedge.db (protected by data/.htaccess; never web-served).
if (defined('AGENTEDGE_LOCAL_DB_LOADED')) return;
define('AGENTEDGE_LOCAL_DB_LOADED', true);

function local_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);

    $pdo = new PDO('sqlite:' . $dir . '/agentedge.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL");

    // External nav links (editable by super_admin)
    $pdo->exec("CREATE TABLE IF NOT EXISTS nav_ext_links (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        key      TEXT    UNIQUE NOT NULL,
        label    TEXT    NOT NULL,
        url      TEXT    NOT NULL DEFAULT '#',
        sort_ord INTEGER NOT NULL DEFAULT 0,
        enabled  INTEGER NOT NULL DEFAULT 1
    )");

    // Market-center resource links (MLS, state tools, etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS mc_resource_links (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        mc_slug  TEXT    NOT NULL,
        label    TEXT    NOT NULL,
        url      TEXT    NOT NULL DEFAULT '#',
        sort_ord INTEGER NOT NULL DEFAULT 0,
        enabled  INTEGER NOT NULL DEFAULT 1
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mc_slug ON mc_resource_links(mc_slug)");

    // Open House Portal tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS oh_listings (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        mls_number          TEXT,
        address             TEXT    NOT NULL,
        city                TEXT    NOT NULL,
        state               TEXT    NOT NULL DEFAULT 'SC',
        zip                 TEXT,
        property_type       TEXT    NOT NULL DEFAULT 'Residential',
        list_price          INTEGER,
        listing_agent_email TEXT    NOT NULL,
        listing_agent_name  TEXT,
        image_url           TEXT,
        vacate              INTEGER NOT NULL DEFAULT 0,
        visible             INTEGER NOT NULL DEFAULT 1,
        created_at          TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oh_slots (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        listing_id  INTEGER NOT NULL,
        slot_date   TEXT    NOT NULL,
        start_time  TEXT    NOT NULL,
        end_time    TEXT    NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oh_requests (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        slot_id      INTEGER NOT NULL,
        listing_id   INTEGER NOT NULL,
        agent_email  TEXT    NOT NULL,
        agent_name   TEXT,
        status       TEXT    NOT NULL DEFAULT 'pending',
        reason       TEXT,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oh_prefs (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    // DotLoop OAuth tokens — one row per connected agent
    $pdo->exec("CREATE TABLE IF NOT EXISTS dotloop_tokens (
        agent_email   TEXT PRIMARY KEY,
        profile_id    TEXT,
        access_token  TEXT,
        refresh_token TEXT,
        expires_at    INTEGER
    )");

    // Onboarding queue — one row per agent being onboarded
    $pdo->exec("CREATE TABLE IF NOT EXISTS onboard_queue (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email   TEXT    NOT NULL,
        agent_name    TEXT    NOT NULL,
        market_center TEXT,
        start_date    TEXT,
        sponsor       TEXT,
        role          TEXT    NOT NULL DEFAULT 'agent',
        added_by      TEXT    NOT NULL,
        added_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        status        TEXT    NOT NULL DEFAULT 'active',
        notes         TEXT
    )");

    // Per-step provisioning status for each queued agent
    $pdo->exec("CREATE TABLE IF NOT EXISTS onboard_steps (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        queue_id    INTEGER NOT NULL,
        tool_key    TEXT    NOT NULL,
        tool_label  TEXT    NOT NULL,
        is_auto     INTEGER NOT NULL DEFAULT 0,
        status      TEXT    NOT NULL DEFAULT 'pending',
        done_by     TEXT,
        done_at     TEXT,
        error_msg   TEXT,
        UNIQUE(queue_id, tool_key)
    )");

    // Role assignments — AgentEdge is the source of truth for role + MC scope.
    // Other apps (intranet, CRM) call /api/permissions.php to read this.
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_roles (
        email      TEXT PRIMARY KEY,
        role       TEXT NOT NULL DEFAULT 'agent',
        mc_slugs   TEXT NOT NULL DEFAULT '[]',
        updated_by TEXT,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // Per-agent extra fields: birthday, hire date, license renewal.
    // birthday and license_renewal are stored as MM-DD so they recur every year.
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_extra (
        email            TEXT PRIMARY KEY,
        birthday         TEXT NOT NULL DEFAULT '',   -- MM-DD (e.g. 06-15)
        hire_date        TEXT NOT NULL DEFAULT '',   -- YYYY-MM-DD (start / work anniversary)
        license_renewal  TEXT NOT NULL DEFAULT '',   -- MM-DD (annual renewal reminder)
        updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // Agents imported via CSV upload (not yet in CRM).
    $pdo->exec("CREATE TABLE IF NOT EXISTS imported_agents (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL DEFAULT '',
        email       TEXT    UNIQUE NOT NULL,
        phone       TEXT    NOT NULL DEFAULT '',
        mc_slug     TEXT    NOT NULL DEFAULT '',
        imported_by TEXT    NOT NULL DEFAULT '',
        imported_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Seed nav_ext_links from defaults
    if ($pdo->query("SELECT COUNT(*) FROM nav_ext_links")->fetchColumn() == 0) {
        $seed = [
            ['transactions', 'Transactions',    '#',                   10],
            ['maxa',         'MAXA Marketing',  'https://app.maxa.io', 20],
            ['swag',         'Swag Shop',       '#',                   30],
            ['openhouse',    'Open House Pool', '#',                   40],
            ['support',      'Agent Support',   '#',                   50],
            ['kb',           'Knowledge Base',  '#',                   60],
        ];
        $ins = $pdo->prepare("INSERT INTO nav_ext_links (key,label,url,sort_ord) VALUES (?,?,?,?)");
        foreach ($seed as $r) $ins->execute($r);
    }

    // Seed mc_resource_links from mc_links.php config
    if ($pdo->query("SELECT COUNT(*) FROM mc_resource_links")->fetchColumn() == 0) {
        $all = require __DIR__ . '/mc_links.php';
        $ins = $pdo->prepare("INSERT INTO mc_resource_links (mc_slug,label,url,sort_ord) VALUES (?,?,?,?)");
        foreach ($all as $slug => $links) {
            foreach ($links as $i => $link) {
                $ins->execute([$slug, $link['label'], $link['url'], ($i + 1) * 10]);
            }
        }
    }

    return $pdo;
}

// All enabled external nav links, ordered for sidebar display.
function nav_ext_links_all(): array {
    return local_db()
        ->query("SELECT * FROM nav_ext_links WHERE enabled=1 ORDER BY sort_ord,id")
        ->fetchAll(PDO::FETCH_ASSOC);
}

// MC-specific links for a given slug — only returns rows with real URLs.
function mc_resource_links_for(string $slug): array {
    $s = local_db()->prepare(
        "SELECT label,url FROM mc_resource_links
         WHERE mc_slug=? AND enabled=1 AND url != '#' ORDER BY sort_ord,id"
    );
    $s->execute([$slug]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
