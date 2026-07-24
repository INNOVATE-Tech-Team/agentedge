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
    // Migration: vacant listings can skip scheduling specific date/time slots
    // and stay listed in the pool as "available anytime" instead.
    try { $pdo->exec("ALTER TABLE oh_listings ADD COLUMN no_schedule INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}

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
    // Migration: tracks whether the "your step is ready" email has already gone out
    try { $pdo->exec("ALTER TABLE onboard_steps ADD COLUMN notified_at TEXT"); } catch (\Exception $e) {}
    // Migration: PandaDoc document id for the doc_signing step, so the signing
    // webhook can map a completed document back to the right step (also lets a
    // failed send retry resume the same document instead of creating a duplicate).
    try { $pdo->exec("ALTER TABLE onboard_steps ADD COLUMN pandadoc_document_id TEXT"); } catch (\Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_onboard_steps_pandadoc_doc ON onboard_steps(pandadoc_document_id)");

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
    // Migration: tracks whether the "your step is ready" email has already gone out
    try { $pdo->exec("ALTER TABLE offboard_steps ADD COLUMN notified_at TEXT"); } catch (\Exception $e) {}

    // Staff notified by email when a specific onboarding/offboarding step is
    // added (heads-up) and when it becomes the next actionable step.
    // step_key matches the 'key' from onboard_tools()/offboard_tools().
    $pdo->exec("CREATE TABLE IF NOT EXISTS step_notify_staff (
        process   TEXT NOT NULL,   -- 'onboard' | 'offboard'
        step_key  TEXT NOT NULL,
        email     TEXT NOT NULL,
        PRIMARY KEY (process, step_key, email)
    )");

    // The onboarding/offboarding checklist itself — editable via
    // admin_step_notify.php. is_auto=1 steps show a "Provision Now" button
    // wired to real provisioning code in api/onboard_action.php|offboard_action.php
    // ('fub' and 'constellation1' have handlers, but are set is_auto=0 by
    // default on the onboard side — FUB requires being under its seat limit,
    // and Constellation1's handler is an unfinished stub — so they render as
    // plain manual checklist items unless re-enabled; on the offboard side
    // 'agentedge' also has a real handler — deactivate_agentedge_account() in
    // lib/agentedge_account.php). Adding a new step here is always a manual step.
    $pdo->exec("CREATE TABLE IF NOT EXISTS step_defs (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        process   TEXT    NOT NULL,   -- 'onboard' | 'offboard'
        step_key  TEXT    NOT NULL,
        label     TEXT    NOT NULL,
        note      TEXT    NOT NULL DEFAULT '',
        is_auto   INTEGER NOT NULL DEFAULT 0,
        sort_ord  INTEGER NOT NULL DEFAULT 0,
        UNIQUE(process, step_key)
    )");
    // One-time seed from the checklist that used to be hardcoded in
    // onboard_tools.php / offboard_tools.php — INSERT OR IGNORE so re-running
    // this on an already-seeded install (or one with admin-added rows) is a no-op.
    $seedSteps = [
        ['onboard','agentedge',      'AgentEdge Account',   'Created when added to queue',              0, 10],
        ['onboard','doc_signing',    'Document Signing',    'Sent via PandaDoc — auto-completes once signed', 1, 25],
        ['onboard','fub',            'Follow Up Boss',      'Manual — add via Follow Up Boss admin',    0, 30],
        ['onboard','constellation1', 'Constellation1',      'Manual — add via Constellation1',          0, 40],
        ['onboard','dotloop',        'DotLoop',              'Add manually in DotLoop admin',           0, 50],
        ['onboard','listingstoleads','ListingsToLeads',      'Add manually',                            0, 60],
        ['onboard','maxa',           'MAXA Presents',        'Add manually',                            0, 70],
        ['onboard','email_setup',    'Email & Signature',     'Set up company email + signature',       0, 90],
        ['onboard','training',       'New Agent Training',    'Enroll in onboarding training program',  0, 100],

        ['offboard','exit_interview',    'Exit Interview',           'Capture departure reason, last day, and book of business transfer', 0, 10],
        ['offboard','final_commissions', 'Final Commission Review',  'Confirm all pending deals are closed and final check issued',       0, 20],
        ['offboard','agentedge',         'AgentEdge Account',        'Auto-deactivate — removes login + roster listing',                   1, 25],
        ['offboard','fub',               'Follow Up Boss',           'Auto-deactivate via API',                                            1, 30],
        ['offboard','constellation1',    'Constellation1',           'Auto-deactivate via API',                                            1, 40],
        ['offboard','dotloop',           'DotLoop',                  'Remove seat in DotLoop admin',                                       0, 50],
        ['offboard','mls',               'MLS Access',               'Submit MLS membership removal form',                                 0, 60],
        ['offboard','listingstoleads',   'ListingsToLeads',          'Remove from account',                                                0, 70],
        ['offboard','maxa',              'MAXA Presents',            'Remove from account',                                                0, 80],
        ['offboard','email_decom',       'Company Email',            'Decommission company email and signature',                           0, 90],
        ['offboard','intranet',          'Company Intranet',         'Remove from everythinginnovate.com',                                 0, 100],
    ];
    $seedStepIns = $pdo->prepare(
        "INSERT OR IGNORE INTO step_defs (process, step_key, label, note, is_auto, sort_ord) VALUES (?,?,?,?,?,?)"
    );
    foreach ($seedSteps as $s) { $seedStepIns->execute($s); }
    // Migration: 'agentedge' offboard step moved from last (sort_ord 110, manual)
    // to right after Final Commission Review (sort_ord 25, real auto-deactivate
    // handler) — only touches installs still on the original default, so any
    // admin customization via admin_step_notify.php is left alone.
    $pdo->exec(
        "UPDATE step_defs SET sort_ord=25, is_auto=1,
            note='Auto-deactivate — removes login + roster listing'
         WHERE process='offboard' AND step_key='agentedge' AND sort_ord=110 AND is_auto=0"
    );

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
    try { $pdo->exec("ALTER TABLE agent_roles ADD COLUMN extra_roles_json TEXT NOT NULL DEFAULT '[]'"); } catch (\Exception $e) {}

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

    // AgentEdge's own login credentials — the local replacement for Perfex
    // tblstaff auth, checked first in attempt_login() (auth.php) before
    // falling back to the Perfex bridge. Deliberately just email+hash —
    // display identity (name/photo) is resolved fresh from tblstaff/
    // innovate_roster on each login rather than cached here.
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_passwords (
        email         TEXT PRIMARY KEY,
        password_hash TEXT NOT NULL,
        updated_at    TEXT NOT NULL DEFAULT ''
    )");

    // Login audit trail — every successful sign-in, password or Google.
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_events (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        email        TEXT    NOT NULL DEFAULT '',
        name         TEXT    NOT NULL DEFAULT '',
        method       TEXT    NOT NULL DEFAULT 'password',
        ip           TEXT    NOT NULL DEFAULT '',
        user_agent   TEXT    NOT NULL DEFAULT '',
        logged_in_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Forgot-password reset tokens — single-use, short-lived. Also doubles as
    // the "set your initial password" link for agents provisioned directly in
    // AgentEdge (no Perfex account to fall back on).
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        token      TEXT PRIMARY KEY,
        email      TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at    TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prt_email ON password_reset_tokens(email)");

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
    // Migration: personal/contact details + online presence fields
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN personal_email      TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN commissions_email   TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN address_line1       TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN address_line2       TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN city                TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN state               TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN zip                 TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN country             TEXT NOT NULL DEFAULT 'United States'"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN drivers_license     TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN gender              TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN website             TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN additional_websites TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN facebook            TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN linkedin            TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN skype               TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN instagram           TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN email_signature     TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    // Migration: professional background + entity/tax fields (self-reported by the agent)
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN personal_tax_id_enc  TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN corporate_tax_id_enc TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN corporation_start    TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN corporation_end      TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN career_start         TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN prior_occupation     TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN prior_affiliation    TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN specialty            TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN full_time            INTEGER NOT NULL DEFAULT 1"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN show_on_internet     INTEGER NOT NULL DEFAULT 1"); } catch (\Exception $e) {}
    // Migration: remaining social platforms, so agent_intake can be the single
    // source of truth for social links (previously split across agent_extra.social_json).
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN twitter             TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN youtube             TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN tiktok              TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE agent_intake ADD COLUMN blog                TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

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

    // Additional licenses beyond the primary one captured on agent_intake itself
    // (e.g. multi-state licensees). Rewritten in full (delete+reinsert) on every
    // intake save rather than diffed, since there's no stable per-row identity
    // coming from the form.
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_intake_licenses (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email    TEXT NOT NULL,
        license_number TEXT NOT NULL DEFAULT '',
        license_state  TEXT NOT NULL DEFAULT '',
        license_exp    TEXT NOT NULL DEFAULT '',
        created_at     TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_licenses_email ON agent_intake_licenses(agent_email)");

    // Public "complete your profile" links (emailed reminders + backoffice
    // send-link action). A new random token is minted every time a link is
    // sent — old tokens for the same agent are never invalidated, so a
    // resend can't break a link that's still sitting unopened in someone's
    // inbox. Not expiring/single-use by design: this is a low-stakes
    // reminder to fill in missing profile fields, not a security-sensitive
    // action.
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_completion_tokens (
        token      TEXT PRIMARY KEY,
        email      TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        created_by TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_profile_completion_tokens_email ON profile_completion_tokens(email)");

    // Ensure headshots directory exists and is web-protected
    $hsDir = $dir . '/headshots';
    if (!is_dir($hsDir)) @mkdir($hsDir, 0750, true);
    $hsHt  = $hsDir . '/.htaccess';
    if (!file_exists($hsHt)) @file_put_contents($hsHt, "Deny from all\n");

    // Company Email attachments — served only through api/email_attachment.php
    // (auth-gated, unlike email_images which are public for email-client fetches),
    // so this is web-protected the same as headshots.
    $eaDir = $dir . '/email_attachments';
    if (!is_dir($eaDir)) @mkdir($eaDir, 0750, true);
    $eaHt  = $eaDir . '/.htaccess';
    if (!file_exists($eaHt)) @file_put_contents($eaHt, "Deny from all\n");

    // Per-agent documents — Documents tab on agent_profile.php. Populated
    // automatically by the PandaDoc signing webhook (source='pandadoc') and/or
    // manually by an admin (source='manual').
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_documents (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        email        TEXT    NOT NULL,
        name         TEXT    NOT NULL,
        source       TEXT    NOT NULL DEFAULT 'manual',
        external_ref TEXT    NOT NULL DEFAULT '',
        mime_type    TEXT    NOT NULL DEFAULT '',
        size_bytes   INTEGER NOT NULL DEFAULT 0,
        storage_key  TEXT    NOT NULL,
        uploaded_by  TEXT    NOT NULL DEFAULT '',
        created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_agent_documents_email ON agent_documents(email)");
    // Compliance doc typing (license, E&O cert, MLS paperwork, CE credit, onboarding, other).
    try { $pdo->exec("ALTER TABLE agent_documents ADD COLUMN category TEXT NOT NULL DEFAULT 'other'"); } catch (\Exception $e) {}
    $adDir = $dir . '/agent_documents';
    if (!is_dir($adDir)) @mkdir($adDir, 0750, true);
    $adHt  = $adDir . '/.htaccess';
    if (!file_exists($adHt)) @file_put_contents($adHt, "Deny from all\n");

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
    // attachment_ids: comma-separated email_attachments.id list, only ever set
    // by Company Email sends — every other notification_queue writer omits it
    // and gets the '' default (no attachments).
    try { $pdo->exec("ALTER TABLE notification_queue ADD COLUMN attachment_ids TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    // from_email/from_name: the AgentEdge user whose action triggered this
    // email, so it sends from them instead of the system default. Blank
    // means no specific actor (system/cron/webhook-triggered) — falls back
    // to cfg()'s sendgrid_from at send time.
    try { $pdo->exec("ALTER TABLE notification_queue ADD COLUMN from_email TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE notification_queue ADD COLUMN from_name  TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    // is_html: Company Email sends pre-rendered HTML bodies; everything else
    // (announcements, ticket notifications) is plain text and gets the 0 default.
    // Already live on production ahead of this migration (see cron/process_email_queue.php,
    // api/company_email_action.php) — added here so a fresh install's schema matches.
    try { $pdo->exec("ALTER TABLE notification_queue ADD COLUMN is_html INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    // reply_to: per-ticket Reply-To address (reply+{id}-{token}@...) so a reply
    // typed in the recipient's mail client routes back into the ticket thread.
    // Only ticket notifications set this; everything else gets '' (no Reply-To header).
    try { $pdo->exec("ALTER TABLE notification_queue ADD COLUMN reply_to TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

    // ── Company Email (Back Office) ───────────────────────────────────────────
    // These three tables existed live on Lightsail long before this migration
    // was written (created by an untracked one-off script) — schema below
    // matches production exactly, backfilled here so fresh installs get them too.
    $pdo->exec("CREATE TABLE IF NOT EXISTS scheduled_emails (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_email    TEXT    NOT NULL,
        sender_role     TEXT    NOT NULL DEFAULT '',
        audience        TEXT    NOT NULL,             -- all | admin | mc | person | leaders
        target_mc_slug  TEXT    NOT NULL DEFAULT '',
        target_email    TEXT    NOT NULL DEFAULT '',
        subject         TEXT    NOT NULL,
        body            TEXT    NOT NULL,
        send_at         TEXT    NOT NULL,
        status          TEXT    NOT NULL DEFAULT 'pending',  -- pending | sent | failed | canceled
        recipient_count INTEGER NOT NULL DEFAULT 0,
        created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_emails (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_email    TEXT    NOT NULL,
        sender_role     TEXT    NOT NULL DEFAULT '',
        audience        TEXT    NOT NULL,             -- all | admin | mc | leaders
        target_mc_slug  TEXT    NOT NULL DEFAULT '',
        subject         TEXT    NOT NULL,
        body            TEXT    NOT NULL,
        recipient_count INTEGER NOT NULL DEFAULT 0,
        sent_at         TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_signatures (
        email        TEXT PRIMARY KEY,
        title        TEXT NOT NULL DEFAULT '',
        phone        TEXT NOT NULL DEFAULT '',
        calendar_url TEXT NOT NULL DEFAULT '',
        website_url  TEXT NOT NULL DEFAULT '',
        updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    // leader_types: comma-separated subset of mc_leader,bic — only meaningful
    // when audience='leaders'; lets a sender pick MC Leaders, BICs, or both.
    try { $pdo->exec("ALTER TABLE scheduled_emails ADD COLUMN leader_types TEXT NOT NULL DEFAULT 'mc_leader,bic'"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE company_emails   ADD COLUMN leader_types TEXT NOT NULL DEFAULT 'mc_leader,bic'"); } catch (\Exception $e) {}
    // Lets a sender write a fully custom rich-text signature instead of the
    // structured title/phone/calendar/website fields — when use_custom=1 and
    // custom_html is non-empty, ce_signature_html() returns it as-is and skips
    // the auto photo/phone/links entirely.
    try { $pdo->exec("ALTER TABLE email_signatures ADD COLUMN use_custom  INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE email_signatures ADD COLUMN custom_html TEXT    NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

    // Reusable Company Email templates — personal by default, is_shared=1 makes
    // one visible/loadable by anyone with Company Email access, not just the owner.
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_email TEXT    NOT NULL,
        name        TEXT    NOT NULL,
        subject     TEXT    NOT NULL DEFAULT '',
        body_html   TEXT    NOT NULL DEFAULT '',
        is_shared   INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_templates_owner ON email_templates(owner_email)");

    // Files uploaded for a Company Email send (multi-select in the compose
    // form). Uploaded and given a random token immediately on file-pick, before
    // the parent send/schedule row exists — send/schedule then link the tokens'
    // ids (comma-separated) onto scheduled_emails/company_emails/notification_queue.
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_attachments (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        token       TEXT    NOT NULL UNIQUE,
        owner_email TEXT    NOT NULL,
        orig_name   TEXT    NOT NULL DEFAULT '',
        mime_type   TEXT    NOT NULL DEFAULT '',
        size_bytes  INTEGER NOT NULL DEFAULT 0,
        storage_key TEXT    NOT NULL,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_attachments_token ON email_attachments(token)");
    // attachment_ids: comma-separated email_attachments.id list for the send.
    try { $pdo->exec("ALTER TABLE scheduled_emails ADD COLUMN attachment_ids TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE company_emails   ADD COLUMN attachment_ids TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

    // General-purpose "do Y at future time X" scheduling engine, drained by
    // cron/process_scheduled_tasks.php. task_type is dispatched in a switch
    // there — first consumer is 'onboard_followup_text' (the 10-day
    // post-onboarding check-in text), more task types can be added later
    // without a new table.
    $pdo->exec("CREATE TABLE IF NOT EXISTS scheduled_tasks (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        task_type    TEXT    NOT NULL,
        payload_json TEXT    NOT NULL DEFAULT '{}',
        fire_at      TEXT    NOT NULL,
        status       TEXT    NOT NULL DEFAULT 'pending',  -- pending | sent | failed
        created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        executed_at  TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sched_tasks_due ON scheduled_tasks(status, fire_at)");

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

    // Seed default Vault departments + top-level category folders for
    // Brokerage/Company and HR/Payroll docs. INSERT OR IGNORE so a name/folder
    // renamed later by an admin is never overwritten by this seed.
    $vaultDepts = [
        ['brokerage',  'Brokerage & Company', 0],
        ['hr-payroll', 'HR & Payroll',        1],
    ];
    $vdi = $pdo->prepare("INSERT OR IGNORE INTO vault_depts (slug,name,sort_ord) VALUES (?,?,?)");
    foreach ($vaultDepts as $d) { $vdi->execute($d); }

    $vaultFolders = [
        ['vf-brokerage-corp-formation', 'Corporate Formation & Governance', 'dept',   'brokerage'],
        ['vf-brokerage-insurance',      'Insurance & E&O',                  'dept',   'brokerage'],
        ['vf-brokerage-bic-records',    'BIC Records',                      'dept',   'brokerage'],
        ['vf-brokerage-policies',       'Office Policies & Procedures',     'public', ''],
        ['vf-hr-employment',            'Employment Agreements',            'dept',   'hr-payroll'],
        ['vf-hr-commission',            'Commission Plans',                 'dept',   'hr-payroll'],
        ['vf-hr-payroll-records',       'Payroll Records (W-2/1099)',       'dept',   'hr-payroll'],
    ];
    $vfi = $pdo->prepare(
        "INSERT OR IGNORE INTO vault_folders (id,parent_id,name,visibility,dept_slug,created_by,created_at)
         VALUES (?,NULL,?,?,?,'system',datetime('now'))"
    );
    foreach ($vaultFolders as $f) { $vfi->execute($f); }

    // ── Support Tickets ───────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_departments (
        slug TEXT PRIMARY KEY,
        name TEXT NOT NULL
    )");
    try { $pdo->exec("ALTER TABLE support_departments ADD COLUMN sort_ord INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    // Canonical department list + display order. INSERT OR IGNORE so existing
    // rows (and any tickets already filed under them) are never dropped, then
    // an explicit UPDATE keeps name/sort_ord correct even for rows seeded
    // before this list existed (OR IGNORE alone wouldn't touch them).
    $seedDepts = [
        ['onboarding',          'Onboarding',            0],
        ['support',             'Support',               1],
        ['tech',                'Tech Support',          2],
        ['finance',             'Finance',               3],
        ['marketing',           'Marketing',             4],
        ['legal-compliance',    'Legal & Compliance',    5],
        ['training-education',  'Training & Education',  6],
        ['compliance',          'Compliance',             7],
        ['transactions',        'Transactions',          8],
        ['offboarding',         'Offboarding',           9],
        ['property-management', 'Property Management',  10],
        ['notary',              'Notary',                11],
    ];
    $di = $pdo->prepare("INSERT OR IGNORE INTO support_departments (slug,name,sort_ord) VALUES (?,?,?)");
    $du = $pdo->prepare("UPDATE support_departments SET name=?, sort_ord=? WHERE slug=?");
    foreach ($seedDepts as $d) { $di->execute($d); $du->execute([$d[1], $d[2], $d[0]]); }

    // Staff routed to a department — notified by email when a ticket lands there.
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_department_staff (
        dept_slug TEXT NOT NULL,
        email     TEXT NOT NULL,
        PRIMARY KEY (dept_slug, email)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        title       TEXT    NOT NULL,
        body        TEXT    NOT NULL,
        status      TEXT    NOT NULL DEFAULT 'open',  -- open | in_progress | answered | on_hold | closed
        dept_slug   TEXT,
        agent_email TEXT    NOT NULL,
        agent_name  TEXT    NOT NULL DEFAULT '',
        assigned_to TEXT,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tkt_agent ON support_tickets(agent_email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tkt_status ON support_tickets(status)");
    try { $pdo->exec("ALTER TABLE support_tickets ADD COLUMN issue_type TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

    // Issue types — the second dropdown on the New Ticket form (Department >
    // Issue Type > Details). A flat catalog, not scoped per department.
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_issue_types (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL UNIQUE,
        sort_ord   INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $seedIssueTypes = [
        'Dotloop', 'Legal & Compliance', 'Profile Update', 'Listings2Leads', 'Email Signature',
        'Google Business Page', 'Facebook', 'Cap/Transaction Questions', 'Compliant', 'Coaching Program',
        'MLS', 'Commission Checks', 'Agent Billing', 'Broker Pay', 'Revenue Share', '1099', 'Stocks',
        'Swag', 'Marketing', 'Signs', 'Onboarding', 'Offboarding', 'Team Agreements', 'Website',
        'Charitable Contribution', 'University/Skool', 'MAXA', 'FUB', 'Notary',
    ];
    $iti = $pdo->prepare("INSERT OR IGNORE INTO support_issue_types (name,sort_ord) VALUES (?,?)");
    foreach ($seedIssueTypes as $i => $name) $iti->execute([$name, $i]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_ticket_messages (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        author    TEXT    NOT NULL,
        is_staff  INTEGER NOT NULL DEFAULT 0,
        body      TEXT    NOT NULL,
        created_at TEXT   NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tktmsg_tid ON support_ticket_messages(ticket_id)");

    // CC'd staff on a ticket — included in reply notifications alongside the
    // agent/department staff.
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_ticket_cc (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id  INTEGER NOT NULL,
        email      TEXT    NOT NULL,
        added_by   TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tktcc_tid ON support_ticket_cc(ticket_id)");

    // Running activity log (status changes, CC add/remove, assignment) shown
    // inline in the ticket thread alongside messages.
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_ticket_events (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id   INTEGER NOT NULL,
        event_type  TEXT    NOT NULL,
        detail      TEXT    NOT NULL DEFAULT '',
        actor_email TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tktevt_tid ON support_ticket_events(ticket_id)");

    // Attachments — screenshots/documents attached to a ticket message (the
    // initial description or a reply), uploaded by either the agent or staff.
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_ticket_files (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id   INTEGER NOT NULL,
        message_id  INTEGER NOT NULL,
        orig_name   TEXT    NOT NULL,
        mime_type   TEXT    NOT NULL DEFAULT '',
        size_bytes  INTEGER NOT NULL DEFAULT 0,
        storage_key TEXT    NOT NULL,
        uploaded_by TEXT    NOT NULL,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tktfiles_msg ON support_ticket_files(message_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tktfiles_tid ON support_ticket_files(ticket_id)");

    $tktUploadsDir = $dir . '/ticket_uploads';
    if (!is_dir($tktUploadsDir)) @mkdir($tktUploadsDir, 0750, true);
    $tktUploadsHt = $tktUploadsDir . '/.htaccess';
    if (!file_exists($tktUploadsHt)) @file_put_contents($tktUploadsHt, "Deny from all\n");

    // Predefined replies — quick canned responses staff can insert into a reply.
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_canned_replies (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title      TEXT    NOT NULL,
        body       TEXT    NOT NULL,
        sort_ord   INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Knowledge base links — staff can insert one into a reply for agent resources.
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_kb_links (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title      TEXT    NOT NULL,
        url        TEXT    NOT NULL,
        sort_ord   INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

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
    try { $pdo->exec("ALTER TABLE uni_courses ADD COLUMN invite_only INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE uni_courses ADD COLUMN state_filter TEXT NOT NULL DEFAULT '[]'"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE uni_courses ADD COLUMN role_filter TEXT NOT NULL DEFAULT '[]'"); } catch (\Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_course_invites (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL,
        agent_email TEXT    NOT NULL,
        invited_by  TEXT    NOT NULL DEFAULT '',
        invited_at  TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(course_id, agent_email)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_invites_course ON uni_course_invites(course_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_folders (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id  INTEGER NOT NULL,
        title      TEXT    NOT NULL,
        sort_ord   INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_folders_crs ON uni_folders(course_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_lessons (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id    INTEGER NOT NULL,
        title        TEXT    NOT NULL,
        sort_ord     INTEGER NOT NULL DEFAULT 0,
        type         TEXT    NOT NULL DEFAULT 'video',  -- video | doc | quiz | placeholder | upload
        file_key     TEXT    NOT NULL DEFAULT '',
        content_html TEXT    NOT NULL DEFAULT '',
        duration_sec INTEGER NOT NULL DEFAULT 0,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_lessons_crs ON uni_lessons(course_id)");
    try { $pdo->exec("ALTER TABLE uni_lessons ADD COLUMN embed_url TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE uni_lessons ADD COLUMN folder_id INTEGER"); } catch (\Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_lessons_folder ON uni_lessons(folder_id)");
    try { $pdo->exec("ALTER TABLE uni_lessons ADD COLUMN tags TEXT NOT NULL DEFAULT '[]'"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE uni_lessons ADD COLUMN learning_objective TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE uni_lessons ADD COLUMN difficulty TEXT NOT NULL DEFAULT 'beginner'"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE uni_lessons ADD COLUMN related_lessons TEXT NOT NULL DEFAULT '[]'"); } catch (\Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_lesson_files (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id     INTEGER NOT NULL,
        file_key      TEXT    NOT NULL,
        original_name TEXT    NOT NULL DEFAULT '',
        sort_ord      INTEGER NOT NULL DEFAULT 0,
        created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_lfiles_lesson ON uni_lesson_files(lesson_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_questions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id     INTEGER NOT NULL,
        question      TEXT    NOT NULL,
        options       TEXT    NOT NULL DEFAULT '[]',  -- JSON array of option strings
        correct_index INTEGER NOT NULL DEFAULT 0,
        sort_ord      INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_q_lesson ON uni_questions(lesson_id)");
    try { $pdo->exec("ALTER TABLE uni_questions ADD COLUMN qtype TEXT NOT NULL DEFAULT 'single'"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE uni_questions ADD COLUMN correct_indexes TEXT NOT NULL DEFAULT '[]'"); } catch (\Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_quiz_answers (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id        INTEGER NOT NULL,
        agent_email      TEXT    NOT NULL,
        question_id      INTEGER NOT NULL,
        answer_text      TEXT    NOT NULL DEFAULT '',
        selected_indexes TEXT    NOT NULL DEFAULT '[]',
        submitted_at     TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_qa_lesson ON uni_quiz_answers(lesson_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS uni_learner_uploads (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id     INTEGER NOT NULL,
        agent_email   TEXT    NOT NULL,
        file_key      TEXT    NOT NULL,
        original_name TEXT    NOT NULL DEFAULT '',
        submitted_at  TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(lesson_id, agent_email)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uni_lupload_lesson ON uni_learner_uploads(lesson_id)");

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

    // ── Per-state PandaDoc onboarding-agreement templates. Not pre-seeded —
    // rows are added as each state's template is built out (up to 50).
    // Falls back to the global 'pandadoc_template_id' config value for any
    // state without a row here (see pandadoc_template_for_state()).
    $pdo->exec("CREATE TABLE IF NOT EXISTS pandadoc_state_templates (
        state_code  TEXT PRIMARY KEY,
        template_id TEXT NOT NULL DEFAULT '',
        updated_by  TEXT NOT NULL DEFAULT '',
        updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
    )");

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
    // Email/phone stored directly on the roster row (editable via Back Office →
    // Agent Roster → Edit) — api/roster.php prefers these over a Perfex tblstaff
    // lookup, and sync_roster_identity() (lib/roster.php) keeps them in sync
    // across an agent's other-state rows.
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN email TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE innovate_roster ADD COLUMN phone TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

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

    // ── Finance: Accounting Checklists (recurring projects / task follower) ───
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_checklist_templates (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        recurrence  TEXT    NOT NULL DEFAULT 'monthly',  -- monthly | quarterly | annual | one_time
        active      INTEGER NOT NULL DEFAULT 1,
        created_by  TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_checklist_template_items (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id             INTEGER NOT NULL,
        title                   TEXT    NOT NULL,
        description             TEXT    NOT NULL DEFAULT '',
        default_assignee_email  TEXT    NOT NULL DEFAULT '',
        sort_ord                INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fcti_template ON finance_checklist_template_items(template_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_checklist_runs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER NOT NULL,
        period_label TEXT   NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'open',  -- open | closed
        created_by  TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fcr_template ON finance_checklist_runs(template_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_checklist_run_items (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        run_id            INTEGER NOT NULL,
        title             TEXT    NOT NULL,
        description       TEXT    NOT NULL DEFAULT '',
        status            TEXT    NOT NULL DEFAULT 'todo',  -- todo | done
        assigned_to_email TEXT    NOT NULL DEFAULT '',
        due_date          TEXT    NOT NULL DEFAULT '',
        notes             TEXT    NOT NULL DEFAULT '',
        sort_ord          INTEGER NOT NULL DEFAULT 0,
        completed_at      TEXT    NOT NULL DEFAULT '',
        completed_by      TEXT    NOT NULL DEFAULT '',
        updated_at        TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fcri_run ON finance_checklist_run_items(run_id)");

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

    // ── MLS / State Offices & Licenses (from annual license report) ──────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS mls_offices (
        id                    INTEGER PRIMARY KEY AUTOINCREMENT,
        state                 TEXT    NOT NULL DEFAULT '',
        branch_office         TEXT    NOT NULL DEFAULT '',
        entity_name           TEXT    NOT NULL DEFAULT '',
        dba                   TEXT    NOT NULL DEFAULT '',
        office_type           TEXT    NOT NULL DEFAULT '',
        office_license_number TEXT    NOT NULL DEFAULT '',
        license_expiration    TEXT,
        firm_type             TEXT    NOT NULL DEFAULT 'Residential',
        designated_broker     TEXT    NOT NULL DEFAULT '',
        market_leader         TEXT    NOT NULL DEFAULT '',
        broker_license_number TEXT    NOT NULL DEFAULT '',
        broker_expiration     TEXT,
        fub_phone             TEXT    NOT NULL DEFAULT '',
        address               TEXT    NOT NULL DEFAULT '',
        lease_payee           TEXT    NOT NULL DEFAULT '',
        notes                 TEXT    NOT NULL DEFAULT '',
        mls_integration_id    INTEGER,
        created_by            TEXT    NOT NULL DEFAULT '',
        created_at            TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at            TEXT    NOT NULL DEFAULT (datetime('now')),
        FOREIGN KEY (mls_integration_id) REFERENCES mls_integrations(id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mo_state ON mls_offices(state)");
    // Seed known state offices/licenses on fresh installs (from Dept. of Lic. annual report)
    if ($pdo->query("SELECT COUNT(*) FROM mls_offices")->fetchColumn() == 0) {
        $mo = $pdo->prepare("INSERT INTO mls_offices
            (state,branch_office,entity_name,dba,office_type,office_license_number,license_expiration,
             firm_type,designated_broker,market_leader,broker_license_number,broker_expiration,
             fub_phone,address,lease_payee,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $offices = [
            ['DE','','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','','00000000','2026-04-30','Residential','Monica Peterson','','RB-0031407','2026-04-30','302-279-2545','153 E Chestnut Hill Rd, Ste 100F, Newark, DE 19713','',''],
            ['FL','Bradenton','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','','CQ1072664','2026-09-30','Residential','Brie Bender','','BK3636462','2027-09-30','941-213-7736','117 7th Street N, Unit 7, Bradenton Beach, FL 34217','',''],
            ['FL','Referrals','INNOVATE Real Estate Referrals LLC','','','CQ1072827','2026-09-30','Referral','Brie Bender','','BK3636462','2027-09-30','NA','117 7th Street N, Unit 7, Bradenton Beach, FL 34217','',''],
            ['GA','','INNOVATE Real Estate SC LLC','INNOVATE Real Estate','','00082669','2029-11-30','Residential','Michael Fries','','421553','2026-07-31','','32 Office Park Road, Hilton Head, SC 29928','',''],
            ['MA','','','','','',null,'Residential','Manny Manenez','','',null,'','','',''],
            ['MD','','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','','00606500',null,'Residential','Monica Peterson','','606500','2027-05-16','443-960-7690','153 E Chestnut Hill Rd, Ste 100F, Newark, DE 19713','',''],
            ['NC','','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','Broker Owned/State HQ','C40905','2026-06-30','Residential','Carrie Kinney','Shawn Carter','283428','2026-06-30','910-776-2496','7290-7 Beach Dr SW Ocean Isle Beach, NC 28469-0000','','Shawn Carter is ML of only the Raleigh area'],
            ['NC','Referrals','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','','C40905','2026-06-30','Referral','Kris Fuller','','287606','2026-06-30','NA','767 Main St, N. Myrtle Beach, SC 29577 ( RE Commission has the wrong address)','','North Carolina licenses renew every year'],
            ['NH','','INNOVATE Real Estate SC, LLC','','','',null,'Residential','Manny Manenez','','',null,'(978) 736-3582','','',''],
            ['NJ','','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','Broker Owned/State HQ','02669143',null,'Residential','Rosemarie Heldman','','1325101',null,'908-888-9704','408 Main Street, 2nd Floor, Chester, NJ 07930','',''],
            ['OH','','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','','REC.2025004161','2026-06-30','Residential','Joe Tupta','','BRKP.2025003277','2027-07-14','330-422-4628','4249 Massillon Rd N Canton, OH 44720','',''],
            ['PA','Doylestown','INNOVATE Doylestown LLC','','Branch/Team Office','RB070309','2026-05-31','Residential','Melanie Henderson','Diane Cleland','RM426259','2026-05-31','484-573-7792','2 W Butler Ave, Doylestown, PA 18901','',''],
            ['PA','North Wales','INNOVATE Doylestown LLC','INNOVATE Real Estate','Branch/Team Office','RO302928','2026-05-31','Residential','Melanie Henderson','Earl, Kevin, Carolyn, & Jeff','RM426259','2026-05-31','(215) 267-8551','109 N 2nd Street, North Wales, PA 19454','',''],
            ['PA','Harleysville','INNOVATE Doylestown LLC','INNOVATE Real Estate','Branch/Team Office','RO302933','2026-05-31','Residential','Melanie Henderson','Dan Smith','RM426259','2026-05-31','215-607-6977','840 Harleysville Pike, Suite B, Harleysville, PA 19438','',''],
            ['PA','','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','','RBR006793','2026-05-31','Residential','Monica Peterson','','RMR006794','2026-05-31','','153 E Chestnut Hill Rd, Ste 100F, Newark, DE 19713','',''],
            ['PA','Quakertown','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','Broker Owned/State HQ','RB302877','2026-05-31','Residential','Melanie Henderson','','RMR006794','2026-05-31','','15 S 14th Street Quakertown, PA  18951','',"Not sure who's office this is??"],
            ['RI','','','','','',null,'Residential','Manny Manenez','','',null,'','','',''],
            ['SC','Hartsville','','INNOVATE Real Estate','Corp Owned/State HQ','00022016','2027-06-30','Residential','Carrie Kinney','','134620','2027-06-30','843-896-2969','125 A N 5th St Hartsville SC 29550','Privately Held',''],
            ['SC','Charleston','','INNOVATE Real Estate','Corp Owned/State HQ','00022203','2027-06-30','Residential','Carrie Kinney','','134620','2027-06-30','843-594-2753','1240 Winnowing Way, Ste 100 PMB Mt. Pleasant, SC 29466','Regus - Mailbox only',''],
            ['SC','North Myrtle','','INNOVATE Real Estate','Corp Owned/State HQ','00023326','2027-06-30','Residential','Carrie Kinney','','134620','2027-06-30','843-892-6754','767 Main St, N. Myrtle Beach, SC 29582','Privately Held',''],
            ['SC','Pawleys Island','','INNOVATE Real Estate','Corp Owned/State HQ','00024074','2027-06-30','Residential','Carrie Kinney','','134620','2027-06-30','843-892-6754','11405 Ocean Hwy, Unit 6, Pawleys Island, SC 29585','Privately Held',''],
            ['SC','Hilton Head','','INNOVATE Real Estate','Corp Owned/State HQ','00028307','2027-06-30','Residential','Michael Fries','','134620','2027-06-30','843-627-2085','32 Office Park Road, Hilton Head, SC 29928','Regus - Mailbox only',''],
            ['SC','Greenville','','INNOVATE Real Estate','Corp Owned/State HQ','00028207',null,'Residential','Jessica Spikes','','101891','2027-06-30','864-477-1935','128 Millport Cir, Ste 200, Greenville, SC 29607','Regus - Mailbox only',''],
            ['SC','Murrells Inlet','','INNOVATE Real Estate','Corp Owned/State HQ','00021494','2026-06-30','Residential','Kris Fuller','','100914','2026-06-30','843-892-6754','3103 US-17 Business, Unit F, Murrells Inlet, SC 29576','Privately Held',''],
            ['SC','Multi MLS','','INNOVATE Real Estate','Corp Owned/State HQ','00024912','2026-06-30','Residential','Kris Fuller','','100914','2026-06-30','843-892-6754','1309 Professional Drive, Myrtle Beach, SC 29577','Privately Held',''],
            ['SC','Professional Dr','','INNOVATE Real Estate','Corp Owned/State HQ','00018484','2026-06-30','Residential','Kris Fuller','','100914','2026-06-30','843-892-6754','1309 Professional Drive, Myrtle Beach, SC 29577','Privately Held',''],
            ['SC','Conway','','INNOVATE Real Estate','','00028579','2027-06-30','Residential','Gerry Gilbert','','105604','2027-06-30','','314 Laurel St, Ste 314-A, Conway, SC 29526','',''],
            ['SC','Referral','','INNOVATE Real Estate','Corp Owned/State HQ','00018653','2026-06-30','Referral','Kris Fuller','','100914','2026-06-30','NA','1309 Professional Drive, Myrtle Beach, SC 29577','Privately Held',''],
            ['TN','Referral','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','Broker Owned/State HQ','00266150','2027-08-15','Referral','Brenda Brewster','','274139','2027-03-21','NA','9111 Cross Park Dr, Ste D200, STE 283, Knoxville, TN 37923','Spaces',''],
            ['TN','East TN','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','Broker Owned/State HQ','00266105','2027-07-20','Residential','Brenda Brewster','','274139','2027-03-21','865-535-7958','9111 Cross Park Dr, Ste D200, STE 283, Knoxville, TN 37923','Spaces',''],
            ['TN','Middle TN','INNOVATE Real Estate SC, LLC','INNOVATE Real Estate','','00266075','2027-06-15','Residential','Monique Westbrooks','','333086','2026-08-22','615-880-6888','106 Public Sq, Ste 1, Gallatin, TN 37066','',''],
            ['VA','','INNOVATE Real Estate INC','INNOVATE Real Estate','','0 226038285','2026-12-31','Residential','Amy Adams','','225211799','2026-12-31','540-501-7800','1320 Central Park Blvd, Ste 200, Fredericksburg, VA 22401','',''],
        ];
        foreach ($offices as $o) { $mo->execute($o); }
    }

    // ── MLS / Board Memberships (login + billing credentials per office) ─────
    $pdo->exec("CREATE TABLE IF NOT EXISTS mls_memberships (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        state             TEXT    NOT NULL DEFAULT '',
        board_or_mls      TEXT    NOT NULL DEFAULT '',
        name              TEXT    NOT NULL DEFAULT '',
        membership_type   TEXT    NOT NULL DEFAULT '',
        address           TEXT    NOT NULL DEFAULT '',
        phone             TEXT    NOT NULL DEFAULT '',
        office_id         TEXT    NOT NULL DEFAULT '',
        broker_of_record  TEXT    NOT NULL DEFAULT '',
        username          TEXT    NOT NULL DEFAULT '',
        password          TEXT    NOT NULL DEFAULT '',
        login_link        TEXT    NOT NULL DEFAULT '',
        notes             TEXT    NOT NULL DEFAULT '',
        billing_site      TEXT    NOT NULL DEFAULT '',
        billing_frequency TEXT    NOT NULL DEFAULT '',
        billing_username  TEXT    NOT NULL DEFAULT '',
        billing_password  TEXT    NOT NULL DEFAULT '',
        office_fees       TEXT    NOT NULL DEFAULT '',
        broker_fees       TEXT    NOT NULL DEFAULT '',
        admin_fees        TEXT    NOT NULL DEFAULT '',
        created_by        TEXT    NOT NULL DEFAULT '',
        created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at        TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mm_state ON mls_memberships(state)");
    // Seed known board/MLS memberships on fresh installs (from Board_MLS annual report)
    if ($pdo->query("SELECT COUNT(*) FROM mls_memberships")->fetchColumn() == 0) {
        $mm = $pdo->prepare("INSERT INTO mls_memberships
            (state,board_or_mls,name,membership_type,address,phone,office_id,broker_of_record,
             username,password,login_link,notes,billing_site,billing_frequency,billing_username,
             billing_password,office_fees,broker_fees,admin_fees)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $memberships = [
            ['DE','Board','New Castle County Board of Realtors','Primary (Board)','3615 Miller Rd, Wilmington, DE 19802','302.762.4800','248012079','Monica Peterson','','','','','','','','','','',''],
            ['FL','Board','Realtor Association of Sarasota & Manatee','Secondary (Board)','2320 Catlemen Rd, Sarasota, FL 34232','941.952.3400','281532745','Brie Bender','752511499','Florida123! ','MYRASM.COM','','','','','','','',''],
            ['FL','Board','Suncoast Tampa Association of Realtors','Secondary (Board)','4590 Ulmerton Rd, Clearwater, FL 33762','727.347.7655','260034049','Brie Bender','','','','','','','','','','',''],
            ['FL','Board','Realtor Association of Indian River County','Secondary (Board)','3250 67th St, Vero Beach, FL 32967','772.567.3510','276004366','Brie Bender','','','','','','','','','','',''],
            ['FL','Board','Orlando Regional Realtor Assoc','Secondary (Board)','5421 Diplomat Cir., Orlando, FL 32810','407.253.3580','','Brie Bender','','','','','','','','','','',''],
            ['FL','MLS','StellarMLS','MLS','PO BOx 150658, Altamonte Springs, FL 32715-0658','800.686.7451','752524172','Brie Bender','752511499','Florida123!!','https://www.stellarmls.com/','','','','','','','',''],
            ['FL','MLS','Beaches','MLS','','561.585.4544','752511499','Brie Bender','BenderBR','Florida843!!!','https://beachesmls.mysolidearth.com/resources/enter','','','','','','','',''],
            ['GA','Board & MLS','Savannah Area Realtors','MLS Only',' 7015 Hodgson memorial Dr, Savannah, GA 31406','912.354.1513','','Michael Fries','421553','Southern310!','https://hivemls.relevateone.com/dashboard','','','','','','','',''],
            ['NC','Board','Brunswick County Association of Realtor','Primary (Board)','5051 Main St. Suite 5, Shallotte, NC 28470','910.754.5700','752528316','Carrie Kinney','','','','','','','','','','',''],
            ['NC','Board','Triangle Assoc of Realtors','Secondary (Board)','','','752528316','Carrie Kinney','kinneycar','Diesel1972!','','','','','','','','','$45/quarterly'],
            ['NC','Board','Cape Fear Assoc of Realtors','Secondary (Board)','','','752528316','Carrie Kinney','carrie@innovateonline.com','Innovate2025!!','','','','','','','','',''],
            ['NC','Board','Central Carolina Assoc of Realtors','Secondary (Board)','1826 Sir Tyler Dr. Suite 100, Wilmington, NC 28405','910.762.7400','M1 552500608','Carrie Kinney','554031769','','https://next.navicamls.net/659/Ams/AMSIndex','','','','','','','',''],
            ['NC','Board','Winston Salem Assoc of Realtors','Secondary (Board)','195 Executive Park Blvd, Winston-Salem, NC 27103','336.768.5560','752528316','','','','','','','','','','','',''],
            ['NC','Board & MLS','Carolina Smokies','Secondary (Board)','131 Heritage Hollow Dr, Franklin, NC 28734','828.524.1179','','Carrie Kinney','CarrieKinney','','https://next.navicamls.net/253','','','','','','','',''],
            ['NC','Board & MLS','Canopy','Secondary (Board)','1120 Pearl Park Way Suite 200, Charlotte, NC 28204','704.940.3159','R03684','Carrie Kinney','R28479','Charlotte123!','https://login.canopymls.com/idp/login','','','','','','','','$30/quarterly'],
            ['NC','Board & MLS','Roanoke Valley Lake Gaston','Secondary (Board)','PO Box 746, Roanoke Rapids, NC 27870','252.676.4679','','Carrie Kinney','IRE_2025','Innovate25!','','','','','','','','',''],
            ['NC','Board & MLS','High Country','Secondary (Board)','4469 Bamboo Rd, Boone, NC 28607','828.262.5437','','Carrie Kinney','283428','','','','','','','','','',''],
            ['NC','MLS','Doorify | Raleigh','Secondary (Board)','','','','Carrie Kinney','beadlingwhi','Innovate2025!','https://sso.tangilla.com/auth/realms/0415/protocol/openid-connect/auth?client_id=tmls_dashboard&redirect_uri=https%3A%2F%2Fmy.doorifymls.com%2Fhome%2FLoginSSO&response_type=code&scope=openid%20profile%20email%20roles&code_challenge=bCpvBWbNqTmKK79_NTpJ5LibxBhAQiT0LQRQiQMZRH0&code_challenge_method=S256&nonce=638821560682762738.ZmU2NjU2MzAtZTY5ZC00MmJiLTgwNjEtMGIzYTJjODJiODM3OTg4Y2FjYTUtMGVlYy00NDdkLWJhZGItYTM1ZTI3ZmYyZDZl&state=CfDJ8PnQsi8x0u5AoU0MGk3ZLDDAgY4Xby_Dt56Yr0me7LkG8EMYD_6RPMwpyj37Zvy4refOVb1zLpN8CfbPsHnmmq1HsfW-bNe4y9qSBXmRB-5MU7ZSvnRc2ns8iWYxhkj3idHToA0Y9igk-rSSOZLAePkbCamqRiajMmK1WWK9cvSIL9HI3OiyAAEfE3liVyJbqKC6UKiTxmBbFwmTHgnplv8f-Pqjpknhd3vIon6zzrBxy-q7HHzUCTm0T3cOCmNEd-Wp7TR1h5-KF5w3VJHj_hgmvAUGxk6OeCjFpgrvx6HIGJKZAS7xGR4HDvT7lOZaBjAaIUsZFF0gg30yEvu1DdnTN1Fm0hwtWjxEmX44Qr2h-9bze_kgAVxmRCG9Kl3EGg&x-client-SKU=ID_NETSTANDARD2_0&x-client-ver=6.10.0.0','','','','','','','',''],
            ['NJ','Board','Mid Jersey AoR','Secondary (Board)','14 Old Bridge Turnpike, South River, NJ 08882','732-442-3400','609516338','Rosemarie Heldmann','whitney@innovateonline.com','beadling','MidNJ Portal','','','','','','','',''],
            ['NJ','Board','North Central Jersey Assoication of Realtors','Primary (Board)','910 Mt. Kemble Ave, Morristown, NJ 07960','973.425.0110','609516338','Rosemarie Heldmann','','','https://ncjar.com/','','','','','','','',''],
            ['NJ','Board','Gloucester Salem Counties Board of Realtors','Secondary (Board)','343 Glassboro Rd #103, Woodbury Heights, NJ 08097','856.345.1116','','Rosemarie Heldmann','','','http://gscbor.com/','','','','','','','',''],
            ['NJ','Board & MLS','Cape May County Association of Realtors','Secondary (Board)','','','','Rosemarie Heldmann','InnovateRE','Innovate2026!','https://capemay.paragonrels.com/ParagonLS/Default.mvc/Login','','','','','','','',''],
            ['NJ','MLS','Garden State MLS','MLS','1719 NJ-10 #223, Parsippany, NJ 07054','973.898.1900','6402','Rosemarie Heldmann','410131','Innovate843','https://mls.gsmls.com/member/','','','','','','','',''],
            ['NJ','MLS','BrightMLS','MLS','PO Box 37093, Baltimore, MD 21297-3093','844.552.7444','INNVRESC1','Rosemarie Heldmann','w.beadling','InnovateDE2025!!!','https://login.brightmls.com/login','','','','','','','',''],
            ['NJ','MLS','South Jersey Shore MLS','MLS Only','','','','Rosemarie Heldmann','wbeadling','Innovate2026!!!','https://sjsr.paragonrels.com/ParagonLS/Default.mvc/Login','will be prompted to change your password on the first Tuesday of every month.','https://atci.rapams.com/scripts/mgrqispi.dll','','wbeadling','Ire2026!','','',''],
            ['NJ','MLS','Monmouth Ocean Regional MLS','MLS Only','','','','Rosemarie Heldmann','mo.p2518','Monmouth1!!','https://www.flexmls.com/ticket/login','','','','','','','',''],
            ['OH','Board','Akron Cleveland Association of Realtors','Primary (Board)','9100 South Hills Blvd, Suite 150, Broadview heights, OH 44147','216901.013','657026902','Joe Tupta','','','','','','','','','','',''],
            ['OH','MLS','MLS Now','MLS','5605 Valley Belt Rd, Brooklyn Heights, OH 44131','216.485.4100','20743','Joe Tupta','612929','InnovateAkron123!','https://mmsi-mlsnow.us.auth0.com/login?state=hKFo2SBHRUg2Q3VFbWlaYUlyb1Nya0VjMGRqRXVsZXRHbFhnZ6FupWxvZ2luo3RpZNkgclFxa2doZ3Iyek42dzY0bHVVWHVzLWdDMTBxVEJ5RHWjY2lk2SB0YlAwTGJ1cHBDZWJZWFR4QXhmME1NYlNPTW9pT2Nubw&client=tbP0LbuppCebYXTxAxf0MMbSOMoiOcno&protocol=samlp&SAMLRequest=fZJdT%2BswDIb%2FSpX7Nm3GYIu2SYMJMWlj1VaOzjk3KE1dFqn5oE5h%2FHv6AdK4YFeRHL9%2B%2FNqeodCV48vGH80eXhtAH5x0ZZD3H3PS1IZbgQq5ERqQe8kPy%2B2GsyjmrrbeSluRM8llhUCE2itrSLBezckzK0R5cyXGYjxKrth0AmXBGOTFFGIY3QCb5jJJZDmdFCT4AzW2yjlpC7VyxAbWBr0wvg3F7DqMk5BNsuSajyd8xP6TYNW6UUb4XnX03iGnVGtUoa7Q2PeowUi0zuNIWk17w9TnabzJG%2BfuIP%2F3NzstT2W83eaH3daqnTSWBMtvD3fWYKOhPkD9piQ87TdnlOId8qhjsaG40q6CDkG1LZoKInd0PZLi8LJQSOyjQ3MkSL%2FGe6tMoczL5cnmQxLyhyxLw3R3yMhi1tXl%2FaTqxW%2BdDTRa2RdlOvyMnqtmw308trz1KrWVkh%2FBva218Jfb6SKqCMs%2BlbtudejBeEIXA%2BDnzS0%2BAQ%3D%3D&RelayState=https%3A%2F%2Fmdweb.mmsi2.com%2Fmlsnow%2Flogin.php','','','','','','','',''],
            ['PA','Board','Bucks County Association of Realtors','Secondary (Board)','','','','Monica Peterson','','','','','','','','','','',''],
            ['PA','Board','Pennsylvania Association of Realtors','','500 N Twelfth St, Lemoyne, PA 17043','717.561.1303','716518899','Melanie Henderson','','','','','','','','','','',''],
            ['PA','Board','Tri-County Suburban Realtors','Primary (Board)','1 Country View Rd Suite 201, Malvern PA 19355','610.560.4800','','Melanie Henderson','','','','','','','','','','',''],
            ['PA | Delaware','MLS','BrightMLS','MLS','PO Box 37093, Baltimore, MD 21297-3093','844.552.7444','INOVR1','Monica Peterson','w.beadling','InnovateDE2025!!!','https://login.brightmls.com/login','','','','','','','',''],
            ['PA | Doylestown','MLS','BrightMLS','MLS','PO Box 37093, Baltimore, MD 21297-3093','844.552.7444','INORE1','Melanie Henderson','w.beadling','InnovateDE2025!!!','https://login.brightmls.com/login','','','','','','','',''],
            ['PA | Doylestown','MLS','BrightMLS','MLS','PO Box 37093, Baltimore, MD 21297-3093','844.552.7444','INOVR2','Monica Peterson','w.beadling','InnovateDE2025!!!','https://login.brightmls.com/login','','','','','','','',''],
            ['PA | Harleysville','MLS','BrightMLS','MLS','PO Box 37093, Baltimore, MD 21297-3093','844.552.7444','INORE2','Melanie Henderson','w.beadling','InnovateDE2025!!!','https://login.brightmls.com/login','','','','','','','',''],
            ['PA | North Wales','MLS','BrightMLS','MLS','PO Box 37093, Baltimore, MD 21297-3093','844.552.7444','INORE3','Melanie Henderson','w.beadling','InnovateDE2025!!!','https://login.brightmls.com/login','','','','','','','',''],
            ['SC | CHS','MLS','CHS Regional MLS','MLS','5006 Wetland Crossing, Charleston, SC 29418','843.760.9410','9460','Carrie Kinney','chs.35694','Charleston843!','CHS Regional Dashboard','','','','','','','',''],
            ['SC | CHS','MLS','CHS Regional MLS','MLS','5006 Wetland Crossing, Charleston, SC 29418','843.760.9410','10419','Carrie Kinney','chs.35694','Charleston843!','CHS Regional Dashboard','','','','','','','','$30/quarterly'],
            ['SC | Columbia','MLS','Consolidated Multiple Listing Service','MLS','138 Westpark Blvd, Columbia, SC 29210','803.799.7167','','','Whitney Beadling','Columbia123!!!','','Access Denied?','','','','','','','None'],
            ['SC | GV','Board','Greater Greenville Association of Realtors','Primary (Board)','50 Airpark Ct, Greenville, SC 29607','864.672.4427','752025626','Jessica Spikes','','','','','','','','','','',''],
            ['SC | GV','MLS','Greater Greenville Association of Realtors','MLS','50 Airpark Ct, Greenville, SC 29607','864.672.4427','10869','Jessica Spikes','752023199','Innovate2025!!','Greenville Paragon','','https://greatergreenvilleassociationofrealtors.growthzoneapp.com/MIC/30393811/30393662/#/MyBillingInfo/MakeaPaymentList','','whitney@innovateonline.com','Greenville123!','','$145/quarter','NONE'],
            ['SC | HH','Board','Hilton Head Association of Realtors','Secondary (Board)','32 Office Park Rd, Hilton Head Island, SC 29926','843.842.2421','309500828','Carrie Kinney','','','https://hhrealtor.com/','','','','','','','',''],
            ['SC | HH','MLS','Resides','MLS','18 Bow Cir, Hilton Head Island, SC 29928','843.785.9696','1084','Carrie Kinney','1084402','Innovate26!','https://hhi.clareity.net/','**Admin Access??','','','','','','','$10/monthly'],
            ['SC | HH','MLS','Beaufort-Jasper County Realtors','MLS',"22 Kemmerlin Lane, Lady's Island, SC 29907",'843.525.6435','','Carrie Kinney','','','','**Admin Access??','','','','','','',''],
            ['SC | HV','Board','Pee Dee Realtor Association ','Secondary (Board)','1375 Celebration Blvd, Florence SC 29501','843.665.2242','750012958','Carrie Kinney','','','','','','','','','','',''],
            ['SC | HV','MLS','Pee Dee Realtor Association ','MLS','1375 Celebration Blvd, Florence SC 29501','843.665.2242','596','Carrie Kinney','STF159','Beach843!','https://peedeemls.paragonrels.com/ParagonLS/Default.mvc','Main Hartsville Office','','','','','','','$100/yearly'],
            ['SC | HV','MLS','Pee Dee Realtor Association ','MLS','1375 Celebration Blvd, Florence SC 29501','843.665.2242','BRG21','Kris Fuller','STF1132','Beach843!','https://peedeemls.paragonrels.com/ParagonLS/Default.mvc','Pee Dee Office attached to the Multi Office','','','','','','',''],
            ['SC | MI','Board','Coastal Carolina Association of Realtors','MLS','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','2422','Kris Fuller','','','','','','','','','','',''],
            ['SC | MI','MLS','Coastal Carolina Association of Realtors','Primary (Board)','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','752526590','Kris Fuller','27803','K@ydence0203','CCAR Portal','','','','','','','',''],
            ['SC | NMB','Board','Coastal Carolina Association of Realtors','MLS','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','752532533','Carrie Kinney','','','','','','','','','','',''],
            ['SC | NMB','MLS','Coastal Carolina Association of Realtors','Secondary (Board)','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','2803','Carrie Kinney','27803','K@ydence0203','CCAR Portal','','','','','','','',''],
            ['SC | PI','Board','Coastal Carolina Association of Realtors','MLS','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','752529018','Carrie Kinney','','','','','','','','','','',''],
            ['SC | PI','MLS','Coastal Carolina Association of Realtors','Secondary (Board)','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','2960','Carrie Kinney','27803','K@ydence0203','CCAR Portal','','','','','','','',''],
            ['SC | Pro Dr','Board','Coastal Carolina Association of Realtors','MLS','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','752531397','Kris Fuller','','','','','','','','','','',''],
            ['SC | Pro Dr','Board','Coastal Carolina Association of Realtors','MLS','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','752524172','Kris Fuller','','','','','','','','','','',''],
            ['SC | Pro Dr','MLS','Coastal Carolina Association of Realtors','Primary (Board)','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','1964','Kris Fuller','27803','K@ydence0203','CCAR Portal','','','','','','','',''],
            ['SC | Pro Dr','MLS','Coastal Carolina Association of Realtors','Primary (Board)','951 Shine Ave, Myrtle Beach, SC 29577','843.626.3638','3462','Kris Fuller','27803','K@ydence0203','CCAR Portal','','','','','','','',''],
            ['SC | WUS','MLS','Western Upstate Association of Realtors','MLS Only','600 McGee Rd, Anderson, SC 29625','864.224.7941','747008576','Jessica Spikes','','','','**Admin Access??','','','','','$313.13/quarter','$75/quarter',''],
            ['TN','Board','East Tennessee Realtors','Primary (Board)','609 Weisgarber Rd, Knoxville, TN 37919','865.584.8647','773527676','Brenda Brewster','','','','','','','','','','',''],
            ['TN','Board','Greater Nashville Association of Realtors','Primary (Board)','4540 Trousdale Dr, Nashville, TN 37204','615.254.7516','772017228','Monique Westbrooks','','','','','','','','','','',''],
            ['TN','MLS','East Tennessee Realtors','MLS','609 Weisgarber Rd, Knoxville, TN 37919','865.584.8647','3480o','Brenda Brewster','knx.23368','Knoxville123!!','https://www.flexmls.com/ticket/login','Annual access fee is $150 billed by invoice','','','','','','',''],
            ['TN','MLS','East Tennessee Realtors','MLS','609 Weisgarber Rd, Knoxville, TN 37919','865.584.8647','3685o','Monique Westbrooks','knx.23368','Knoxville123!!','https://www.flexmls.com/ticket/login','','','','','','','',''],
            ['TN','MLS','Realtracs','MLS','','','BRGG01','Monica Peterson','WBeadling','Nashville123!','https://www.realtracs.com/auth/signin','','','','','','','',''],
            ['TN','MLS','Realtracs','MLS','','','BRGK01','Brenda Brewster','WBeadling','Nashville123!','https://www.realtracs.com/auth/signin','','','','','','','',''],
            ['VA','Board','Fredericksburg Area Association of Realtors','Primary (Board)','2050 Gordon W Shelton Blvd., Fredericksburg, VA 22401','540.373.7711','841004554','Amy Adams','','','https://login.brightmls.com/login','','','','','','','',''],
            ['VA | Fredericksburg','MLS','BrightMLS','MLS','PO Box 37093, Baltimore, MD 21297-3093','844.552.7444','INNOVREINC1','Amy Adams','w.beadling','InnovateDE2025!!!','https://login.brightmls.com/login','','','','','','','',''],
            ['VA | Richmond','MLS','BrightMLS','MLS','PO Box 37093, Baltimore, MD 21297-3093','844.552.7444','INRE01-CVR','Amy Adams','w.beadling','InnovateDE2025!!!','https://login.brightmls.com/login','','','','','','','',''],
            ['NC','Board','Raleigh Regional','Primary (Board)','','','223730','Carrie Kinney','','','','','','','','','','',''],
            ['NC','MLS','Doorify | Raleigh','MLS','','','','','','','','','','','','','','',''],
        ];
        foreach ($memberships as $m) { $mm->execute($m); }
    }

    // Backfill: rows present in Carrie's MLS spreadsheet but not captured in the
    // original seed above. Runs on every boot; INSERT is skipped once a row with
    // the same state+name+username already exists, so it's safe to re-run.
    $mmBackfillCheck = $pdo->prepare("SELECT COUNT(*) FROM mls_memberships WHERE state=? AND name=? AND username=?");
    $mmBackfillIns = $pdo->prepare("INSERT INTO mls_memberships
        (state,board_or_mls,name,membership_type,address,phone,office_id,broker_of_record,
         username,password,login_link,notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $mmBackfill = [
        ['NC','MLS','Triad','','','','','Carrie Kinney','283428','Innovate25!','https://triadmls.com/','$63/month (WSAR)'],
        ['SC | HH','MLS','Lowcountry','Flex','','','','Carrie Kinney','bmls.kinneyc','Innovate2025!!','https://bmls.flexmls.com/',''],
        ['SC | CHS','MLS','Charleston Trident Association of Realtors (CTAR)','Matrix','','','4132','Carrie Kinney','4132','4620','https://ims.charlestonrealtors.com/',''],
        ['SC | HV','MLS','Pee Dee Realtor Association','Paragon','','','','Carrie Kinney','554031769','Innovate26!!','https://peedeemls.paragonrels.com/ParagonLS/Default.mvc/Login','carrie@innovateonline.com login; $100/qtr'],
    ];
    foreach ($mmBackfill as $b) {
        $mmBackfillCheck->execute([$b[0], $b[2], $b[8]]);
        if ($mmBackfillCheck->fetchColumn() == 0) { $mmBackfillIns->execute($b); }
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
    // 'registered' or 'waitlisted' — set when an event has a capacity and is full.
    try { $pdo->exec("ALTER TABLE training_rsvps ADD COLUMN status TEXT NOT NULL DEFAULT 'registered'"); } catch (\Exception $e) {}

    // Per-event capacity for training events. Training events themselves live in
    // Google Calendar (no local row) — this just attaches an optional headcount cap.
    $pdo->exec("CREATE TABLE IF NOT EXISTS training_events (
        event_id TEXT PRIMARY KEY,
        capacity INTEGER
    )");

    // ── Company Calendar "Events" tab — same RSVP/capacity/waitlist pattern as
    // Training above, but a separate Google Calendar + separate RSVP pool, so
    // registering for a training session and a company event are independent.
    $pdo->exec("CREATE TABLE IF NOT EXISTS events_rsvps (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id    TEXT    NOT NULL,
        event_title TEXT    NOT NULL DEFAULT '',
        event_date  TEXT    NOT NULL DEFAULT '',
        agent_email TEXT    NOT NULL,
        agent_name  TEXT    NOT NULL DEFAULT '',
        rsvped_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        status      TEXT    NOT NULL DEFAULT 'registered',
        UNIQUE(event_id, agent_email)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ersvp_event ON events_rsvps(event_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ersvp_agent ON events_rsvps(agent_email)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events_calendar (
        event_id TEXT PRIMARY KEY,
        capacity INTEGER
    )");

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

    // ── Suggestions — attachments (screenshots/documents) ─────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS suggestion_files (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        suggestion_id INTEGER NOT NULL,
        orig_name     TEXT    NOT NULL,
        mime_type     TEXT    NOT NULL DEFAULT '',
        size_bytes    INTEGER NOT NULL DEFAULT 0,
        storage_key   TEXT    NOT NULL,
        uploaded_by   TEXT    NOT NULL,
        created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suggestion_files_sugg ON suggestion_files(suggestion_id)");

    $sugUploadsDir = $dir . '/suggestion_uploads';
    if (!is_dir($sugUploadsDir)) @mkdir($sugUploadsDir, 0750, true);
    $sugUploadsHt = $sugUploadsDir . '/.htaccess';
    if (!file_exists($sugUploadsHt)) @file_put_contents($sugUploadsHt, "Deny from all\n");

    // Exit interview — self-service form filled out by a departing agent while
    // their AgentEdge login is still active (offboarding step 'exit_interview',
    // before the 'agentedge' step deactivates their account). See exit_interview.php
    // / api/exit_interview.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_exit_interview (
        email               TEXT PRIMARY KEY,
        queue_id            INTEGER NOT NULL,
        satisfaction_rating INTEGER,
        feedback_management TEXT NOT NULL DEFAULT '',
        feedback_support    TEXT NOT NULL DEFAULT '',
        feedback_training   TEXT NOT NULL DEFAULT '',
        next_destination    TEXT NOT NULL DEFAULT '',
        would_recommend     TEXT NOT NULL DEFAULT '',
        suggestions         TEXT NOT NULL DEFAULT '',
        submitted           INTEGER NOT NULL DEFAULT 0,
        submitted_at        TEXT,
        updated_at          TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // Office address + geocoded coordinates, used to compute recruiting-prospect distance.
    try { $pdo->exec("ALTER TABLE market_centers ADD COLUMN address      TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE market_centers ADD COLUMN city         TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE market_centers ADD COLUMN zip          TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE market_centers ADD COLUMN lat          REAL"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE market_centers ADD COLUMN lng          REAL"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE market_centers ADD COLUMN geocoded_at  TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}

    // Recruiting prospects — agents at other brokerages INNOVATE is trying to recruit.
    // Distinct from innovate_roster (existing/active INNOVATE agents).
    $pdo->exec("CREATE TABLE IF NOT EXISTS recruit_prospects (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name         TEXT    NOT NULL,
        current_brokerage TEXT    NOT NULL DEFAULT '',
        phone             TEXT    NOT NULL DEFAULT '',
        email             TEXT    NOT NULL DEFAULT '',
        address           TEXT    NOT NULL DEFAULT '',
        city              TEXT    NOT NULL DEFAULT '',
        state             TEXT    NOT NULL DEFAULT '',
        zip               TEXT    NOT NULL DEFAULT '',
        lat               REAL,
        lng               REAL,
        geocoded_at       TEXT    NOT NULL DEFAULT '',
        status            TEXT    NOT NULL DEFAULT 'new',
        notes             TEXT    NOT NULL DEFAULT '',
        added_at          TEXT    NOT NULL DEFAULT (datetime('now')),
        added_by          TEXT    NOT NULL DEFAULT '',
        updated_at        TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prospects_status ON recruit_prospects(status)");

    // Free-form staff notes about an agent, shown on the Agent Profile page's
    // Notes tab (agent_profile.php).
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_notes (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        email      TEXT    NOT NULL,
        note       TEXT    NOT NULL,
        created_by TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_agent_notes_email ON agent_notes(email)");

    // Exchange Readiness — IPO milestone tracker (Finance dept, super_admin
    // only). Moved here from the old Advantage/coastline-server admin page
    // 2026-07-14 so AgentEdge owns this natively instead of round-tripping
    // to a separate app. Target listing pushed from NASDAQ 2029 to 2031 —
    // only phase 3's dates shifted; phases 1-2 (audit/governance buildout,
    // OTCQB listing) are unchanged.
    $pdo->exec("CREATE TABLE IF NOT EXISTS exchange_milestones (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        phase          INTEGER NOT NULL,
        category       TEXT    NOT NULL,
        title          TEXT    NOT NULL,
        description    TEXT    NOT NULL DEFAULT '',
        status         TEXT    NOT NULL DEFAULT 'pending',
        target_date    TEXT,
        completed_date TEXT,
        notes          TEXT,
        sort_order     INTEGER NOT NULL DEFAULT 0
    )");
    if ($pdo->query("SELECT COUNT(*) FROM exchange_milestones")->fetchColumn() == 0) {
        $ins = $pdo->prepare("INSERT INTO exchange_milestones (phase,category,title,description,target_date,sort_order) VALUES (?,?,?,?,?,?)");
        foreach ([
            // ── Phase 1 — Year 1: Foundation (Jul 2026 – Jun 2027) ──
            [1,'Advisors','Engage securities attorney','Hire a firm specializing in OTCQB / going-public work (not a generalist). They will clean up the cap table, structure equity grants, and eventually file the SEC registration.','2026-09-30',10],
            [1,'Advisors','Sign PCAOB audit engagement letter for FY2026','CRITICAL PATH — do this before year-end 2026. Must be a PCAOB-registered firm (not your current CPA). Firms to consider: Marcum LLP, Friedman LLP. Audit delay is the #1 reason listings slip.','2026-10-31',20],
            [1,'Advisors','Hire fractional CFO with public-company experience','Fractional CFO ($5K–$15K/month) who has taken a company public before. They own financial systems, internal controls, and the monthly close process.','2026-11-30',30],
            [1,'Corporate','Clean up cap table','Document exactly who owns what percentage of INNOVATE Holdings C-Corp. Formalize or extinguish any informal equity promises to agents/employees. Get a 409A valuation.','2026-12-31',40],
            [1,'Corporate','Document all IP under INNOVATE Holdings C-Corp','Have attorney confirm Coastline CRM, AgentEdge, agent sites, and all code is owned by INNOVATE Holdings C-Corp — not Darren personally.','2026-12-31',50],
            [1,'Corporate','Document entity relationships legally','Clearly separate INNOVATE Holdings (C-Corp parent) from SeaShore Realty Group (S-Corp) and Carolina Property Insurance. Public companies cannot have ambiguous related-party transactions.','2026-12-31',60],
            [1,'Governance','Seat Board of Directors (3+ members, 1 financial expert)','At least one member must qualify as an "audit committee financial expert" (public company accounting background). One real estate industry; one capital markets. Budget $15K–$30K/year per member.','2027-03-31',70],
            [1,'Governance','Establish formal audit committee with written charter','Required for OTCQB and NASDAQ listing. Committee members must be independent directors. Charter documents the scope, authority, and meeting cadence.','2027-03-31',80],
            [1,'Finance','Establish monthly financial close process','Books closed by the 15th of each month. Public companies file 10-Qs within 45 days of quarter end — this discipline starts now.','2027-01-31',90],
            [1,'Finance','409A valuation complete','Required before issuing stock options to employees or agents. Sets the fair market value of common stock for tax purposes.','2027-03-31',100],
            [1,'Growth','Reach 400–500 agents','Company dollar at 300 agents ≈ $2.25M; need $5M–$8M to be taken seriously by investors. Growing to 400–500 agents is the most direct lever. Use Coastline CRM, join.growwithinnovate.com, and social recruiting automation.','2027-06-30',110],
            // ── Phase 2 — Year 2: Build (Jul 2027 – Jun 2028) ──
            [2,'Compliance','Year 2 PCAOB audit complete','Must have 2 full years of audited financials to file Form 10 (OTCQB registration). If Year 1 audit started by Oct 2026, Year 2 should be complete by mid-2028.','2028-04-30',10],
            [2,'Governance','Seat Compensation Committee (written charter)','Sets executive pay, manages stock option plan. Must include independent directors.','2027-12-31',20],
            [2,'Governance','Seat Nominating/Governance Committee (written charter)','Oversees board composition and corporate governance standards.','2027-12-31',30],
            [2,'Compliance','Document internal controls framework','Pre-SOX readiness: financial reporting review process, expense approval thresholds, revenue recognition policy, segregation of duties in accounting. CFO owns this.','2028-01-31',40],
            [2,'Legal','File Form 10 with the SEC','The registration statement that makes INNOVATE a "reporting company." Required for OTCQB listing. Requires 2 years of audited financials. Securities attorney files it — budget 3–6 months.','2028-04-30',50],
            [2,'Listing','OTCQB listing live','Requirements: 50+ beneficial shareholders (100+ shares each), 10% public float, PCAOB-audited financials, current SEC disclosure on EDGAR. Trading begins under INNOVATE ticker.','2028-06-30',60],
            [2,'Capital','Complete Reg D raise ($1M–$5M)','A small Rule 506(b) private offering to accredited investors — agents, friends, family, real estate industry angels — to create the initial public float required for OTCQB.','2028-06-30',70],
            [2,'Technology','Darwin (AccountTECH) integration live in AgentEdge','Per-agent cap amount, paid, remaining, pending/sold count/volume, and sponsor/upline tree live in AgentEdge. Integration meeting was scheduled week of 2026-06-14.','2027-12-31',80],
            [2,'Technology','License Coastline CRM to at least 1 outside brokerage','Transforms the investor story from "brokerage" to "proptech company" — dramatically different valuation multiple. Even 1 paying customer proves SaaS viability.','2028-03-31',90],
            [2,'Marketing','Launch investor relations page on innovateonline.com','Company fact sheet, investor presentation, one-pager. Story: "Technology-powered brokerage for the independent agent." Position CRM and AgentEdge as the IP moat.','2027-12-31',100],
            [2,'Growth','Reach 600–750 agents, $10M+ company dollar revenue','At 85/15 split, 700 agents averaging $1.5M production each = ~$2M in company dollar per agent per year at 15% company cut. Target: $10M–$12M annual company revenue.','2028-06-30',110],
            // ── Phase 3 — Year 3: Uplift (Jul 2030 – Jun 2031) — dates pushed
            // +2 years from the original 2028/2029 plan (target listing now 2031).
            [3,'Compliance','Year 3 PCAOB audit complete','Three full years of audited financials strengthens the NASDAQ application and gives institutional investors the historical view they need.','2031-04-30',10],
            [3,'Capital','File Regulation A+ Tier 2 Form 1-A with SEC','The "mini-IPO" — raise up to $75M from the general public (not just accredited investors). 4–6 month SEC review. Creates broad shareholder base, PR, brand awareness, and the float needed for NASDAQ.','2030-10-31',20],
            [3,'Capital','Reg A+ offering qualified — raise complete','Use proceeds: expand to 1,000+ agents, build out CRM SaaS revenue, potentially acquire a competing small brokerage. Reg A+ proceeds more than cover Year 3 compliance costs.','2031-03-31',30],
            [3,'Listing','Apply to NASDAQ Capital Market','Must meet one of three financial standards: (1) $5M stockholders\' equity, OR (2) $35M+ market cap, OR (3) $750K+ net income. Plus 300+ round-lot shareholders, 1M+ public shares, $4+ bid price, 3 market makers.','2031-03-31',40],
            [3,'Governance','Majority independent board confirmed','NASDAQ requires majority of board to be independent directors. Recruit any remaining independent members before filing.','2031-01-31',50],
            [3,'Governance','Code of business conduct and ethics published','Written, posted publicly. Required for NASDAQ listing. Covers conflicts of interest, insider trading, whistleblower policy.','2031-01-31',60],
            [3,'Listing','NASDAQ Capital Market listing live','Trading begins on NASDAQ. Full SOX Section 302 compliance kicks in: CEO/CFO certifications on quarterly and annual filings.','2031-06-30',70],
            [3,'Marketing','Hire full-time investor relations firm','Budget $100K–$250K/year. Begin institutional investor outreach. Analyst coverage target: 2–3 small-cap real estate analysts in Year 1 as a public company.','2031-06-30',80],
            [3,'Growth','Reach 750–1,000 agents, $15M+ company dollar revenue','At $35M+ market cap threshold for NASDAQ, you need investors to believe a 2–3x revenue multiple is justified. $15M revenue × 2.5x = $37.5M market cap. Tech story is key to achieving that multiple.','2031-06-30',90],
        ] as $r) {
            $ins->execute($r);
        }
    }

    // ── Darwin Cloud custom API sync (lib/darwin.php) ───────────────────────────
    // Mutable auth state — access/refresh token pair, seeded from config.php on
    // first use, then rotated in place as refreshes happen (every refresh returns
    // a new refresh token per AccountTECH's guide, so this must not go stale).
    $pdo->exec("CREATE TABLE IF NOT EXISTS darwin_auth (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT    NOT NULL,
        access_token  TEXT    NOT NULL,
        refresh_token TEXT    NOT NULL,
        expires_at    TEXT    NOT NULL DEFAULT '',
        updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Cap progress — one row per agent, feeds the Agent Edge cap wheel.
    $pdo->exec("CREATE TABLE IF NOT EXISTS darwin_cap_progress (
        agent_person_id        INTEGER PRIMARY KEY,
        agent_first_name       TEXT    NOT NULL DEFAULT '',
        agent_last_name        TEXT    NOT NULL DEFAULT '',
        agent_name             TEXT    NOT NULL DEFAULT '',
        agent_email            TEXT    NOT NULL DEFAULT '',
        commission_plan_id     TEXT    NOT NULL DEFAULT '',
        commission_plan_name   TEXT    NOT NULL DEFAULT '',
        cap_amount              REAL   NOT NULL DEFAULT 0,
        cap_earned              REAL   NOT NULL DEFAULT 0,
        amount_left_to_cap      REAL   NOT NULL DEFAULT 0,
        anniversary_date        TEXT    NOT NULL DEFAULT '',
        anniversary_end_date    TEXT    NOT NULL DEFAULT '',
        agent_start_date        TEXT    NOT NULL DEFAULT '',
        terminated_date         TEXT    NOT NULL DEFAULT '',
        recruited_by_person_id  TEXT    NOT NULL DEFAULT '',
        recruited_by_name       TEXT    NOT NULL DEFAULT '',
        office_id                TEXT   NOT NULL DEFAULT '',
        office_name              TEXT   NOT NULL DEFAULT '',
        company_id               TEXT   NOT NULL DEFAULT '',
        company_name             TEXT   NOT NULL DEFAULT '',
        is_active_agent          INTEGER NOT NULL DEFAULT 1,
        cap_status_modify_date   TEXT    NOT NULL DEFAULT '',
        synced_at                TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dcp_active     ON darwin_cap_progress(is_active_agent)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dcp_recruiter  ON darwin_cap_progress(recruited_by_person_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dcp_email      ON darwin_cap_progress(agent_email)");

    // Revenue share — one row per (recruiter, downline agent, override role) pair,
    // filtered to overrideRole='Revenue Share' at sync time; feeds the growth network.
    $pdo->exec("CREATE TABLE IF NOT EXISTS darwin_revenue_share (
        id                          INTEGER PRIMARY KEY AUTOINCREMENT,
        recruiter_person_id         INTEGER NOT NULL,
        recruiter_name              TEXT    NOT NULL DEFAULT '',
        agent_person_id             INTEGER NOT NULL,
        agent_name                  TEXT    NOT NULL DEFAULT '',
        override_role               TEXT    NOT NULL DEFAULT '',
        ytd_amount                  REAL    NOT NULL DEFAULT 0,
        ytd_amount_closed_basis     REAL    NOT NULL DEFAULT 0,
        ytd_amount_posted_basis     REAL    NOT NULL DEFAULT 0,
        all_time_amount             REAL    NOT NULL DEFAULT 0,
        all_time_paid_amount        REAL    NOT NULL DEFAULT 0,
        voucher_count               INTEGER NOT NULL DEFAULT 0,
        last_override_date          TEXT    NOT NULL DEFAULT '',
        has_current_override_setup INTEGER  NOT NULL DEFAULT 1,
        is_non_producing            INTEGER NOT NULL DEFAULT 0,
        rev_share_modify_date       TEXT    NOT NULL DEFAULT '',
        company_id                  TEXT    NOT NULL DEFAULT '',
        synced_at                   TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(recruiter_person_id, agent_person_id, override_role)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_drs_recruiter ON darwin_revenue_share(recruiter_person_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_drs_agent     ON darwin_revenue_share(agent_person_id)");

    // Sales volume — one row per agent, YTD closed-transaction volume.
    $pdo->exec("CREATE TABLE IF NOT EXISTS darwin_sales_volume (
        agent_person_id                   INTEGER PRIMARY KEY,
        agent_name                        TEXT    NOT NULL DEFAULT '',
        ytd_sales_volume                  REAL    NOT NULL DEFAULT 0,
        ytd_sales_volume_processed_basis  REAL    NOT NULL DEFAULT 0,
        ytd_list_volume                   REAL    NOT NULL DEFAULT 0,
        ytd_sell_volume                   REAL    NOT NULL DEFAULT 0,
        ytd_transaction_count             REAL    NOT NULL DEFAULT 0,
        volume_modify_date                TEXT    NOT NULL DEFAULT '',
        company_id                        TEXT    NOT NULL DEFAULT '',
        synced_at                         TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Commission check submission log — fed by commission_submit.php /
    // api/commission_action.php, read by backoffice_commission_checks.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_check_submissions (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email      TEXT    NOT NULL,
        agent_name       TEXT    NOT NULL DEFAULT '',
        loop_id          TEXT    NOT NULL,
        loop_name        TEXT    NOT NULL DEFAULT '',
        method           TEXT    NOT NULL,
        office_location  TEXT,
        check_original   TEXT,
        check_stored     TEXT,
        dl_check_doc_id  TEXT,
        dl_folder_id     TEXT,
        dotloop_ok       INTEGER NOT NULL DEFAULT 0,
        email_sent       INTEGER NOT NULL DEFAULT 0,
        notes            TEXT,
        submitted_at     TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ccs_agent ON commission_check_submissions(agent_email)");

    // ── Agent OS: cohorts / KPIs / weekly activity ──────────────────────────────
    // Program-agnostic accountability model — LAUNCH is the first consumer
    // (program='launch') but nothing here is LAUNCH-specific by name.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cohorts (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        program       TEXT    NOT NULL DEFAULT 'launch',
        name          TEXT    NOT NULL,
        start_date    TEXT    NOT NULL DEFAULT '',
        cadence_weeks INTEGER NOT NULL DEFAULT 1,
        status        TEXT    NOT NULL DEFAULT 'active',  -- active | graduated | archived
        created_by    TEXT    NOT NULL DEFAULT '',
        created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Coach is assigned per member (not per cohort) so one cohort can span
    // multiple coaches' rosters.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cohort_members (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        cohort_id   INTEGER NOT NULL,
        agent_email TEXT    NOT NULL,
        coach_email TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'active',  -- active | graduated | dropped
        joined_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(cohort_id, agent_email)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cm_agent ON cohort_members(agent_email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cm_coach ON cohort_members(coach_email)");

    // KPI catalog kept as data (not hardcoded) so a future non-LAUNCH program
    // can define its own weekly targets without a code change.
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_definitions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        program       TEXT    NOT NULL DEFAULT 'launch',
        kpi_key       TEXT    NOT NULL,
        label         TEXT    NOT NULL,
        unit          TEXT    NOT NULL DEFAULT 'count',
        weekly_target INTEGER NOT NULL DEFAULT 0,
        sort_ord      INTEGER NOT NULL DEFAULT 0,
        active        INTEGER NOT NULL DEFAULT 1,
        UNIQUE(program, kpi_key)
    )");
    if ($pdo->query("SELECT COUNT(*) FROM kpi_definitions WHERE program='launch'")->fetchColumn() == 0) {
        $ins = $pdo->prepare("INSERT INTO kpi_definitions (program,kpi_key,label,unit,weekly_target,sort_ord) VALUES ('launch',?,?,?,?,?)");
        foreach ([
            ['conversations',     'Conversations',      'count', 20, 10],
            ['appointments',      'Appointments Set',   'count', 4,  20],
            ['signed_agreements', 'Signed Agreements',  'count', 1,  30],
            ['transactions',      'Transactions',       'count', 0,  40],
        ] as $r) { $ins->execute($r); }
    }

    // One row per agent per KPI per ISO week (week_start = that week's Monday).
    // Resubmitting the same week updates in place via ON CONFLICT DO UPDATE.
    $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_activity (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email TEXT    NOT NULL,
        cohort_id   INTEGER,
        kpi_key     TEXT    NOT NULL,
        week_start  TEXT    NOT NULL,
        value       INTEGER NOT NULL DEFAULT 0,
        source      TEXT    NOT NULL DEFAULT 'self',  -- self | auto (auto unused until a call/text log integration exists)
        logged_by   TEXT    NOT NULL DEFAULT '',
        logged_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(agent_email, kpi_key, week_start)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wa_agent_week ON weekly_activity(agent_email, week_start)");

    // Visible-wins log — two rows are system-triggered (first_signed_agreement,
    // week_complete) by api/weekly_activity.php; graduated fires when a coach/admin
    // flips cohort_members.status to 'graduated' via api/cohort_members_action.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS milestones (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        cohort_id     INTEGER,
        agent_email   TEXT    NOT NULL,
        milestone_key TEXT    NOT NULL,  -- week_complete | first_signed_agreement | graduated
        label         TEXT    NOT NULL DEFAULT '',
        achieved_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        note          TEXT    NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ms_agent ON milestones(agent_email)");

    // Written by cron/flag_missed_targets.php — one open (unresolved) row per
    // agent+kpi miss-streak, so a coach is notified once per streak instead of
    // once per cron run.
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_flags (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email        TEXT    NOT NULL,
        cohort_id          INTEGER,
        kpi_key            TEXT    NOT NULL,
        week_start         TEXT    NOT NULL,
        consecutive_misses INTEGER NOT NULL DEFAULT 0,
        flagged_at         TEXT    NOT NULL DEFAULT (datetime('now')),
        coach_email        TEXT    NOT NULL DEFAULT '',
        resolved           INTEGER NOT NULL DEFAULT 0,
        resolved_at        TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_af_agent ON activity_flags(agent_email, kpi_key, resolved)");

    // Per-recipient log of Company Email sends, so a coach/leader's outreach to
    // an agent shows up on that agent's own record (agent_profile.php's
    // Communications tab) instead of only existing in the sender's mail client.
    // Written by lib/company_email.php's ce_log_to_agent_records(), called from
    // both the immediate-send path (api/company_email_action.php) and the
    // scheduled-send worker (cron/process_email_queue.php).
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_comms_log (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_email   TEXT    NOT NULL,
        sender_email  TEXT    NOT NULL DEFAULT '',
        subject       TEXT    NOT NULL DEFAULT '',
        snippet       TEXT    NOT NULL DEFAULT '',
        source_type   TEXT    NOT NULL DEFAULT 'company_email',
        source_id     INTEGER,
        sent_at       TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_acl_agent ON agent_comms_log(agent_email)");

    return $pdo;
}

// Build the "ST - Market Center" display label for a roster row. If $state
// is blank (e.g. a row edited/imported without a state_code), look up the
// same bare MC name in the market_centers master list and other
// innovate_roster rows so the row still joins its proper group instead of
// rendering as an orphaned state-less duplicate (e.g. "RALEIGH" vs
// "NC - RALEIGH").
function mc_label(string $name, string $state): string {
    $name  = trim($name);
    $state = trim($state);
    if ($name === '') return '';
    if ($state !== '') return "$state - $name";

    static $known = null;
    if ($known === null) {
        $known = [];
        try {
            $pdo = local_db();
            foreach ($pdo->query("SELECT name, state_code FROM market_centers WHERE state_code != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $known[strtolower(trim($r['name']))] = trim($r['state_code']);
            }
            foreach ($pdo->query("SELECT market_center, state_code FROM innovate_roster WHERE state_code != '' AND market_center != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = strtolower(trim($r['market_center']));
                if (!isset($known[$key])) $known[$key] = trim($r['state_code']);
            }
        } catch (\Exception $e) {}
    }
    $key = strtolower($name);
    return isset($known[$key]) ? "{$known[$key]} - $name" : $name;
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

// PandaDoc template id for a given state, or null if no state-specific
// template has been set up yet (caller falls back to the global default).
function pandadoc_template_for_state(string $code): ?string {
    $s = local_db()->prepare("SELECT template_id FROM pandadoc_state_templates WHERE state_code=?");
    $s->execute([$code]);
    $id = $s->fetchColumn();
    return ($id !== false && $id !== '') ? $id : null;
}

// All configured per-state PandaDoc templates keyed by state code.
function pandadoc_state_templates_all(): array {
    $rows = local_db()->query("SELECT * FROM pandadoc_state_templates ORDER BY state_code")->fetchAll(PDO::FETCH_ASSOC);
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
