<?php
// Vendorsgiving + shared vendor identity schema (vg_* and vendor_* tables).
// Applied ONLY by the explicit migration tool tools/vendorsgiving_schema.php —
// deliberately NOT referenced from local_db.php's automatic migration routine,
// so no page load can create or alter these tables. Never ALTERs, writes or
// references any existing (non vg_/vendor_) table.
//
// v1 (Checkpoint C): vg_* tables incl. vg_vendors / vg_suppressions.
// v2 (Checkpoint E, approved 2026-11-05): shared vendor identity —
//    vendor_companies ─< vendor_contacts, markets, per-market contact visibility,
//    consent history + verbatim notice versions, vendor-only email suppression,
//    identity audit; vg_invitations points at contact/company; delivery tracking;
//    versioned templates; dev-sink-only message log. Guest DB ceiling 50.
// Parent event record stays mc_events (unchanged); vg_events is a 1:1 sidecar.

const VG_SCHEMA_VERSION = 2;
const VG_GUEST_DB_CEILING = 50;

function vg_schema_tables(): array {
    return ['vg_schema_meta', 'vg_events', 'vg_attendees', 'vg_potluck_categories', 'vg_contributions', 'vg_invitations',
            'vg_audit', 'vg_rate_limits', 'vg_email_templates', 'vg_email_messages',
            'vendor_companies', 'vendor_company_markets', 'vendor_contacts', 'vendor_contact_markets',
            'vendor_notice_versions', 'vendor_consents', 'vendor_email_suppressions', 'vendor_audit'];
}

// ── helpers shared by the migration and lib/vendorsgiving.php ──────────────
function vg_free_mail(string $domain): bool {
    return in_array(strtolower($domain), ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'aol.com', 'comcast.net', 'live.com', 'msn.com', 'me.com', 'att.net', 'sbcglobal.net', 'verizon.net', 'protonmail.com'], true);
}
function vg_email_domain(string $emailNorm): string { $p = strrpos($emailNorm, '@'); return $p === false ? '' : substr($emailNorm, $p + 1); }
function vg_business_domain(string $emailNorm): string { $d = vg_email_domain($emailNorm); return ($d === '' || vg_free_mail($d)) ? '' : $d; }
function vg_name_norm(string $first, string $last): string { return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($first) . ' ' . trim($last)))); }

// ── DDL ─────────────────────────────────────────────────────────────────────
// Tables unchanged from v1.
function vg_schema_ddl_common(): array {
    return [
        "CREATE TABLE IF NOT EXISTS vg_schema_meta (
            id      INTEGER PRIMARY KEY CHECK (id = 1),
            version INTEGER NOT NULL,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        )",
        // 1:1 with mc_events.id. mc_slug is the canonical single market; if the
        // parent mc_events row's mc_slugs ever differs, Vendorsgiving refuses writes.
        "CREATE TABLE IF NOT EXISTS vg_events (
            mc_event_id            INTEGER PRIMARY KEY,
            mc_slug                TEXT    NOT NULL,
            capacity               INTEGER CHECK (capacity IS NULL OR capacity BETWEEN 1 AND 5000),
            rsvp_deadline          TEXT,                       -- 'YYYY-MM-DD HH:MM' America/New_York, or NULL
            potluck_enabled        INTEGER NOT NULL DEFAULT 1 CHECK (potluck_enabled IN (0,1)),
            vendor_invites_enabled INTEGER NOT NULL DEFAULT 1 CHECK (vendor_invites_enabled IN (0,1)),
            status                 TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','cancelled')),
            created_by             TEXT    NOT NULL,
            created_at             TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_by             TEXT    NOT NULL DEFAULT '',
            updated_at             TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_vg_events_slug ON vg_events(mc_slug)",
        "CREATE TABLE IF NOT EXISTS vg_potluck_categories (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            mc_event_id INTEGER NOT NULL,
            name        TEXT    NOT NULL,
            sort_ord    INTEGER NOT NULL DEFAULT 0,
            UNIQUE (mc_event_id, name)
        )",
        "CREATE TABLE IF NOT EXISTS vg_contributions (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            mc_event_id       INTEGER NOT NULL,
            category_id       INTEGER NOT NULL,
            contributor_email TEXT    NOT NULL,
            contributor_name  TEXT    NOT NULL DEFAULT '',
            item_name         TEXT    NOT NULL,
            item_norm         TEXT    NOT NULL,
            qty               INTEGER NOT NULL DEFAULT 1 CHECK (qty BETWEEN 1 AND 100),
            notes             TEXT    NOT NULL DEFAULT '',
            status            TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','removed')),
            created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at        TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_by        TEXT    NOT NULL DEFAULT ''
        )",
        "CREATE INDEX IF NOT EXISTS idx_vg_contrib_event ON vg_contributions(mc_event_id, status)",
        "CREATE INDEX IF NOT EXISTS idx_vg_contrib_person ON vg_contributions(mc_event_id, contributor_email)",
        "CREATE TABLE IF NOT EXISTS vg_audit (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            at          TEXT    NOT NULL DEFAULT (datetime('now')),
            actor       TEXT    NOT NULL,
            actor_type  TEXT    NOT NULL CHECK (actor_type IN ('user','vendor_link','system')),
            action      TEXT    NOT NULL,
            mc_event_id INTEGER,
            target_type TEXT    NOT NULL DEFAULT '',
            target_id   INTEGER,
            detail_json TEXT    NOT NULL DEFAULT '{}'
        )",
        "CREATE INDEX IF NOT EXISTS idx_vg_audit_event ON vg_audit(mc_event_id, at)",
        "CREATE TABLE IF NOT EXISTS vg_rate_limits (
            bucket       TEXT    NOT NULL,
            subject      TEXT    NOT NULL,
            window_start INTEGER NOT NULL,
            count        INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket, subject, window_start)
        )",
    ];
}

// v2 attendees: identical to v1 except the guest ceiling (50). The app setting
// max_guests (default 10) remains the enforced limit.
function vg_schema_ddl_attendees(string $name = 'vg_attendees'): string {
    return "CREATE TABLE IF NOT EXISTS $name (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            mc_event_id   INTEGER NOT NULL,
            email         TEXT    NOT NULL,
            name          TEXT    NOT NULL DEFAULT '',
            response      TEXT    NOT NULL CHECK (response IN ('yes','no')),
            guest_count   INTEGER NOT NULL DEFAULT 0 CHECK (guest_count BETWEEN 0 AND 50),
            checked_in_at TEXT,
            checked_in_by TEXT    NOT NULL DEFAULT '',
            created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE (mc_event_id, email)
        )";
}

// v2 invitations: one per CONTACT per event; delivery tracked separately from the RSVP status.
function vg_schema_ddl_invitations(string $name = 'vg_invitations'): string {
    return "CREATE TABLE IF NOT EXISTS $name (
            id                    INTEGER PRIMARY KEY AUTOINCREMENT,
            mc_event_id           INTEGER NOT NULL,
            contact_id            INTEGER NOT NULL REFERENCES vendor_contacts(id),
            company_id            INTEGER NOT NULL REFERENCES vendor_companies(id),
            status                TEXT    NOT NULL CHECK (status IN ('suggested','approved','rejected','invited','accepted','declined','cancelled')),
            source                TEXT    NOT NULL CHECK (source IN ('suggestion','organizer')),
            suggested_by          TEXT    NOT NULL DEFAULT '',
            suggestion_note       TEXT    NOT NULL DEFAULT '',
            suggested_json        TEXT    NOT NULL DEFAULT '{}',
            decided_by            TEXT    NOT NULL DEFAULT '',
            decided_at            TEXT,
            decision_note         TEXT    NOT NULL DEFAULT '',
            delivery_status       TEXT    NOT NULL DEFAULT 'none' CHECK (delivery_status IN ('none','prepared','recorded_sent','queued','delivered','failed')),
            delivery_channel      TEXT    NOT NULL DEFAULT '' CHECK (delivery_channel IN ('','manual','provider')),
            delivery_updated_by   TEXT    NOT NULL DEFAULT '',
            delivery_updated_at   TEXT,
            token_hash            TEXT    UNIQUE,              -- sha256 hex of the raw link token; raw token is never stored
            token_issued_at       TEXT,
            token_expires_at      TEXT,                        -- UTC 'YYYY-MM-DD HH:MM:SS'
            token_revoked_at      TEXT,
            last_viewed_at        TEXT,
            responded_at          TEXT,
            response_guest_count  INTEGER NOT NULL DEFAULT 0 CHECK (response_guest_count BETWEEN 0 AND 50),
            response_bringing     TEXT    NOT NULL DEFAULT 'none' CHECK (response_bringing IN ('none','food','supplies','both')),
            response_category_id  INTEGER REFERENCES vg_potluck_categories(id),
            response_contribution TEXT    NOT NULL DEFAULT '',
            attendance            TEXT    NOT NULL DEFAULT 'unknown' CHECK (attendance IN ('unknown','attended','no_show')),
            attendance_by         TEXT    NOT NULL DEFAULT '',
            attendance_at         TEXT,
            created_at            TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at            TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE (mc_event_id, contact_id)
        )";
}
function vg_schema_ddl_invitation_indexes(): array {
    return ["CREATE INDEX IF NOT EXISTS idx_vg_inv_event ON vg_invitations(mc_event_id, status)",
            "CREATE INDEX IF NOT EXISTS idx_vg_inv_contact ON vg_invitations(contact_id)",
            "CREATE INDEX IF NOT EXISTS idx_vg_inv_company ON vg_invitations(mc_event_id, company_id)"];
}

// New in v2: shared vendor identity + email tables.
function vg_schema_ddl_v2_new(): array {
    return [
        // A business. Several contacts may belong to it. Never auto-merged.
        "CREATE TABLE IF NOT EXISTS vendor_companies (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            name           TEXT    NOT NULL,
            name_norm      TEXT    NOT NULL,
            domain_norm    TEXT    NOT NULL DEFAULT '',
            category       TEXT    NOT NULL DEFAULT 'other',
            status         TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived','merged')),
            merged_into_id INTEGER REFERENCES vendor_companies(id),
            created_by     TEXT    NOT NULL,
            created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at     TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_vendor_companies_name ON vendor_companies(name_norm)",
        "CREATE INDEX IF NOT EXISTS idx_vendor_companies_domain ON vendor_companies(domain_norm)",
        // Markets a company serves. Written only by an authorized organizer action (approve / add) or an admin.
        "CREATE TABLE IF NOT EXISTS vendor_company_markets (
            company_id INTEGER NOT NULL REFERENCES vendor_companies(id),
            mc_slug    TEXT    NOT NULL,
            source     TEXT    NOT NULL DEFAULT 'vendorsgiving' CHECK (source IN ('vendorsgiving','admin','migration')),
            created_by TEXT    NOT NULL DEFAULT '',
            created_at TEXT    NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (company_id, mc_slug)
        )",
        "CREATE INDEX IF NOT EXISTS idx_vendor_company_markets_slug ON vendor_company_markets(mc_slug)",
        // A person at a company. Email is NOT unique (shared inboxes); identity = company + email + name.
        "CREATE TABLE IF NOT EXISTS vendor_contacts (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id           INTEGER NOT NULL REFERENCES vendor_companies(id),
            first_name           TEXT    NOT NULL,
            last_name            TEXT    NOT NULL DEFAULT '',
            name_norm            TEXT    NOT NULL,
            email                TEXT    NOT NULL,
            email_norm           TEXT    NOT NULL,
            phone                TEXT    NOT NULL DEFAULT '',
            phone_norm           TEXT    NOT NULL DEFAULT '',
            status               TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived','merged')),
            merged_into_id       INTEGER REFERENCES vendor_contacts(id),
            details_confirmed_at TEXT,
            details_confirmed_invitation_id INTEGER,
            created_by           TEXT    NOT NULL,
            created_at           TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at           TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE (company_id, email_norm, name_norm)
        )",
        "CREATE INDEX IF NOT EXISTS idx_vendor_contacts_email ON vendor_contacts(email_norm)",
        "CREATE INDEX IF NOT EXISTS idx_vendor_contacts_phone ON vendor_contacts(phone_norm)",
        "CREATE INDEX IF NOT EXISTS idx_vendor_contacts_company ON vendor_contacts(company_id)",
        // Per-market association + privacy (approved §2): a market's organizers see the
        // stored phone only when phone_visible=1 for that market, i.e. the phone was
        // entered through that market or the vendor confirmed it through that market's invitation.
        "CREATE TABLE IF NOT EXISTS vendor_contact_markets (
            contact_id    INTEGER NOT NULL REFERENCES vendor_contacts(id),
            mc_slug       TEXT    NOT NULL,
            phone_visible INTEGER NOT NULL DEFAULT 0 CHECK (phone_visible IN (0,1)),
            source        TEXT    NOT NULL CHECK (source IN ('suggestion','organizer','vendor_rsvp','migration','admin')),
            created_by    TEXT    NOT NULL DEFAULT '',
            created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (contact_id, mc_slug)
        )",
        "CREATE INDEX IF NOT EXISTS idx_vendor_contact_markets_slug ON vendor_contact_markets(mc_slug)",
        // Verbatim notice / choice wording ever shown.
        "CREATE TABLE IF NOT EXISTS vendor_notice_versions (
            version      TEXT PRIMARY KEY,
            kind         TEXT NOT NULL CHECK (kind IN ('privacy_notice','directory_choice','marketing_choice','opt_out')),
            text         TEXT NOT NULL,
            text_sha256  TEXT NOT NULL,
            effective_at TEXT NOT NULL DEFAULT (datetime('now')),
            created_by   TEXT NOT NULL
        )",
        // Append-only consent / acknowledgement history.
        "CREATE TABLE IF NOT EXISTS vendor_consents (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id     INTEGER NOT NULL REFERENCES vendor_contacts(id),
            purpose        TEXT    NOT NULL CHECK (purpose IN ('event_coordination','business_contact','directory_consideration','marketing_email')),
            granted        INTEGER NOT NULL CHECK (granted IN (0,1)),
            notice_version TEXT    NOT NULL REFERENCES vendor_notice_versions(version),
            source         TEXT    NOT NULL CHECK (source IN ('vendor_rsvp','vendor_opt_out','admin')),
            invitation_id  INTEGER,
            recorded_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_vendor_consents_contact ON vendor_consents(contact_id, purpose, recorded_at)",
        // Vendor-only do-not-email list (by address). Separate from notification_prefs / unsubscribe.php.
        "CREATE TABLE IF NOT EXISTS vendor_email_suppressions (
            email_norm    TEXT PRIMARY KEY,
            reason        TEXT NOT NULL DEFAULT '',
            source        TEXT NOT NULL CHECK (source IN ('vendor_opt_out','organizer','admin','migration')),
            contact_id    INTEGER,
            invitation_id INTEGER,
            created_by    TEXT NOT NULL DEFAULT '',
            created_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )",
        // Identity history: corrections (old + new values), confirmations, permission changes.
        "CREATE TABLE IF NOT EXISTS vendor_audit (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            at            TEXT    NOT NULL DEFAULT (datetime('now')),
            actor         TEXT    NOT NULL,
            actor_type    TEXT    NOT NULL CHECK (actor_type IN ('user','vendor_link','system')),
            action        TEXT    NOT NULL,
            company_id    INTEGER,
            contact_id    INTEGER,
            invitation_id INTEGER,
            detail_json   TEXT    NOT NULL DEFAULT '{}'
        )",
        "CREATE INDEX IF NOT EXISTS idx_vendor_audit_contact ON vendor_audit(contact_id, at)",
        "CREATE INDEX IF NOT EXISTS idx_vendor_audit_invitation ON vendor_audit(invitation_id, action)",
        // Versioned invitation templates.
        "CREATE TABLE IF NOT EXISTS vg_email_templates (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            template_key TEXT    NOT NULL CHECK (template_key IN ('vendor_invitation')),
            version      INTEGER NOT NULL,
            subject      TEXT    NOT NULL,
            body_text    TEXT    NOT NULL,
            status       TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('draft','active','retired')),
            created_by   TEXT    NOT NULL,
            created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE (template_key, version)
        )",
    ];
}
// Created after vg_invitations exists (it references it).
function vg_schema_ddl_messages(): array {
    return [
        // The ONLY permitted transport is the development sink (CHECK). Real delivery
        // needs a further, separately approved schema change. Never touches notification_queue.
        "CREATE TABLE IF NOT EXISTS vg_email_messages (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            invitation_id    INTEGER NOT NULL REFERENCES vg_invitations(id),
            template_key     TEXT    NOT NULL,
            template_version INTEGER NOT NULL,
            to_email         TEXT    NOT NULL,
            subject          TEXT    NOT NULL,
            body_text        TEXT    NOT NULL,
            transport        TEXT    NOT NULL CHECK (transport IN ('dev_sink')),
            state            TEXT    NOT NULL CHECK (state IN ('captured')),
            created_by       TEXT    NOT NULL,
            created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_vg_email_messages_inv ON vg_email_messages(invitation_id)",
    ];
}

// ── seeds (INSERT OR IGNORE: never changes a row that already exists) ───────
// DRAFT wording — NOT approved for production (user review pending). The
// "DRAFT-" version prefix makes every consent row traceable to draft text.
const VG_NOTICE_SEED = [
    'DRAFT-2026-11-05-privacy' => ['privacy_notice',
        "How we'll use your information\nINNOVATE Real Estate will use the details you give us here to coordinate this event with you — for example, to confirm your RSVP, send event updates and plan the food table. We'll also keep them in our internal vendor contact records so our team can contact you about doing business together. Your phone number is used only to reach you about this event or that business, never for marketing texts. We won't publish your details or send you marketing email unless you choose the options below. You can ask us to stop emailing you at any time."],
    'DRAFT-2026-11-05-directory' => ['directory_choice',
        "Please consider {{company}} for INNOVATE's future Preferred Vendors directory. This isn't an application approval, and nothing about you or your company will be published. If we'd like to list you, we'll ask for your permission and the listing details first."],
    'DRAFT-2026-11-05-marketing' => ['marketing_choice',
        "Send me occasional INNOVATE Real Estate news and marketing emails. These are separate from messages about this event; you can unsubscribe at any time."],
    'DRAFT-2026-11-05-optout' => ['opt_out',
        "We'll stop sending vendor invitations and vendor emails to this address. This doesn't affect any other INNOVATE subscriptions or accounts."],
];
const VG_TEMPLATE_SEED_SUBJECT = "You're invited: {{event_name}} ({{market}})";
const VG_TEMPLATE_SEED_BODY = "Hi {{first_name}},\n\nINNOVATE Real Estate's {{market}} office would love to have {{company}} join us for {{event_name}}.\n\nWhen: {{date}}, {{time}}\nWhere: {{location}}\n\n{{details}}\n\nPlease let us know if you can make it{{rsvp_deadline}}:\n{{rsvp_link}}\n\nThank you for supporting our agents!\n{{organizer_name}}";

function vg_schema_seed(PDO $db): void {
    $n = $db->prepare("INSERT OR IGNORE INTO vendor_notice_versions (version, kind, text, text_sha256, created_by) VALUES (?,?,?,?, 'system:schema-v2')");
    foreach (VG_NOTICE_SEED as $ver => [$kind, $text]) $n->execute([$ver, $kind, $text, hash('sha256', $text)]);
    $db->prepare("INSERT OR IGNORE INTO vg_email_templates (template_key, version, subject, body_text, status, created_by) VALUES ('vendor_invitation', 1, ?, ?, 'active', 'system:schema-v2')")
       ->execute([VG_TEMPLATE_SEED_SUBJECT, VG_TEMPLATE_SEED_BODY]);
}

// ── introspection ───────────────────────────────────────────────────────────
function vg_schema_present(PDO $db): array {
    return $db->query("SELECT name FROM sqlite_master WHERE type='table' AND (name LIKE 'vg\\_%' ESCAPE '\\' OR name LIKE 'vendor\\_%' ESCAPE '\\')")->fetchAll(PDO::FETCH_COLUMN);
}
// Which v2 tables are missing on this DB (empty array = fully installed).
function vg_schema_missing(PDO $db): array {
    return array_values(array_diff(vg_schema_tables(), vg_schema_present($db)));
}
function vg_schema_version(PDO $db): int {
    if (!in_array('vg_schema_meta', vg_schema_present($db), true)) return 0;
    return (int)$db->query("SELECT version FROM vg_schema_meta WHERE id=1")->fetchColumn();
}
// Schema text of every object NOT owned by Vendorsgiving — must be identical before/after any apply.
function vg_schema_foreign_signature(PDO $db): string {
    $rows = $db->query("SELECT type||'|'||name||'|'||COALESCE(sql,'') FROM sqlite_master
                         WHERE tbl_name NOT LIKE 'vg\\_%' ESCAPE '\\' AND tbl_name NOT LIKE 'vendor\\_%' ESCAPE '\\' ORDER BY type, name")->fetchAll(PDO::FETCH_COLUMN);
    return hash('sha256', implode("\n", $rows));
}

// ── apply / migrate ─────────────────────────────────────────────────────────
// Returns a report array. Throws (after ROLLBACK) on any failure, leaving the
// database exactly as it was. $failAfter is a test hook ('copy','verify','drop')
// that throws at that point to prove the rollback path.
function vg_schema_apply(PDO $db, ?string $failAfter = null): array {
    $from = vg_schema_version($db);
    if ($from > VG_SCHEMA_VERSION) throw new RuntimeException("database schema version $from is newer than this code (" . VG_SCHEMA_VERSION . ')');
    if ($from === 0 && array_intersect(vg_schema_present($db), ['vg_vendors', 'vg_invitations'])) throw new RuntimeException('vg_* tables present without vg_schema_meta — refusing');
    $foreignBefore = vg_schema_foreign_signature($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $report = ['from_version' => $from, 'to_version' => VG_SCHEMA_VERSION];
        if ($from === 1) {
            $report += vg_schema_migrate_v1_to_v2($db, $failAfter);
        } else {
            foreach (vg_schema_ddl_common() as $sql) $db->exec($sql);
            $db->exec(vg_schema_ddl_attendees());
            foreach (vg_schema_ddl_v2_new() as $sql) $db->exec($sql);
            $db->exec(vg_schema_ddl_invitations());
            foreach (vg_schema_ddl_invitation_indexes() as $sql) $db->exec($sql);
            foreach (vg_schema_ddl_messages() as $sql) $db->exec($sql);
        }
        vg_schema_seed($db);
        $db->prepare("INSERT INTO vg_schema_meta (id, version) VALUES (1, ?) ON CONFLICT(id) DO UPDATE SET version=excluded.version, applied_at=datetime('now')")
           ->execute([VG_SCHEMA_VERSION]);
        if ($missing = vg_schema_missing($db)) throw new RuntimeException('missing after apply: ' . implode(',', $missing));
        if ($fk = $db->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC)) throw new RuntimeException('foreign_key_check failed: ' . json_encode(array_slice($fk, 0, 5)));
        if (vg_schema_foreign_signature($db) !== $foreignBefore) throw new RuntimeException('a non-Vendorsgiving table changed — refusing');
        $db->exec('COMMIT');
        return $report;
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

// v1 → v2. Runs inside vg_schema_apply's transaction. Destructive steps (drop
// vg_vendors / vg_suppressions, rebuild vg_invitations / vg_attendees) happen
// only after copied row counts and referential integrity are verified.
function vg_schema_migrate_v1_to_v2(PDO $db, ?string $failAfter): array {
    $count = fn(string $t) => (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    $before = ['vendors' => $count('vg_vendors'), 'invitations' => $count('vg_invitations'), 'suppressions' => $count('vg_suppressions'), 'attendees' => $count('vg_attendees')];
    $attHash = hash('sha256', json_encode($db->query("SELECT * FROM vg_attendees ORDER BY id")->fetchAll(PDO::FETCH_NUM)));
    foreach (vg_schema_ddl_v2_new() as $sql) $db->exec($sql);
    $report = ['needs_last_name' => [], 'cross_market_same_name_companies' => [], 'response_contact_names_preserved' => 0];

    // Companies: one per (market, normalized name). Same name in different markets → NOT merged (reported).
    $companyId = [];
    $groups = $db->query("SELECT mc_slug, company_norm, MIN(id) first_id FROM vg_vendors GROUP BY mc_slug, company_norm ORDER BY first_id")->fetchAll(PDO::FETCH_ASSOC);
    $first = $db->prepare("SELECT * FROM vg_vendors WHERE id=?");
    $insC = $db->prepare("INSERT INTO vendor_companies (name, name_norm, domain_norm, category, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)");
    $insCM = $db->prepare("INSERT INTO vendor_company_markets (company_id, mc_slug, source, created_by) VALUES (?,?, 'migration', 'system:schema-v2')");
    foreach ($groups as $g) {
        $first->execute([$g['first_id']]); $v = $first->fetch(PDO::FETCH_ASSOC); $first->closeCursor();
        $insC->execute([$v['company_name'], $g['company_norm'], vg_business_domain($v['email_norm']), $v['category'], $v['status'], $v['created_by'], $v['created_at'], $v['updated_at']]);
        $cid = (int)$db->lastInsertId();
        $companyId[$g['mc_slug'] . "\0" . $g['company_norm']] = $cid;
        $insCM->execute([$cid, $g['mc_slug']]);
    }
    foreach ($db->query("SELECT company_norm, GROUP_CONCAT(DISTINCT mc_slug) slugs FROM vg_vendors GROUP BY company_norm HAVING COUNT(DISTINCT mc_slug) > 1") as $r)
        $report['cross_market_same_name_companies'][] = $r['company_norm'] . ' (' . $r['slugs'] . ')';

    // Contacts: one per vg_vendors row (v1 already unique per market+email).
    $db->exec("CREATE TEMP TABLE vg_mig_map (vendor_id INTEGER PRIMARY KEY, contact_id INTEGER NOT NULL, company_id INTEGER NOT NULL)");
    $insP = $db->prepare("INSERT INTO vendor_contacts (company_id, first_name, last_name, name_norm, email, email_norm, phone, phone_norm, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $insPM = $db->prepare("INSERT INTO vendor_contact_markets (contact_id, mc_slug, phone_visible, source, created_by) VALUES (?,?,?, 'migration', 'system:schema-v2')");
    $insMap = $db->prepare("INSERT INTO vg_mig_map VALUES (?,?,?)");
    foreach ($db->query("SELECT * FROM vg_vendors ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $parts = preg_split('/\s+/', trim($v['contact_name']), 2);
        $fn = $parts[0] ?? ''; $ln = $parts[1] ?? '';
        if ($ln === '') $report['needs_last_name'][] = (int)$v['id'];
        $cid = $companyId[$v['mc_slug'] . "\0" . $v['company_norm']];
        $insP->execute([$cid, $fn, $ln, vg_name_norm($fn, $ln), $v['email'], $v['email_norm'], $v['phone'], $v['phone_norm'], $v['status'], $v['created_by'], $v['created_at'], $v['updated_at']]);
        $pid = (int)$db->lastInsertId();
        $insPM->execute([$pid, $v['mc_slug'], $v['phone'] !== '' ? 1 : 0]);
        $insMap->execute([(int)$v['id'], $pid, $cid]);
    }

    // Invitations: rebuild pointing at contact/company. Status/token/response copied unchanged.
    $db->exec(vg_schema_ddl_invitations('vg_invitations_v2'));
    $db->exec("INSERT INTO vg_invitations_v2 (id, mc_event_id, contact_id, company_id, status, source, suggested_by, suggestion_note, suggested_json, decided_by, decided_at, decision_note,
                   delivery_status, delivery_channel, delivery_updated_by, delivery_updated_at, token_hash, token_issued_at, token_expires_at, token_revoked_at, last_viewed_at,
                   responded_at, response_guest_count, response_bringing, response_category_id, response_contribution, created_at, updated_at)
               SELECT i.id, i.mc_event_id, m.contact_id, m.company_id, i.status, i.source, i.suggested_by, i.suggestion_note, '{}', i.decided_by, i.decided_at, i.decision_note,
                   CASE WHEN i.status IN ('invited','accepted','declined') THEN 'recorded_sent' ELSE 'none' END,
                   CASE WHEN i.status IN ('invited','accepted','declined') THEN 'manual' ELSE '' END,
                   CASE WHEN i.status IN ('invited','accepted','declined') THEN 'system:schema-v2' ELSE '' END,
                   CASE WHEN i.status IN ('invited','accepted','declined') THEN i.updated_at END,
                   i.token_hash, i.token_issued_at, i.token_expires_at, i.token_revoked_at, i.last_viewed_at,
                   i.responded_at, i.response_guest_count, i.response_bringing, NULL, i.response_contribution, i.created_at, i.updated_at
               FROM vg_invitations i JOIN vg_mig_map m ON m.vendor_id = i.vendor_id");
    $keep = $db->prepare("INSERT INTO vendor_audit (actor, actor_type, action, company_id, contact_id, invitation_id, detail_json) VALUES ('system:schema-v2','system','migration.response_contact_name',?,?,?,?)");
    foreach ($db->query("SELECT i.id, i.response_contact_name, m.contact_id, m.company_id FROM vg_invitations i JOIN vg_mig_map m ON m.vendor_id=i.vendor_id WHERE i.response_contact_name<>''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $keep->execute([$r['company_id'], $r['contact_id'], $r['id'], json_encode(['response_contact_name' => $r['response_contact_name']])]);
        $report['response_contact_names_preserved']++;
    }
    // Attendees: rebuild only to raise the CHECK ceiling; every column copied.
    $db->exec(vg_schema_ddl_attendees('vg_attendees_v2'));
    $db->exec("INSERT INTO vg_attendees_v2 (id, mc_event_id, email, name, response, guest_count, checked_in_at, checked_in_by, created_at, updated_at)
               SELECT id, mc_event_id, email, name, response, guest_count, checked_in_at, checked_in_by, created_at, updated_at FROM vg_attendees");
    // Suppressions → vendor-only list.
    $db->exec("INSERT INTO vendor_email_suppressions (email_norm, reason, source, created_by, created_at)
               SELECT email_norm, reason, CASE WHEN source IN ('vendor_opt_out','organizer','admin') THEN source ELSE 'migration' END, created_by, created_at FROM vg_suppressions");
    if ($failAfter === 'copy') throw new RuntimeException('test: forced failure after copy');

    // Verify BEFORE anything is removed.
    $after = ['vendors' => $count('vendor_contacts'), 'invitations' => $count('vg_invitations_v2'), 'suppressions' => $count('vendor_email_suppressions'), 'attendees' => $count('vg_attendees_v2')];
    if ($after !== $before) throw new RuntimeException('row count mismatch: before ' . json_encode($before) . ' after ' . json_encode($after));
    if ($count('vg_mig_map') !== $before['vendors']) throw new RuntimeException('vendor→contact map incomplete');
    $orphans = (int)$db->query("SELECT COUNT(*) FROM vg_invitations_v2 i LEFT JOIN vendor_contacts c ON c.id=i.contact_id LEFT JOIN vendor_companies k ON k.id=i.company_id WHERE c.id IS NULL OR k.id IS NULL OR c.company_id<>i.company_id")->fetchColumn();
    if ($orphans) throw new RuntimeException("$orphans invitation(s) without a valid contact/company");
    $tok = (int)$db->query("SELECT COUNT(*) FROM vg_invitations i JOIN vg_invitations_v2 n ON n.id=i.id WHERE COALESCE(i.token_hash,'')<>COALESCE(n.token_hash,'') OR i.status<>n.status OR i.mc_event_id<>n.mc_event_id OR i.response_guest_count<>n.response_guest_count")->fetchColumn();
    if ($tok) throw new RuntimeException("$tok invitation(s) changed status/token/event/guests during copy");
    if (hash('sha256', json_encode($db->query("SELECT * FROM vg_attendees_v2 ORDER BY id")->fetchAll(PDO::FETCH_NUM))) !== $attHash) throw new RuntimeException('vg_attendees content changed during copy');
    if ($fk = $db->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC)) throw new RuntimeException('foreign_key_check failed: ' . json_encode(array_slice($fk, 0, 5)));
    if ($failAfter === 'verify') throw new RuntimeException('test: forced failure after verify');

    // Destructive steps — only after verification, still inside the transaction.
    unset($first, $insC, $insCM, $insP, $insPM, $insMap, $keep);   // release statement handles (open cursors lock tables)
    $db->exec("DROP TABLE vg_invitations");
    $db->exec("ALTER TABLE vg_invitations_v2 RENAME TO vg_invitations");
    foreach (vg_schema_ddl_invitation_indexes() as $sql) $db->exec($sql);
    $db->exec("DROP TABLE vg_attendees");
    $db->exec("ALTER TABLE vg_attendees_v2 RENAME TO vg_attendees");
    $db->exec("DROP TABLE vg_vendors");
    $db->exec("DROP TABLE vg_suppressions");
    $db->exec("DROP TABLE vg_mig_map");
    foreach (vg_schema_ddl_messages() as $sql) $db->exec($sql);
    if ($failAfter === 'drop') throw new RuntimeException('test: forced failure after drop');
    return ['migrated' => $before] + $report;
}

// Removes ONLY vg_* and vendor_* tables. Caller (the tool) enforces the guards:
// never on production in this version, backup first, refuses data without explicit confirmation.
function vg_schema_row_counts(PDO $db): array {
    $out = [];
    foreach (vg_schema_present($db) as $t) $out[$t] = (int)$db->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
    ksort($out);
    return $out;
}
function vg_schema_uninstall(PDO $db): array {
    $foreignBefore = vg_schema_foreign_signature($db);
    $tables = vg_schema_present($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec("DROP TABLE IF EXISTS vg_email_messages");   // references vg_invitations
        foreach ($tables as $t) $db->exec("DROP TABLE IF EXISTS \"$t\"");
        if (vg_schema_present($db)) throw new RuntimeException('tables remain after uninstall');
        if (vg_schema_foreign_signature($db) !== $foreignBefore) throw new RuntimeException('a non-Vendorsgiving table changed — refusing');
        $db->exec('COMMIT');
    } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    sort($tables);
    return $tables;
}
