<?php
// Shared Vault access helper. A "restricted" department (vault_depts.restricted=1)
// is a real confidentiality boundary, not just an org-chart label: admins do NOT
// automatically see/upload/delete inside one the way they do every other
// department — only agents explicitly granted the department via
// vault_user_depts can. Everywhere a Vault file checks "$admin bypasses this"
// must also check this, or a restricted department silently isn't restricted.
function vault_dept_restricted(string $slug): bool {
    if ($slug === '') return false;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (local_db()->query("SELECT slug FROM vault_depts WHERE restricted=1")->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $cache[$s] = true;
        }
    }
    return isset($cache[$slug]);
}

// Does this agent have an explicit per-user grant for this department? (The
// normal vault_user_depts membership check, factored out since restricted
// departments need it evaluated even for admins.)
function vault_user_has_dept(string $email, string $slug): bool {
    if ($email === '' || $slug === '') return false;
    $s = local_db()->prepare("SELECT 1 FROM vault_user_depts WHERE email=? AND dept_slug=?");
    $s->execute([$email, $slug]);
    return (bool)$s->fetchColumn();
}

// Per-folder restriction, independent of (and layered on top of) its
// department's own restricted flag. Added for "Payroll Records (W-2/1099)",
// which has to sit inside the otherwise-open Human Resources department but
// still stay locked to a couple of people — the department-level restriction
// above can't express that on its own, since every folder in an unrestricted
// department is visible to any admin. vault_folders.restricted_emails is a
// comma-separated list; empty means "no per-folder override" and every
// existing folder falls through to the normal dept-based check unchanged.
// Every place that already checks vault_dept_restricted()/vault_user_has_dept()
// for a folder must also check this, or a restricted folder silently isn't
// restricted.
function vault_folder_restricted_emails(array $folder): array {
    $raw = trim((string) ($folder['restricted_emails'] ?? ''));
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', strtolower($raw)))));
}

function vault_user_passes_folder_restriction(array $folder, string $email): bool {
    $list = vault_folder_restricted_emails($folder);
    if (!$list) return true;
    return in_array(strtolower(trim($email)), $list, true);
}
