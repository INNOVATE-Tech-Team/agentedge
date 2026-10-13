<?php
// Which uploaded photo an agent uses as their headshot, and its square crop.
//
// Agents upload up to 5 photos to agent_intake_files, kept uncropped so
// staff can use the originals for marketing (more torso, wider framing).
// One of them is chosen as the headshot and framed into a square in the
// browser (assets/headshot-crop.js); that crop is stored as its own file
// in data/headshots/crops/ and is what the website shows
// (api/agent_profile_export.php). The original is never modified.
//
// agent_headshot keeps one row per agent. updated_at changes whenever the
// choice or crop changes -- including when the chosen photo is deleted, in
// which case source_key/crop_key go NULL but the row stays -- because the
// website's nightly sync only re-downloads a photo when this timestamp (or
// a newer upload) moves forward.
if (defined('AGENTEDGE_HEADSHOT_SELECT_LOADED')) return;
define('AGENTEDGE_HEADSHOT_SELECT_LOADED', true);

function headshot_select_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_headshot (
        agent_email TEXT PRIMARY KEY,
        source_key  TEXT,
        crop_key    TEXT,
        crop_rect   TEXT,
        updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $done = true;
}

function headshot_data_dir(): string {
    $cfgDir = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    return $cfgDir ?: (__DIR__ . '/../data');
}

function headshot_crop_dir(): string {
    return headshot_data_dir() . '/headshots/crops';
}

// The agent's current choice, or null when they've never picked one (or
// the picked photo was deleted).
function headshot_select_get(PDO $pdo, string $email): ?array {
    headshot_select_ensure_table($pdo);
    $st = $pdo->prepare("SELECT source_key, crop_key, crop_rect, updated_at FROM agent_headshot WHERE agent_email=?");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['crop_key']) return null;
    $row['crop_rect'] = $row['crop_rect'] ? json_decode($row['crop_rect'], true) : null;
    return $row;
}

function headshot_select_save(PDO $pdo, string $email, string $sourceKey, string $cropKey, ?array $rect): void {
    headshot_select_ensure_table($pdo);
    $old = $pdo->prepare("SELECT crop_key FROM agent_headshot WHERE agent_email=?");
    $old->execute([$email]);
    $oldCrop = $old->fetchColumn();
    $pdo->prepare(
        "INSERT INTO agent_headshot (agent_email, source_key, crop_key, crop_rect, updated_at)
         VALUES (?, ?, ?, ?, datetime('now'))
         ON CONFLICT(agent_email) DO UPDATE SET
           source_key = excluded.source_key, crop_key = excluded.crop_key,
           crop_rect = excluded.crop_rect, updated_at = excluded.updated_at"
    )->execute([$email, $sourceKey, $cropKey, $rect ? json_encode($rect) : null]);
    if ($oldCrop && $oldCrop !== $cropKey) @unlink(headshot_crop_dir() . '/' . basename($oldCrop));
}

// Called when a photo is deleted: if it was the chosen headshot, drop the
// crop and bump updated_at so the website falls back to the latest photo.
function headshot_select_forget_source(PDO $pdo, string $email, string $sourceKey): void {
    headshot_select_ensure_table($pdo);
    $st = $pdo->prepare("SELECT crop_key FROM agent_headshot WHERE agent_email=? AND source_key=?");
    $st->execute([$email, $sourceKey]);
    $crop = $st->fetchColumn();
    if ($crop === false) return;
    if ($crop) @unlink(headshot_crop_dir() . '/' . basename($crop));
    $pdo->prepare(
        "UPDATE agent_headshot SET source_key=NULL, crop_key=NULL, crop_rect=NULL, updated_at=datetime('now')
         WHERE agent_email=?"
    )->execute([$email]);
}
