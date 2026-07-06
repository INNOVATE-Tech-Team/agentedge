<?php
// AgentEdge-owned SQLite database — settings, link configs, agent notes, etc.
// By default stored at data/agentedge.db inside the app folder, but you can
// move it outside the git/deploy directory by setting 'local_db_dir' in config.php
// (e.g. '/home/ec2-user/agentedge-data'). That way deploys never wipe the database.
if (defined('AGENTEDGE_LOCAL_DB_LOADED')) return;
define('AGENTEDGE_LOCAL_DB_LOADED', true);

function local_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Allow config.php to point the db at a directory outside the repo so deploys
    // (git pull / SCP / zip-extract) can never accidentally wipe the database.
    $cfgDir = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dir = $cfgDir ?: (__DIR__ . '/data');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $pdo = new PDO('sqlite:' . $dir . '/agentedge.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL");

    // External nav links (editable by super_admin)
    $pdo->exec("CREATE TABLE IF NOT EXISTS nav_ext_links (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        key         TEXT    UNIQUE NOT NULL,
        label       TEXT    NOT NULL,
        url         TEXT    NOT NULL DEFAULT '#',
        sort_ord    INTEGER NOT NULL DEFAULT 0,
        enabled     INTEGER NOT NULL DEFAULT 1,
        group_label TEXT    NOT NULL DEFAULT 'Links'
    )");
    // Migrate existing installs that predate the group_label column
    try { $pdo->exec("ALTER TABLE nav_ext_links ADD COLUMN group_label TEXT NOT NULL DEFAULT 'Links'"); } catch (\Exception $e) {}

    // Sort order for the hardcoded core pages (Dashboard, Roster, etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS nav_core_order (
        key      TEXT    PRIMARY KEY,
        sort_ord INTEGER NOT NULL DEFAULT 0
    )");
    if ($pdo->query("SELECT COUNT(*) FROM nav_core_order")->fetchColumn() == 0) {
        $ins = $pdo->prepare("INSERT OR IGNORE INTO nav_core_order (key,sort_ord) VALUES (?,?)");
        foreach ([['roster',20],['market_centers',25],['network',30],['industry_events',35],['intake',40],['calendar',50],['profile',60],['vault',78],['university',85],['tickets',90],['listing_intel',95]] as $r) {
            $ins->execute($r);
        }
    }
    // Ensure rows exist on existing installs
    $pdo->prepare("INSERT OR IGNORE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute(['vault',78]);
    $pdo->prepare("INSERT OR IGNORE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute(['university',85]);
    $pdo->prepare("INSERT OR IGNORE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute(['market_centers',25]);
    $pdo->prepare("INSERT OR IGNORE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute(['intake',40]);
    $pdo->prepare("INSERT OR IGNORE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute(['listing_intel',95]);
    $pdo->prepare("INSERT OR IGNORE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute(['network',30]);
    $pdo->prepare("INSERT OR IGNORE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute(['industry_events',35]);

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
    // Migration: add tracking columns to existing installs (no-op if already present)
    try { $pdo->exec("ALTER TABLE onboard_queue ADD COLUMN state_code         TEXT"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE onboard_queue ADD COLUMN canonical_agent_id TEXT"); } catch (\Exception $e) {}

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

    // Offboarding queue — one row per agent being offboarded
    $pdo->exec("CREATE TABLE IF NOT EXISTS offboard_queue (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email     TEXT    NOT NULL,
        agent_name      TEXT    NOT NULL,
        market_center   TEXT,
        last_day        TEXT,
        reason          TEXT    NOT NULL DEFAULT 'voluntary',
        reason_notes    TEXT,
        book_of_biz_to  TEXT,
        added_by        TEXT    NOT NULL,
        added_at        TEXT    NOT NULL DEFAULT (datetime('now')),
        status          TEXT    NOT NULL DEFAULT 'active'
    )");

    // Per-step deprovisioning status for each offboarding agent
    $pdo->exec("CREATE TABLE IF NOT EXISTS offboard_steps (
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
        email        TEXT PRIMARY KEY,
        role         TEXT NOT NULL DEFAULT 'agent',
        mc_slugs     TEXT NOT NULL DEFAULT '[]',  -- MCs this user leads (mc_leader/bic)
        own_mc_slug  TEXT NOT NULL DEFAULT '',    -- MC this agent belongs to
        bic_email    TEXT NOT NULL DEFAULT '',    -- BIC assigned to this agent
        updated_by   TEXT,
        updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    // Migrations for existing installs
    try { $pdo->exec("ALTER TABLE agent_roles ADD COLUMN own_mc_slug TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_roles ADD COLUMN bic_email TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

    // Per-agent extra fields: birthday, hire date, license renewal.
    // birthday and license_renewal are stored as MM-DD so they recur every year.
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_extra (
        email            TEXT PRIMARY KEY,
        birthday         TEXT NOT NULL DEFAULT '',   -- MM-DD (e.g. 06-15)
        hire_date        TEXT NOT NULL DEFAULT '',   -- YYYY-MM-DD (start / work anniversary)
        license_renewal  TEXT NOT NULL DEFAULT '',   -- MM-DD (annual renewal reminder)
        updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    // Migrations for existing installs
    try { $pdo->exec("ALTER TABLE agent_extra ADD COLUMN personal_cal_url TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_extra ADD COLUMN cal_token        TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ae_cal_token ON agent_extra(cal_token)");

    // HUD & Check document submissions
    $pdo->exec("CREATE TABLE IF NOT EXISTS hud_submissions (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email      TEXT    NOT NULL,
        agent_name       TEXT    NOT NULL,
        loop_id          TEXT    NOT NULL,
        loop_name        TEXT    NOT NULL,
        hud_original     TEXT,
        check_original   TEXT,
        hud_stored       TEXT,
        check_stored     TEXT,
        dl_hud_doc_id    TEXT,
        dl_check_doc_id  TEXT,
        dl_folder_id     TEXT,
        dotloop_ok       INTEGER NOT NULL DEFAULT 0,
        email_sent       INTEGER NOT NULL DEFAULT 0,
        notes            TEXT,
        submitted_at     TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Ensure upload directory exists and is protected
    $uploadDir = $dir . '/hud_uploads';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0750, true);
    $htaccess = $uploadDir . '/.htaccess';
    if (!file_exists($htaccess)) @file_put_contents($htaccess, "Deny from all\n");

    // Agent intake form — native replacement for the Google Form onboarding sheet.
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_intake (
        email               TEXT PRIMARY KEY,
        full_name           TEXT NOT NULL DEFAULT '',
        phone               TEXT NOT NULL DEFAULT '',
        license_number      TEXT NOT NULL DEFAULT '',
        license_state       TEXT NOT NULL DEFAULT '',
        license_exp         TEXT NOT NULL DEFAULT '',
        nar_number          TEXT NOT NULL DEFAULT '',
        mls_board           TEXT NOT NULL DEFAULT '',
        mls_id              TEXT NOT NULL DEFAULT '',
        office_location     TEXT NOT NULL DEFAULT '',
        birthday            TEXT NOT NULL DEFAULT '',
        mailing_address     TEXT NOT NULL DEFAULT '',
        spouse_name         TEXT NOT NULL DEFAULT '',
        emergency_name      TEXT NOT NULL DEFAULT '',
        emergency_phone     TEXT NOT NULL DEFAULT '',
        bio                 TEXT NOT NULL DEFAULT '',
        tshirt_size         TEXT NOT NULL DEFAULT '',
        is_military         TEXT NOT NULL DEFAULT '',
        first_responder     TEXT NOT NULL DEFAULT '',
        is_teacher          TEXT NOT NULL DEFAULT '',
        phone_last4         TEXT NOT NULL DEFAULT '',
        referring_agent     TEXT NOT NULL DEFAULT '',
        languages           TEXT NOT NULL DEFAULT '',
        submitted           INTEGER NOT NULL DEFAULT 0,
        submitted_at        TEXT,
        updated_at          TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // Headshot photos uploaded with the intake form (up to 5 per agent)
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_intake_files (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email TEXT NOT NULL,
        file_key    TEXT NOT NULL UNIQUE,
        orig_name   TEXT NOT NULL DEFAULT '',
        mime_type   TEXT NOT NULL DEFAULT '',
        size_bytes  INTEGER NOT NULL DEFAULT 0,
        uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_files_email ON agent_intake_files(agent_email)");

    // Ensure headshots directory exists and is web-protected
    $hsDir = $dir . '/headshots';
    if (!is_dir($hsDir)) @mkdir($hsDir, 0750, true);
    $hsHt  = $hsDir . '/.htaccess';
    if (!file_exists($hsHt)) @file_put_contents($hsHt, "Deny from all\n");

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

    // Custom industry events — admin-added events merged into the Industry Events page.
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_events (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        organizer   TEXT    NOT NULL DEFAULT '',
        category    TEXT    NOT NULL DEFAULT 'industry',
        start_date  TEXT    NOT NULL,
        end_date    TEXT    NOT NULL DEFAULT '',
        location    TEXT    NOT NULL DEFAULT '',
        url         TEXT    NOT NULL DEFAULT '',
        description TEXT    NOT NULL DEFAULT '',
        featured    INTEGER NOT NULL DEFAULT 0,
        created_by  TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Back Office menu builder — admin-defined items that appear in the Back Office sidebar section.
    $pdo->exec("CREATE TABLE IF NOT EXISTS backoffice_items (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        label      TEXT    NOT NULL,
        url        TEXT    NOT NULL DEFAULT '#',
        is_ext     INTEGER NOT NULL DEFAULT 0,
        sort_ord   INTEGER NOT NULL DEFAULT 0,
        enabled    INTEGER NOT NULL DEFAULT 1,
        department TEXT    NOT NULL DEFAULT 'Operations'
    )");
    try { $pdo->exec("ALTER TABLE backoffice_items ADD COLUMN department TEXT NOT NULL DEFAULT 'Operations'"); } catch (\Exception $e) {}

    // ── Announcements ─────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        title            TEXT    NOT NULL,
        body             TEXT    NOT NULL,
        author           TEXT    NOT NULL,
        audience         TEXT    NOT NULL DEFAULT 'all',  -- all | admin | mc | bic
        target_mc_slug   TEXT    NOT NULL DEFAULT '',     -- set when audience='mc'
        target_bic_email TEXT    NOT NULL DEFAULT '',     -- set when audience='bic'
        pinned           INTEGER NOT NULL DEFAULT 0,
        created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
        expires_at       TEXT
    )");
    // Migrations for existing installs
    try { $pdo->exec("ALTER TABLE announcements ADD COLUMN target_mc_slug TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE announcements ADD COLUMN target_bic_email TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE announcements ADD COLUMN image_key TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE announcements ADD COLUMN image_position TEXT NOT NULL DEFAULT 'center'"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE announcements ADD COLUMN image_size TEXT NOT NULL DEFAULT 'standard'"); } catch (\Exception $e) {}

    // Ensure announcement image directory exists alongside the database
    $annImgDir = $dir . '/announcement_images';
    if (!is_dir($annImgDir)) @mkdir($annImgDir, 0755, true);

    // ── Notification Preferences ──────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_prefs (
        email        TEXT    PRIMARY KEY,
        notify_email INTEGER NOT NULL DEFAULT 1,
        notify_sms   INTEGER NOT NULL DEFAULT 0,
        sms_phone    TEXT    NOT NULL DEFAULT '',
        updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // ── Outbound Notification Queue ───────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient  TEXT    NOT NULL,
        channel    TEXT    NOT NULL,  -- email | sms
        subject    TEXT    NOT NULL DEFAULT '',
        body       TEXT    NOT NULL,
        phone      TEXT    NOT NULL DEFAULT '',
        status     TEXT    NOT NULL DEFAULT 'pending',  -- pending | sent | failed
        attempts   INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL DEFAULT (datetime('now')),
        sent_at    TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifq_status ON notification_queue(status)");

    // ── Document Library (legacy simple Resources page) ───────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS doc_folders (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id  INTEGER,
        name       TEXT    NOT NULL,
        visibility TEXT    NOT NULL DEFAULT 'all',  -- all | admin
        sort_ord   INTEGER NOT NULL DEFAULT 0,
        created_by TEXT,
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS doc_files (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_id   INTEGER,
        name        TEXT    NOT NULL,
        orig_name   TEXT    NOT NULL,
        mime_type   TEXT,
        size_bytes  INTEGER,
        storage_key TEXT    NOT NULL,
        uploaded_by TEXT,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $docsDir = $dir . '/docs';
    if (!is_dir($docsDir)) @mkdir($docsDir, 0750, true);
    $docsHt = $docsDir . '/.htaccess';
    if (!file_exists($docsHt)) @file_put_contents($docsHt, "Deny from all\n");

    // ── The Vault — department-aware document library (replaces intranet /docs) ─
    // TEXT primary keys so UUIDs from the intranet migration carry over exactly.
    $pdo->exec("CREATE TABLE IF NOT EXISTS vault_depts (
        slug     TEXT    PRIMARY KEY,
        name     TEXT    NOT NULL,
        sort_ord INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vault_user_depts (
        email     TEXT NOT NULL,
        dept_slug TEXT NOT NULL,
        PRIMARY KEY (email, dept_slug)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vud_email ON vault_user_depts(email)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vault_folders (
        id          TEXT    PRIMARY KEY,
        parent_id   TEXT,
        name        TEXT    NOT NULL,
        -- public = all logged-in agents; dept = only dept members + admin; admin = admin only
        visibility  TEXT    NOT NULL DEFAULT 'public',
        dept_slug   TEXT    NOT NULL DEFAULT '',
        sort_ord    INTEGER NOT NULL DEFAULT 0,
        created_by  TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vf_parent ON vault_folders(parent_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vf_dept   ON vault_folders(dept_slug)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vault_files (
        id          TEXT    PRIMARY KEY,
        folder_id   TEXT,
        name        TEXT    NOT NULL,
        mime_type   TEXT    NOT NULL DEFAULT '',
        size_bytes  INTEGER NOT NULL DEFAULT 0,
        storage_key TEXT    NOT NULL,
        uploaded_by TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vfi_folder ON vault_files(folder_id)");

    // ── Support Tickets ───────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_departments (
        slug TEXT PRIMARY KEY,
        name TEXT NOT NULL
    )");
    $seedDepts = [['tech','Tech Support'],['onboarding','Onboarding'],['finance','Finance'],['general','General']];
    $di = $pdo->prepare("INSERT OR IGNORE INTO support_departments (slug,name) VALUES (?,?)");
    foreach ($seedDepts as $d) $di->execute($d);

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        title       TEXT    NOT NULL,
        body        TEXT    NOT NULL,
        status      TEXT    NOT NULL DEFAULT 'open',  -- open | in_progress | closed
        dept_slug   TEXT,
        agent_email TEXT    NOT NULL,
        agent_name  TEXT    NOT NULL DEFAULT '',
        assigned_to TEXT,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tkt_agent ON support_tickets(agent_email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tkt_status ON support_tickets(status)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_ticket_messages (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        author    TEXT    NOT NULL,
        is_staff  INTEGER NOT NULL DEFAULT 0,
        body      TEXT    NOT NULL,
        created_at TEXT   NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tktmsg_tid ON support_ticket_messages(ticket_id)");

    // ── Workflow Boards (Kanban) ───────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS wf_boards (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        description TEXT,
        created_by  TEXT,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wf_stages (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        board_id INTEGER NOT NULL,
        name     TEXT    NOT NULL,
        sort_ord INTEGER NOT NULL DEFAULT 0,
        color    TEXT    NOT NULL DEFAULT '#e0e0e0'
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wfstage_bid ON wf_stages(board_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wf_items (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        stage_id    INTEGER NOT NULL,
        board_id    INTEGER NOT NULL,
        title       TEXT    NOT NULL,
        description TEXT,
        assigned_to TEXT,
        due_date    TEXT,
        sort_ord    INTEGER NOT NULL DEFAULT 0,
        created_by  TEXT,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wfitem_sid ON wf_items(stage_id)");

    // ── INNOVATE University (LMS) ─────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_categories (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL,
        icon       TEXT    NOT NULL DEFAULT '📚',
        sort_ord   INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_courses (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        title       TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        thumb_key   TEXT    NOT NULL DEFAULT '',
        is_required INTEGER NOT NULL DEFAULT 0,
        sort_ord    INTEGER NOT NULL DEFAULT 0,
        published   INTEGER NOT NULL DEFAULT 0,
        created_by  TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_courses_cat ON uni_courses(category_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_lessons (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id    INTEGER NOT NULL,
        title        TEXT    NOT NULL,
        sort_ord     INTEGER NOT NULL DEFAULT 0,
        type         TEXT    NOT NULL DEFAULT 'video',  -- video | doc | quiz
        file_key     TEXT    NOT NULL DEFAULT '',
        content_html TEXT    NOT NULL DEFAULT '',
        duration_sec INTEGER NOT NULL DEFAULT 0,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_lessons_crs ON uni_lessons(course_id)");
    try { $pdo->exec("ALTER TABLE uni_lessons ADD COLUMN embed_url TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_questions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id     INTEGER NOT NULL,
        question      TEXT    NOT NULL,
        options       TEXT    NOT NULL DEFAULT '[]',  -- JSON array of option strings
        correct_index INTEGER NOT NULL DEFAULT 0,
        sort_ord      INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_q_lesson ON uni_questions(lesson_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_progress (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email  TEXT    NOT NULL,
        lesson_id    INTEGER NOT NULL,
        completed_at TEXT    NOT NULL DEFAULT (datetime('now')),
        score        INTEGER,           -- quiz score 0-100; NULL for video/doc
        attempts     INTEGER NOT NULL DEFAULT 1,
        UNIQUE(agent_email, lesson_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_prog_email ON uni_progress(agent_email)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_certs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email TEXT    NOT NULL,
        course_id   INTEGER NOT NULL,
        issued_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        cert_code   TEXT    NOT NULL UNIQUE,
        UNIQUE(agent_email, course_id)
    )");

    $uniDir = $dir . '/uni';
    if (!is_dir($uniDir)) @mkdir($uniDir, 0750, true);
    $uniHt  = $uniDir . '/.htaccess';
    if (!file_exists($uniHt)) @file_put_contents($uniHt, "Deny from all\n");

    // ── Market Centers master list ────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS market_centers (
        slug            TEXT PRIMARY KEY,
        name            TEXT NOT NULL,
        state_code      TEXT NOT NULL DEFAULT '',
        sort_ord        INTEGER NOT NULL DEFAULT 0,
        enabled         INTEGER NOT NULL DEFAULT 1,
        bic_email       TEXT NOT NULL DEFAULT '',
        mc_leader_email TEXT NOT NULL DEFAULT '',
        created_at      TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mc_state ON market_centers(state_code)");
    try { $pdo->exec("ALTER TABLE market_centers ADD COLUMN bic_email TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE market_centers ADD COLUMN mc_leader_email TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

    // ── Per-state automation status for the State Rosters page.
    $pdo->exec("CREATE TABLE IF NOT EXISTS state_roster_status (
        state_code TEXT PRIMARY KEY,
        status     TEXT NOT NULL DEFAULT 'pending',
        notes      TEXT NOT NULL DEFAULT '',
        updated_by TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    // Seed one row per state so every state shows up even before anyone sets a status.
    $seedSt = $pdo->prepare("INSERT OR IGNORE INTO state_roster_status (state_code) VALUES (?)");
    foreach (['FL','VA','DE','RI','NH','OH','NC','GA','PA','SC','MD','TN','NJ','MA'] as $sc) {
        $seedSt->execute([$sc]);
    }

    // INNOVATE agent roster — organized by state + market center, with license expiry where known.
    // agent_name, state_code, market_center, license_exp (YYYY-MM-DD or empty)
    $pdo->exec("CREATE TABLE IF NOT EXISTS innovate_roster (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_name    TEXT    NOT NULL,
        state_code    TEXT    NOT NULL,
        market_center TEXT    NOT NULL DEFAULT '',
        license_exp   TEXT    NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_roster_state ON innovate_roster(state_code)");
    if ($pdo->query("SELECT COUNT(*) FROM innovate_roster")->fetchColumn() == 0) {
        $ri = $pdo->prepare("INSERT INTO innovate_roster (agent_name,state_code,market_center,license_exp) VALUES (?,?,?,?)");
        foreach (_innovate_roster_seed() as $r) $ri->execute($r);
    }
    // Migration: add tracking columns to existing installs (no-op if already present)
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN active     INTEGER NOT NULL DEFAULT 1"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN added_at   TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN added_by   TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN removed_at TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN removed_by TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    // Retention tracking: secure | watch | at_risk, plus free-text notes and last-contact date.
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN retention_status TEXT NOT NULL DEFAULT 'secure'"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN retention_notes  TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN last_contact_at  TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    // Links a roster row back to coastline's canonical_agents.id when the agent
    // came through the Add-to-Team → onboarding flow (exact-match key instead
    // of name matching). Null for legacy rows added manually.
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN canonical_agent_id TEXT"); } catch (\Exception $e) {}

    // Roster change log — every add/remove writes a row here for weekly reports.
    $pdo->exec("CREATE TABLE IF NOT EXISTS roster_changes (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_name    TEXT    NOT NULL,
        state_code    TEXT    NOT NULL,
        market_center TEXT    NOT NULL DEFAULT '',
        license_exp   TEXT    NOT NULL DEFAULT '',
        action        TEXT    NOT NULL,
        changed_by    TEXT    NOT NULL DEFAULT '',
        changed_at    TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_roster_chg_at ON roster_changes(changed_at)");

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

    // ── Finance: Department Budgets ───────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS budget_periods (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        period_type TEXT    NOT NULL DEFAULT 'annual',  -- annual | quarterly | monthly | custom
        start_date  TEXT    NOT NULL DEFAULT '',
        end_date    TEXT    NOT NULL DEFAULT '',
        created_by  TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS budget_lines (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        period_id     INTEGER NOT NULL,
        department    TEXT    NOT NULL DEFAULT 'Operations',
        category      TEXT    NOT NULL DEFAULT '',
        description   TEXT    NOT NULL DEFAULT '',
        budgeted_amt  REAL    NOT NULL DEFAULT 0,
        actual_amt    REAL    NOT NULL DEFAULT 0,
        notes         TEXT    NOT NULL DEFAULT '',
        created_by    TEXT    NOT NULL DEFAULT '',
        updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bl_period ON budget_lines(period_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bl_dept   ON budget_lines(department)");

    // ── MLS Integrations tracker ──────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS mls_integrations (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        mls_name         TEXT    NOT NULL,
        mls_code         TEXT    NOT NULL DEFAULT '',
        region           TEXT    NOT NULL DEFAULT '',
        feed_type        TEXT    NOT NULL DEFAULT 'RETS',
        status           TEXT    NOT NULL DEFAULT 'researching',
        monthly_fee      REAL    NOT NULL DEFAULT 0,
        products         TEXT    NOT NULL DEFAULT '',
        application_date TEXT,
        approval_date    TEXT,
        go_live_date     TEXT,
        agreement_url    TEXT,
        contact_name     TEXT    NOT NULL DEFAULT '',
        contact_org      TEXT    NOT NULL DEFAULT '',
        contact_email    TEXT    NOT NULL DEFAULT '',
        contact_phone    TEXT    NOT NULL DEFAULT '',
        api_base_url     TEXT,
        api_username     TEXT    NOT NULL DEFAULT '',
        api_secret       TEXT    NOT NULL DEFAULT '',
        api_key          TEXT    NOT NULL DEFAULT '',
        notes            TEXT    NOT NULL DEFAULT '',
        created_by       TEXT    NOT NULL DEFAULT '',
        created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    // Seed known MLS integrations on fresh installs
    if ($pdo->query("SELECT COUNT(*) FROM mls_integrations")->fetchColumn() == 0) {
        $mi = $pdo->prepare("INSERT INTO mls_integrations
            (mls_name,mls_code,region,feed_type,status,monthly_fee,products,
             go_live_date,contact_email,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $mi->execute(['Coastal Carolinas Association of REALTORS','CCAR','SC – Coastal Carolinas','Trestle','active',0,'idx,crm','2024-01-01','','Trestle feed via CoreLogic. OriginatingSystemName = CCAR.']);
        $mi->execute(['Consolidated MLS (Columbia SC)','CMLS','SC – Columbia','RETS','active',0,'crm','2026-05-01','','Paragon RETS feed. 5298 active agents + 1958 offices. Nightly cron 4:30am ET via ~/coastline-server/columbia.sh.']);
        $mi->execute(['PrimeMLS','PRIME','NH, VT, ME, MA, CT, RI','RETS','applied',750,'idx,crm','','data@primemls.com','Specialty Data Feed Agreement signed 2026-06-23. Contact: Chad Jacobson, CEO. Phone: (603) 228-9733. Agreement effective date 6/23/26.']);
        $mi->execute(['East Tennessee Association of REALTORS (ETAR)','ETAR','TN – Knoxville area','Spark','researching',0,'idx,crm','','','Spark Platform integration in progress. Demo token issue pending resolution.']);
    }

    // ── Listing Intelligence ──────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_farms (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email  TEXT    NOT NULL,
        name         TEXT    NOT NULL,
        zip_codes    TEXT    NOT NULL DEFAULT '[]',
        neighborhoods TEXT   NOT NULL DEFAULT '[]',
        notes        TEXT    NOT NULL DEFAULT '',
        created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lf_email ON listing_farms(agent_email)");
    try { $pdo->exec("ALTER TABLE listing_farms ADD COLUMN state TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_farms ADD COLUMN is_demo INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_prospects (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email    TEXT    NOT NULL,
        farm_id        INTEGER,
        owner_name     TEXT    NOT NULL DEFAULT '',
        address        TEXT    NOT NULL,
        city           TEXT    NOT NULL DEFAULT '',
        zip            TEXT    NOT NULL DEFAULT '',
        phone          TEXT    NOT NULL DEFAULT '',
        email          TEXT    NOT NULL DEFAULT '',
        mls_number     TEXT    NOT NULL DEFAULT '',
        source         TEXT    NOT NULL DEFAULT 'auto',    -- auto | expired | manual
        status         TEXT    NOT NULL DEFAULT 'new',     -- new | contacted | active | dead
        seller_score   INTEGER NOT NULL DEFAULT 0,
        est_value      INTEGER NOT NULL DEFAULT 0,
        purchase_price INTEGER NOT NULL DEFAULT 0,
        purchase_date  TEXT    NOT NULL DEFAULT '',
        years_owned    INTEGER NOT NULL DEFAULT 0,
        velocity       INTEGER NOT NULL DEFAULT 0,
        skip_traced    INTEGER NOT NULL DEFAULT 0,
        skip_traced_at TEXT    NOT NULL DEFAULT '',
        last_contact   TEXT    NOT NULL DEFAULT '',
        notes          TEXT    NOT NULL DEFAULT '',
        created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at     TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_email  ON listing_prospects(agent_email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_status ON listing_prospects(status)");
    // Migrations for existing installs
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN purchase_price INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN purchase_date  TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN years_owned    INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN velocity       INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN skip_traced    INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN skip_traced_at TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("UPDATE listing_prospects SET owner_name='' WHERE owner_name IS NULL"); } catch (\Exception $e) {}
    // Regrid parcel/tax ingestion fields
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN mailing_address     TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN absentee_owner      INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN homestead_exemption INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN regrid_ll_uuid      TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_regrid_uuid ON listing_prospects(regrid_ll_uuid)");
    // PropertyRadar ingestion fields — real equity/distress data Regrid didn't have
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN property_type      TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN state              TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN equity_pct         REAL    NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN equity_amt         INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN avm_value          INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN tax_delinquent     INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN tax_delinquent_yrs INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN in_foreclosure     INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN foreclosure_stage  TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN is_vacant          INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN pr_radar_id        TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN pr_monitored       INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN ingest_batch       TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN lat                REAL    NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE listing_prospects ADD COLUMN lon                REAL    NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_pr_radar_id   ON listing_prospects(pr_radar_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_ingest_batch  ON listing_prospects(ingest_batch)");

    // Monitoring/webhook event trail — audit log of real-world change events
    // PropertyRadar pushes for monitored properties (tax delinquent, foreclosure, sold, listed).
    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_monitor_events (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        prospect_id  INTEGER NOT NULL,
        event_type   TEXT    NOT NULL,
        payload      TEXT    NOT NULL DEFAULT '',
        received_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lme_prospect ON listing_monitor_events(prospect_id)");

    // Per-property scoring signals — one row per signal per prospect, so the
    // seller_score is a transparent, retunable sum instead of a black-box formula.
    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_prospect_signals (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        prospect_id  INTEGER NOT NULL,
        signal_key   TEXT    NOT NULL,
        signal_value TEXT    NOT NULL DEFAULT '',
        points       INTEGER NOT NULL DEFAULT 0,
        computed_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lps_prospect ON listing_prospect_signals(prospect_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_outreach (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        prospect_id  INTEGER NOT NULL,
        agent_email  TEXT    NOT NULL,
        method       TEXT    NOT NULL DEFAULT 'call',   -- call | email | mail | text | door
        outcome      TEXT    NOT NULL DEFAULT '',       -- no_answer | left_vm | spoke | interested | not_interested
        notes        TEXT    NOT NULL DEFAULT '',
        logged_at    TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lo_prospect ON listing_outreach(prospect_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lo_agent    ON listing_outreach(agent_email)");

    // ── Training RSVPs ────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS training_rsvps (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id    TEXT    NOT NULL,
        event_title TEXT    NOT NULL DEFAULT '',
        event_date  TEXT    NOT NULL DEFAULT '',
        agent_email TEXT    NOT NULL,
        agent_name  TEXT    NOT NULL DEFAULT '',
        rsvped_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(event_id, agent_email)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_trsvp_event ON training_rsvps(event_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_trsvp_agent ON training_rsvps(agent_email)");

    // ── Finance: Statement Scans ──────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS statement_scans (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        account_label   TEXT    NOT NULL DEFAULT '',
        scan_type       TEXT    NOT NULL DEFAULT 'bank',  -- bank | credit_card
        uploaded_by     TEXT    NOT NULL DEFAULT '',
        uploaded_at     TEXT    NOT NULL DEFAULT (datetime('now')),
        raw_text        TEXT    NOT NULL DEFAULT '',
        analysis_json   TEXT    NOT NULL DEFAULT '',
        status          TEXT    NOT NULL DEFAULT 'pending'  -- pending | complete | error
    )");

    // ── Press Release: media contacts ("Who to Pitch") ───────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS press_contacts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        category   TEXT    NOT NULL,
        outlet     TEXT    NOT NULL,
        beat       TEXT    NOT NULL DEFAULT '',
        how        TEXT    NOT NULL DEFAULT '',
        note       TEXT    NOT NULL DEFAULT '',
        sort_ord   INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_press_contacts_cat ON press_contacts(category)");
    try { $pdo->exec("ALTER TABLE press_contacts ADD COLUMN state TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    if ($pdo->query("SELECT COUNT(*) FROM press_contacts")->fetchColumn() == 0) {
        $ins = $pdo->prepare("INSERT INTO press_contacts (category,outlet,beat,how,note,sort_ord,state) VALUES (?,?,?,?,?,?,?)");
        foreach ([
            ['National Real Estate Trade','Inman News','Brokerage growth, agent recruitment, industry trends','tips@inman.com','Highest reach for brokerage expansion. Lead with agent count + growth data.',10,''],
            ['National Real Estate Trade','RISMedia','Real estate leadership, independent brokerages','press@rismedia.com','Receptive to regional brokerages with compelling agent value props.',20,''],
            ['National Real Estate Trade','HousingWire','Brokerage tech, market data','editorial@housingwire.com','Best for tech announcements and market data stories.',30,''],
            ['National Real Estate Trade','RealTrends','Brokerage rankings, agent productivity','info@realtrends.com','Submit when crossing volume milestones or for rankings consideration.',40,''],
            ['National Real Estate Trade','The Real Deal','Market moves, notable hires, brokerage competition','tips@therealdeal.com','Skews large markets — use for major expansions or exec hires.',50,''],
            ['Regional Business Press','Myrtle Beach Sun News','Local business, real estate, coastal SC economy','business@myrtlebeachsun.com','Home market — pitch everything. Quote local agent counts and SC data.',110,''],
            ['Regional Business Press','The State (Columbia, SC)','SC business, economy, commercial real estate','newsdesk@thestate.com','Strong for statewide growth stories and Columbia/Upstate expansion.',120,''],
            ['Regional Business Press','Post and Courier (Charleston)','Charleston real estate, SC business','business@postandcourier.com',"One of SC's most credible papers — any SC-wide news belongs here.",130,''],
            ['Regional Business Press','Charlotte Observer','Carolinas business, real estate, expansion','business@charlotteobserver.com','Covers NC + greater Carolinas. Best for NC market expansion stories.',140,''],
            ['Regional Business Press','Business North Carolina','NC business, growth companies','editor@businessnc.com','Monthly magazine — submit 6–8 weeks ahead for print consideration.',150,''],
            ['Wire Services & Industry','PR Newswire','Broad distribution to hundreds of outlets','prnewswire.com (submit online)','~$350–700/release. Best ROI for milestones. Ask for SE regional circuit.',210,''],
            ['Wire Services & Industry','Business Wire','Financial and trade press distribution','businesswire.com (submit online)','Preferred by financial journalists; use for investor-audience stories.',220,''],
            ['Wire Services & Industry','EIN Presswire','Broad online distribution, affordable','einpresswire.com (submit online)','Free tier available. Good for SEO pickup on routine announcements.',230,''],
            ['Wire Services & Industry','Broker Agent Magazine','Independent brokerage, agent recruiting','editorial@brokeragentmagazine.com','Directly reaches agents evaluating brokerage moves — prime for recruiting news.',240,''],
            ['Wire Services & Industry','NAR Newsroom / Realtor Magazine','NAR member news, market data','newsroom@nar.realtor','Use for MLS data, compliance, and agent advocacy stories.',250,''],
            ['Regional Business Press','LehighValleyNews.com','Local/regional news, nonprofit newsroom — Allentown, Bethlehem, Easton','news@lehighvalleynews.com','New market opening — Bethlehem, PA. Best first pitch for a new-office story.',160,'PA'],
            ['Regional Business Press','Providence Business News','Rhode Island business journal','Advertising@PBN.com (paid release/People-on-the-Move submission at pbn.com)','New market opening — Cranston, RI. Consider a paid release or People on the Move for the branch leader.',170,'RI'],
        ] as $r) $ins->execute($r);
    } else {
        // Backfill new contacts for installs that already seeded before these existed.
        $backfill = $pdo->prepare("INSERT INTO press_contacts (category,outlet,beat,how,note,sort_ord,state)
            SELECT ?,?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM press_contacts WHERE outlet=?)");
        $backfill->execute(['Regional Business Press','LehighValleyNews.com','Local/regional news, nonprofit newsroom — Allentown, Bethlehem, Easton','news@lehighvalleynews.com','New market opening — Bethlehem, PA. Best first pitch for a new-office story.',160,'PA','LehighValleyNews.com']);
        $backfill->execute(['Regional Business Press','Providence Business News','Rhode Island business journal','Advertising@PBN.com (paid release/People-on-the-Move submission at pbn.com)','New market opening — Cranston, RI. Consider a paid release or People on the Move for the branch leader.',170,'RI','Providence Business News']);
    }

    return $pdo;
}

// All enabled external nav links, ordered for sidebar display.
function nav_ext_links_all(): array {
    return local_db()
        ->query("SELECT * FROM nav_ext_links WHERE enabled=1 ORDER BY CASE WHEN group_label='' THEN sort_ord ELSE (SELECT MIN(sort_ord) FROM nav_ext_links n2 WHERE n2.group_label=nav_ext_links.group_label AND n2.enabled=1) END, group_label, sort_ord, id")
        ->fetchAll(PDO::FETCH_ASSOC);
}

// All enabled back office menu items, ordered for sidebar display.
function backoffice_items_all(): array {
    try {
        return local_db()
            ->query("SELECT * FROM backoffice_items WHERE enabled=1 ORDER BY sort_ord,id")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) { return []; }
}

// Current status row for a state, or defaults if not yet set.
function state_roster_status(string $code): array {
    $s = local_db()->prepare("SELECT * FROM state_roster_status WHERE state_code=?");
    $s->execute([$code]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: ['state_code' => $code, 'status' => 'pending', 'notes' => ''];
}

// All 14 state roster statuses keyed by state code.
function state_roster_statuses_all(): array {
    $rows = local_db()->query("SELECT * FROM state_roster_status")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['state_code']] = $r;
    return $map;
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

// Static seed data for the innovate_roster table.
// Returns array of [agent_name, state_code, market_center, license_exp].
// license_exp is YYYY-MM-DD for SC agents where known, '' otherwise.
function _innovate_roster_seed(): array {
    return [
        // TN — Knoxville
        ['Josh Anderson',        'TN','Knoxville',''],
        ['Paulinus Onwo',        'TN','Knoxville',''],
        ['Faleria De Robertis',  'TN','Knoxville',''],
        ['Crystal Griffith',     'TN','Knoxville',''],
        ['Andrew Heath',         'TN','Knoxville',''],
        ['Robert McLeay',        'TN','Knoxville',''],
        ['Duyet Bui',            'TN','Knoxville',''],
        ['Maggie Scott',         'TN','Knoxville',''],
        ['Will Tomb',            'TN','Knoxville',''],
        ['Brenda Brewster',      'TN','Knoxville',''],
        // TN — Nashville
        ['Monique Westbrooks',   'TN','Nashville',''],
        ['Janis Rittersbeek',    'TN','Nashville',''],
        // OH
        ['Joe Tupta',            'OH','',''],
        // VA
        ['Amy Adams',            'VA','',''],
        ['Jason Adams',          'VA','',''],
        ['Kelly Blumenthal',     'VA','',''],
        ['Kenneth Hamblin',      'VA','',''],
        ['Jessica Urdiano',      'VA','',''],
        ['Tabatha Urf',          'VA','',''],
        ['Greg Williams',        'VA','',''],
        ['Kathleen Wood',        'VA','',''],
        // RI
        ['Manny Menezes',        'RI','',''],
        ['Scott Egersheim',      'RI','',''],
        ['John Pachecho',        'RI','',''],
        ['Filomena Silva',       'RI','',''],
        // FL
        ['Brie Bender',          'FL','',''],
        ['Joan Covell-Tocci',    'FL','',''],
        ['Danielle Dustman',     'FL','',''],
        ['George Dustman',       'FL','',''],
        ['Gina Ewan',            'FL','',''],
        ['Nick Huffman',         'FL','',''],
        ['Gina Lollo',           'FL','',''],
        ['Joanna Lupa',          'FL','',''],
        ['Trina Maguire',        'FL','',''],
        ['Heather Panek',        'FL','',''],
        ['Dana Pangelinan',      'FL','',''],
        ['Lynne-Ann Rose',       'FL','',''],
        ['Susan Schleicher',     'FL','',''],
        ['Heidi Smithers',       'FL','',''],
        ['Alysia Stern',         'FL','',''],
        ['Lindsey Van Deilen',   'FL','',''],
        ['Jennifer Rose Young',  'FL','',''],
        // NC
        ['Carrie Nicholson Kinney', 'NC','NC',''],
        ['Antonia Alini',           'NC','NC',''],
        ['Tonya Mills Allen',       'NC','NC',''],
        ['Caitlyn Seastrunk Bair',  'NC','NC',''],
        ['James Dustin Batten',     'NC','NC',''],
        ['Brittany Amille Booker',  'NC','NC',''],
        ['Daniel Hawkins Brown',    'NC','NC',''],
        ['Jonathan Blake Butler',   'NC','NC',''],
        ['Shawn Paul Carter',       'NC','NC',''],
        ['Robin Haddock Chestnutt', 'NC','NC',''],
        ['Diane Cleland',           'NC','NC',''],
        ['Sheri Anita Cole Smith',  'NC','NC',''],
        ['April Campbell Coutsos',  'NC','NC',''],
        ['Christine Cox',           'NC','NC',''],
        ['Nicole Marie Cummings',   'NC','NC',''],
        ['Juliann Beth Deforrest',  'NC','NC',''],
        ['Jaclyn Suzanne Edwards',  'NC','NC',''],
        ['Gina Marie Ewan',         'NC','NC',''],
        ['Kristie Sue Gaither',     'NC','NC',''],
        ['Gerard Raymond Gilbert',  'NC','NC',''],
        ['Rosemarie Lisa Heldmann', 'NC','NC',''],
        ['Ronald Mark Hyman',       'NC','NC',''],
        ["D'Ambrah Patricia Rose King",'NC','NC',''],
        ['Elizabeth Lewis Kozar',   'NC','NC',''],
        ['Stephanie Graham Lilly',  'NC','NC',''],
        ['Patricia Nicole Manning', 'NC','NC',''],
        ['Kathleen Ann Margeson',   'NC','NC',''],
        ['Kristina McGeathy',       'NC','NC',''],
        ['John Mills',              'NC','NC',''],
        ['Cathy Nourse',            'NC','NC',''],
        ["Richard Thomas O'Donnell Jr.", 'NC','NC',''],
        ['Kayla Beth Parrinello',   'NC','NC',''],
        ['Nick Ruppe',              'NC','NC',''],
        ['Sarah Seastrunk',         'NC','NC',''],
        ['Terry Sechrist',          'NC','NC',''],
        ['Inna Semenova Arbolino',  'NC','NC',''],
        ['Eric Allen Shenberger',   'NC','NC',''],
        ['Lisa Felica Smith',       'NC','NC',''],
        ['Justin Tomas Thompson',   'NC','NC',''],
        ['Jason James Whedon',      'NC','NC',''],
        // DE
        ['Monica Peterson',      'DE','',''],
        ['Quinton Gaines',       'DE','',''],
        ['Vernell Wynn',         'DE','',''],
        ['Carolyn Parrish',      'DE','',''],
        ['Sean Brooks',          'DE','',''],
        ['Evie Ross',            'DE','',''],
        ['Tiffany Fuller',       'DE','',''],
        ['Evette Cabreja',       'DE','',''],
        // MD
        ['James Davis',          'MD','',''],
        // PA — Bucks County
        ['Melanie Henderson',    'PA','Bucks County',''],
        ['Lauren Adam',          'PA','Bucks County',''],
        ['Diane Cleland',        'PA','Bucks County',''],
        ['Ed Valentine',         'PA','Bucks County',''],
        ['Donna Frei',           'PA','Bucks County',''],
        ['Kevin Daly',           'PA','Bucks County',''],
        ['Dan Smith',            'PA','Bucks County',''],
        ['Sandy Horan',          'PA','Bucks County',''],
        ['Chris Hennessy',       'PA','Bucks County',''],
        ['Kimbery Picciotti',    'PA','Bucks County',''],
        ['Chris Cleland',        'PA','Bucks County',''],
        ['Tracey Hill',          'PA','Bucks County',''],
        ['William Kelly',        'PA','Bucks County',''],
        ['Edward Brun',          'PA','Bucks County',''],
        ['Lindsay Martin',       'PA','Bucks County',''],
        ['Amy Eves Walder',      'PA','Bucks County',''],
        ['Cheryl Smith',         'PA','Bucks County',''],
        ['Kandece Henning',      'PA','Bucks County',''],
        ['William Lowe',         'PA','Bucks County',''],
        ['Jared Moyer',          'PA','Bucks County',''],
        ['Heather Buckley',      'PA','Bucks County',''],
        ['Jessica Kooker',       'PA','Bucks County',''],
        ['Jason Musselman',      'PA','Bucks County',''],
        ['Amanda Moeser',        'PA','Bucks County',''],
        ['Earl Caffrey',         'PA','Bucks County',''],
        ['Renee Rhine',          'PA','Bucks County',''],
        ['Theres Wydan',         'PA','Bucks County',''],
        ['Darren Taylor',        'PA','Bucks County',''],
        ['Kevin Illg',           'PA','Bucks County',''],
        ['John Walder',          'PA','Bucks County',''],
        ['Carolyn Powers',       'PA','Bucks County',''],
        ['Christi Myers',        'PA','Bucks County',''],
        ['Alicia Rodgers',       'PA','Bucks County',''],
        ['Brandon Hill',         'PA','Bucks County',''],
        ['Jeff Powers',          'PA','Bucks County',''],
        ['Elizabeth Wydan Capece','PA','Bucks County',''],
        ['Jaselyn Ramos',        'PA','Bucks County',''],
        ['Nicole Mikula',        'PA','Bucks County',''],
        ['Jordan Wehr Faikish',  'PA','Bucks County',''],
        ['Linda McGlinn',        'PA','Bucks County',''],
        ['Patricia Murphy',      'PA','Bucks County',''],
        ['Joshua Forker',        'PA','Bucks County',''],
        ['Robert Moesser',       'PA','Bucks County',''],
        ['Randall Cuthbert',     'PA','Bucks County',''],
        ['Donald Hunter',        'PA','Bucks County',''],
        ['Ronald Monroe',        'PA','Bucks County',''],
        ['Susan Testani',        'PA','Bucks County',''],
        ['Mandee Hammerstein',   'PA','Bucks County',''],
        ['Shawn Gentile',        'PA','Bucks County',''],
        ['Elisha Cook',          'PA','Bucks County',''],
        ['Malisha Leach',        'PA','Bucks County',''],
        ['Brent Moser',          'PA','Bucks County',''],
        ['Reece Souder',         'PA','Bucks County',''],
        // PA — Multi-State (also licensed in DE)
        ['Monica Peterson',      'PA','Multi-State',''],
        ['Tiffany Fuller',       'PA','Multi-State',''],
        ['Vernell Wynn',         'PA','Multi-State',''],
        // NJ
        ['Amy Eves Walder',      'NJ','',''],
        ['Rosemarie Heldmann',   'NJ','',''],
        ['Brandon Hill',         'NJ','',''],
        ['Nicole Larue',         'NJ','',''],
        ['Kim Loizzi',           'NJ','',''],
        ['Nick Loizzi',          'NJ','',''],
        ['Monica Peterson',      'NJ','',''],
        ['Susan Testani',        'NJ','',''],
        // SC — Myrtle Beach (Professional Drive)
        ['Sean Matthew Brooks',          'SC','Myrtle Beach','2025-06-30'],
        ['Gracelyn Fay Alexander',       'SC','Myrtle Beach','2027-06-30'],
        ['Katherine Staples Arrigo',     'SC','Myrtle Beach','2027-06-30'],
        ['Fernanda Silva Azevedo',       'SC','Myrtle Beach','2028-06-30'],
        ['Carolyn Jean Barnes',          'SC','Myrtle Beach','2026-06-30'],
        ['David I Barr',                 'SC','Myrtle Beach','2026-06-30'],
        ['Daniel C Baxley',              'SC','Myrtle Beach','2025-06-30'],
        ['Stacy Lynn Belue',             'SC','Myrtle Beach','2026-06-30'],
        ['Melissa A Bills',              'SC','Myrtle Beach','2026-06-30'],
        ['Sandra K Bishop',              'SC','Myrtle Beach','2027-06-30'],
        ['James Bodner',                 'SC','Myrtle Beach','2026-06-30'],
        ['Chasitie Lynne Borders',       'SC','Myrtle Beach','2026-06-30'],
        ['Edward P Boyd',                'SC','Myrtle Beach','2025-06-30'],
        ['Gregory Ryan Brastow',         'SC','Myrtle Beach','2026-06-30'],
        ['Lisa Marie Brinkley',          'SC','Myrtle Beach','2025-06-30'],
        ['Andrew Jacob Brock',           'SC','Myrtle Beach','2026-06-30'],
        ['Kate Brooks',                  'SC','Myrtle Beach',''],
        ['Ethan Rasheem Brown',          'SC','Myrtle Beach','2026-06-30'],
        ['Dorri C Brown',                'SC','Myrtle Beach','2027-06-30'],
        ['Margaret J Burris',            'SC','Myrtle Beach','2025-06-30'],
        ['Brandon M Bushaw',             'SC','Myrtle Beach','2025-06-30'],
        ['Peter Justin Candela',         'SC','Myrtle Beach','2026-06-30'],
        ['Michael Carpenter',            'SC','Myrtle Beach','2027-06-30'],
        ['Deanna Marie Casanova',        'SC','Myrtle Beach','2026-06-30'],
        ['Maria L Caspento',             'SC','Myrtle Beach','2025-06-30'],
        ['Jeffrey J Casterline',         'SC','Myrtle Beach','2027-06-30'],
        ['Caryn Marie Casterline',       'SC','Myrtle Beach','2026-06-30'],
        ['Sean M Collins',               'SC','Myrtle Beach','2025-06-30'],
        ['Cannon Leigh Collins',         'SC','Myrtle Beach','2026-06-30'],
        ['Haley Morris Corbett',         'SC','Myrtle Beach','2026-06-30'],
        ['Serita Patricia Cotton',       'SC','Myrtle Beach','2026-06-30'],
        ['Jordan Lee Cummins',           'SC','Myrtle Beach','2026-06-30'],
        ['Marshall Barton Deforrest',    'SC','Myrtle Beach','2026-06-30'],
        ['Juliann B Deforrest',          'SC','Myrtle Beach','2027-06-30'],
        ['Larisa Esmat',                 'SC','Myrtle Beach','2026-06-30'],
        ['Kevin Michael Field',          'SC','Myrtle Beach','2028-06-30'],
        ['Bret A French',                'SC','Myrtle Beach','2025-06-30'],
        ['James Scott Furr',             'SC','Myrtle Beach','2026-06-30'],
        ['Courtney Graham',              'SC','Myrtle Beach','2027-06-30'],
        ['Eric S Graham',                'SC','Myrtle Beach','2027-06-30'],
        ['Joseph Michael Guerriero',     'SC','Myrtle Beach','2028-06-30'],
        ['Yvonne Lynn Guthrie',          'SC','Myrtle Beach','2025-06-30'],
        ['Laura A Harrison',             'SC','Myrtle Beach','2026-06-30'],
        ['Kenneth E Haselden',           'SC','Myrtle Beach','2026-06-30'],
        ['Jordyn Delaney Heche',         'SC','Myrtle Beach','2025-06-30'],
        ['Shawn L Hixenbaugh',           'SC','Myrtle Beach','2025-06-30'],
        ['Tammy Hoey',                   'SC','Myrtle Beach','2027-06-30'],
        ['Nicole Michelle Hughes',       'SC','Myrtle Beach','2025-06-30'],
        ['Ronald M Hyman',               'SC','Myrtle Beach','2026-06-30'],
        ['Jade Iglesias',                'SC','Myrtle Beach','2025-06-30'],
        ['Virginia Mackenzie Jesselson', 'SC','Myrtle Beach','2025-06-30'],
        ['Anita Jo Jones',               'SC','Myrtle Beach','2027-06-30'],
        ['Colleen L Kane',               'SC','Myrtle Beach','2026-06-30'],
        ['Jessica R Keefer',             'SC','Myrtle Beach','2026-06-30'],
        ["D'Ambrah Patricia Rose King",  'SC','Myrtle Beach','2025-06-30'],
        ['Krista Naomi Knight',          'SC','Myrtle Beach','2026-06-30'],
        ['Derek A Kouche',               'SC','Myrtle Beach','2025-06-30'],
        ['Brenda Lynn Kozak',            'SC','Myrtle Beach','2026-06-30'],
        ['Kristin La Bar',               'SC','Myrtle Beach','2026-06-30'],
        ['Rebecca A Lewis',              'SC','Myrtle Beach','2026-06-30'],
        ['Mark J Loomis',                'SC','Myrtle Beach','2026-06-30'],
        ['Jeffrey T Love',               'SC','Myrtle Beach','2025-06-30'],
        ['Sabrina Susanne Lynch',        'SC','Myrtle Beach','2026-06-30'],
        ['Lisa Maureen Mantone',         'SC','Myrtle Beach','2027-06-30'],
        ['Tammy Ann Marasia',            'SC','Myrtle Beach','2026-06-30'],
        ['Tyler Marchese',               'SC','Myrtle Beach','2027-06-30'],
        ['Noelle Mason',                 'SC','Myrtle Beach',''],
        ['Faith Mattei',                 'SC','Myrtle Beach','2025-06-30'],
        ['Kevin Matthews',               'SC','Myrtle Beach','2027-06-30'],
        ['Christopher W McZeke',         'SC','Myrtle Beach','2026-06-30'],
        ['Sarah Brittany Miller',        'SC','Myrtle Beach','2026-06-30'],
        ['Chris Robert Nadelman',        'SC','Myrtle Beach','2025-06-30'],
        ['Teresa Marie Nealey',          'SC','Myrtle Beach','2027-06-30'],
        ['Thubelihle Nkomazana',         'SC','Myrtle Beach','2026-06-30'],
        ['April M Oakley',               'SC','Myrtle Beach','2026-06-30'],
        ['Bruna Simas Pabon',            'SC','Myrtle Beach','2026-06-30'],
        ['Ricky Francis Pabon',          'SC','Myrtle Beach','2026-06-30'],
        ['Venice Samantha Parker',       'SC','Myrtle Beach','2026-06-30'],
        ['Jamie Edwin Pate',             'SC','Myrtle Beach','2027-06-30'],
        ['Komal R Patel',                'SC','Myrtle Beach','2028-06-30'],
        ['Renee Popovic',                'SC','Myrtle Beach','2027-06-30'],
        ['Rene Rhine',                   'SC','Myrtle Beach','2027-06-30'],
        ['Jennifer Michelle Rossini',    'SC','Myrtle Beach','2026-06-30'],
        ['Amber Rae Roy',                'SC','Myrtle Beach','2025-06-30'],
        ['Karla Jean Rupp',              'SC','Myrtle Beach','2025-06-30'],
        ['Inna Semenova Arbolino',       'SC','Myrtle Beach','2026-06-30'],
        ['Eric Allen Shenberger',        'SC','Myrtle Beach','2027-06-30'],
        ['Christian Todd Sichitano',     'SC','Myrtle Beach','2026-06-30'],
        ['Payton Anna Smith',            'SC','Myrtle Beach','2026-06-30'],
        ['Diana Joy Smith',              'SC','Myrtle Beach','2028-06-30'],
        ['Lee Anthony Spryzenski',       'SC','Myrtle Beach','2028-06-30'],
        ['Joshua Stevens',               'SC','Myrtle Beach','2027-06-30'],
        ['Christopher Michael Stoll',    'SC','Myrtle Beach','2026-06-30'],
        ['Jennifer Jeanne Swisher',      'SC','Myrtle Beach','2027-06-30'],
        ['Kimberly Sue Tatro',           'SC','Myrtle Beach','2026-06-30'],
        ['Robert Paul Tichy',            'SC','Myrtle Beach','2026-06-30'],
        ['Cliff Scarborough Todd',       'SC','Myrtle Beach','2027-06-30'],
        ['Jillian A Tomkovich',          'SC','Myrtle Beach','2025-06-30'],
        ['Gavin Chaplinski Vallonga',    'SC','Myrtle Beach','2025-06-30'],
        ['Adriana Mendonca Vidal',       'SC','Myrtle Beach','2026-06-30'],
        ['Carol M Watters',              'SC','Myrtle Beach','2026-06-30'],
        ['Kathryn Werner',               'SC','Myrtle Beach',''],
        ['Jason James Whedon',           'SC','Myrtle Beach','2026-06-30'],
        ['Selena Antoinette Witherspoon','SC','Myrtle Beach','2026-06-30'],
        ['Barry Alan Woessner',          'SC','Myrtle Beach','2025-06-30'],
        ['Jimmy Sennon Wojtko',          'SC','Myrtle Beach','2027-06-30'],
        ['Addie Elizabeth Woodbury',     'SC','Myrtle Beach','2027-06-30'],
        ['Lisa J Yazici',                'SC','Myrtle Beach','2026-06-30'],
        ['Rositsa Yordanova',            'SC','Myrtle Beach','2028-06-30'],
        // SC — Conway
        ['Gerry Gilbert',                'SC','Conway',''],
        ['Ronald Lee Booth',             'SC','Conway','2027-06-30'],
        ['Sheri Anita Cole Smith',       'SC','Conway','2027-06-30'],
        ['Amanda Lynne Gunter',          'SC','Conway','2027-06-30'],
        ['Veronica Smyth',               'SC','Conway','2028-06-30'],
        ['Melissa Vella',                'SC','Conway','2027-06-30'],
        // SC — Hilton Head
        ['Brie Bender',                  'SC','Hilton Head',''],
        ['Michael Fries',                'SC','Hilton Head',''],
        // SC — Greenville
        ['Kimberly A Guillory',          'SC','Greenville','2027-06-30'],
        ['Maryhelen Medina-Tolbert',     'SC','Greenville','2027-06-30'],
        ['Jessica Spikes',               'SC','Greenville','2027-06-30'],
        // SC — Multi-Office
        ['Todd Allen',                   'SC','Multi-Office','2026-06-30'],
        ['Madison Phillips Baldwin',     'SC','Multi-Office','2027-06-30'],
        ['Brittany A Booker',            'SC','Multi-Office','2025-06-30'],
        ['Daniel Hawkins Brown',         'SC','Multi-Office','2025-06-30'],
        ['Alison Mary Dagostino',        'SC','Multi-Office','2026-06-30'],
        ['Matthew Gorham',               'SC','Multi-Office','2027-06-30'],
        ['Stephen Hart',                 'SC','Multi-Office','2026-06-30'],
        ['Kerwin Emerson Heath',         'SC','Multi-Office','2026-06-30'],
        ['Kathryn Kim Loizzi',           'SC','Multi-Office','2026-06-30'],
        ['Cristino Melendez',            'SC','Multi-Office','2026-06-30'],
        ['Daniel J Murphy',              'SC','Multi-Office','2026-06-30'],
        ['Jason Michael Reynolds',       'SC','Multi-Office','2027-06-30'],
        ['Lisa Smith',                   'SC','Multi-Office','2027-06-30'],
        ['Franklyn Solorzano',           'SC','Multi-Office','2026-06-30'],
        ['Shayne Steiner',               'SC','Multi-Office','2026-06-30'],
        ['Alysia Beth Stern',            'SC','Multi-Office','2026-06-30'],
        ['Michael Thompson',             'SC','Multi-Office','2027-06-30'],
        ['William Alexander Tomb',       'SC','Multi-Office','2026-06-30'],
        ['James Rivalier Van Deventer',  'SC','Multi-Office','2026-06-30'],
        // SC — Pawleys Island
        ['Sarah Ethy Ballabani',         'SC','Pawleys Island','2026-06-30'],
        ['Leslie Hanna Cooper',          'SC','Pawleys Island','2027-06-30'],
        ['Christopher Brock Cooper',     'SC','Pawleys Island','2026-06-30'],
        ['Deborah L Donovan Rice',       'SC','Pawleys Island','2026-06-30'],
        ['Rebecca A Noble',              'SC','Pawleys Island','2025-06-30'],
        ['Kelly Anne Olivet',            'SC','Pawleys Island','2025-06-30'],
        ['Olivia Russell',               'SC','Pawleys Island','2025-06-30'],
        ['Katherine Nicole Sargent',     'SC','Pawleys Island','2027-06-30'],
        // SC — North Myrtle Beach
        ['Tonya Mills Allen',            'SC','North Myrtle Beach','2025-06-30'],
        ['David Walter Avery',           'SC','North Myrtle Beach','2026-06-30'],
        ['James Dustin Batten',          'SC','North Myrtle Beach','2027-06-30'],
        ['Linda C Bocchino',             'SC','North Myrtle Beach','2026-06-30'],
        ['Brian C Bray',                 'SC','North Myrtle Beach','2026-06-30'],
        ['Jonathan Blake Butler',        'SC','North Myrtle Beach','2026-06-30'],
        ['Robin H Chestnutt',            'SC','North Myrtle Beach','2027-06-30'],
        ['Mary Claire Clonts',           'SC','North Myrtle Beach','2025-06-30'],
        ['April Campbell Coutsos',       'SC','North Myrtle Beach','2025-06-30'],
        ["Tina Marie D'Amato",           'SC','North Myrtle Beach','2026-06-30'],
        ['Kathleen L Dulhagen',          'SC','North Myrtle Beach','2025-06-30'],
        ['Jaclyn S Edwards',             'SC','North Myrtle Beach','2025-06-30'],
        ['Charlene Dean Ellison',        'SC','North Myrtle Beach','2025-06-30'],
        ['Jennifer Faircloth',           'SC','North Myrtle Beach','2026-06-30'],
        ['Steven Graiff',                'SC','North Myrtle Beach','2026-06-30'],
        ['Adam Scott Lane',              'SC','North Myrtle Beach','2026-06-30'],
        ['Nicole Byrd Lane',             'SC','North Myrtle Beach','2027-06-30'],
        ['Virginia Ann Laroche',         'SC','North Myrtle Beach','2026-06-30'],
        ['Holly J Levasseur',            'SC','North Myrtle Beach','2026-06-30'],
        ['Stephanie Graham Lilly',       'SC','North Myrtle Beach','2025-06-30'],
        ['Noah Cooper Livingston',       'SC','North Myrtle Beach','2025-06-30'],
        ['Darlene M Olivo',              'SC','North Myrtle Beach','2026-06-30'],
        ['Kayla B Parrinello',           'SC','North Myrtle Beach','2025-06-30'],
        ['William Jordan Rogers',        'SC','North Myrtle Beach','2026-06-30'],
        ['Elizabeth A Rogers',           'SC','North Myrtle Beach','2026-06-30'],
        ['Susan Joan Rossi',             'SC','North Myrtle Beach','2026-06-30'],
        ['Joseph John Sacks',            'SC','North Myrtle Beach','2028-06-30'],
        ['Mark Louis Santora',           'SC','North Myrtle Beach','2027-06-30'],
        ['Michelle Leigh Seales',        'SC','North Myrtle Beach','2025-06-30'],
        ['Jay Seville',                  'SC','North Myrtle Beach','2027-06-30'],
        ['Travis A Smith',               'SC','North Myrtle Beach','2026-06-30'],
        ['Petrina Stanley',              'SC','North Myrtle Beach','2026-06-30'],
        ['Pamela Thaxton',               'SC','North Myrtle Beach','2026-06-30'],
        ['Justin T Thompson',            'SC','North Myrtle Beach','2026-06-30'],
        ['Preston Greer Thompson',       'SC','North Myrtle Beach','2026-06-30'],
        ['Wendy Wolbert',                'SC','North Myrtle Beach','2025-06-30'],
        ['Robert Allen Woods',           'SC','North Myrtle Beach','2027-06-30'],
        // SC — Charleston
        ['Shelley Cribbs Monahan',       'SC','Charleston','2026-06-30'],
        ['Debbie Himebaugh',             'SC','Charleston',''],
        // SC — Hartsville
        ['Natalie N Bedenbaugh',         'SC','Hartsville','2025-06-30'],
        ['Sherri S Goode',               'SC','Hartsville','2026-06-30'],
        ['William Charles Johnson',      'SC','Hartsville',''],
        ['Margaret Elizabeth Lineberger','SC','Hartsville','2025-06-30'],
        ['David Thompson',               'SC','Hartsville','2026-06-30'],
        ['Madison Johnson',              'SC','Hartsville',''],
        // SC — Murrells Inlet
        ['Joseph Alini',                 'SC','Murrells Inlet','2027-06-30'],
        ['Antonia Alini',                'SC','Murrells Inlet','2026-06-30'],
        ['Andrew Bennett',               'SC','Murrells Inlet','2025-06-30'],
        ['Christal Lynn Cason',          'SC','Murrells Inlet','2026-06-30'],
        ['Michael James Chimento',       'SC','Murrells Inlet','2026-06-30'],
        ['Sonya Louise Cicardi',         'SC','Murrells Inlet','2027-06-30'],
        ['Diane Cleland',                'SC','Murrells Inlet','2028-06-30'],
        ['Allison Quinn Cooper',         'SC','Murrells Inlet','2026-06-30'],
        ['Melanie Stella Corpening',     'SC','Murrells Inlet','2025-06-30'],
        ['April Marie Demure',           'SC','Murrells Inlet','2028-06-30'],
        ['Gordon Grout',                 'SC','Murrells Inlet','2025-06-30'],
        ['Emily Jane Higdon',            'SC','Murrells Inlet','2025-06-30'],
        ['Michael B Higdon',             'SC','Murrells Inlet','2025-06-30'],
        ['Robert G Hughart',             'SC','Murrells Inlet','2027-06-30'],
        ['Rhonda Eileen Hughart',        'SC','Murrells Inlet','2025-06-30'],
        ['Joseph A Laveglia',            'SC','Murrells Inlet','2026-06-30'],
        ['Kim Diane Lynch',              'SC','Murrells Inlet','2026-06-30'],
        ['Derek J Macleod',              'SC','Murrells Inlet','2025-06-30'],
        ['Courtney Patterson Martin',    'SC','Murrells Inlet','2025-06-30'],
        ['Paul F Mayer',                 'SC','Murrells Inlet','2026-06-30'],
        ['David Brian Mercer',           'SC','Murrells Inlet','2026-06-30'],
        ['Corinne Sue Morin',            'SC','Murrells Inlet','2025-06-30'],
        ['Stephen T Morrow',             'SC','Murrells Inlet','2026-06-30'],
        ['Christina Ann Osborne',        'SC','Murrells Inlet','2026-06-30'],
        ['Thomas V Palermo',             'SC','Murrells Inlet','2025-06-30'],
        ['Nicole Palermo',               'SC','Murrells Inlet','2025-06-30'],
        ['Nancy Pratt Patrick',          'SC','Murrells Inlet','2025-06-30'],
        ['Alison Pavy',                  'SC','Murrells Inlet','2025-06-30'],
        ['Kathleen Marie Reiersen',      'SC','Murrells Inlet','2026-06-30'],
        ['Anna Lowery Richardson',       'SC','Murrells Inlet','2026-06-30'],
        ['Corey Richardson',             'SC','Murrells Inlet',''],
        ['Gregory Thomas Arcuri Sanders','SC','Murrells Inlet','2026-06-30'],
        ['Corey Allan Sanders',          'SC','Murrells Inlet','2026-06-30'],
        ['Deborah H Shine',              'SC','Murrells Inlet','2026-06-30'],
        ['Patrick J Shine',              'SC','Murrells Inlet','2026-06-30'],
        ['Cheryl Lynn Smith',            'SC','Murrells Inlet','2027-06-30'],
        ['Frank Vigna',                  'SC','Murrells Inlet','2027-06-30'],
        ['Annie Halliday Williams',      'SC','Murrells Inlet','2026-06-30'],
        ['Darren S Woodard',             'SC','Murrells Inlet','2026-06-30'],
        ['Kris Fuller',                  'SC','Murrells Inlet',''],
    ];
}
