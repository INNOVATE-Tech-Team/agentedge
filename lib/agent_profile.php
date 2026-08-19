<?php
// Single-agent data access for agent_profile.php. Deliberately a separate
// parameterized query from backoffice_agents.php's list query (not a shared
// refactor) so this new page can't regress that working list/detail tool.
if (defined('AGENTEDGE_AGENT_PROFILE_LIB_LOADED')) return;
define('AGENTEDGE_AGENT_PROFILE_LIB_LOADED', true);

// Fields checked for the completion-reminder email/popup and the
// resend-only-missing form. Deliberately narrower than the required-field
// list api/intake_public.php enforces at onboarding submission — fields
// collected only at onboarding (e.g. referring_agent) are excluded here so
// tenured agents who joined before a field existed aren't nagged for it.
const REQUIRED_INTAKE_FIELDS = [
    'full_name'       => 'Full Name',
    'phone'           => 'Phone Number',
    'license_number'  => 'License Number',
    'nar_number'      => 'NAR Number',
    'mls_board'       => 'MLS Board',
    'office_location' => 'Office Location',
    'birthday'        => 'Birthday',
    'address_line1'   => 'Address',
    'city'            => 'City',
    'state'           => 'State',
    'zip'             => 'Zip Code',
    'emergency_name'  => 'Emergency Contact Name',
    'emergency_phone' => 'Emergency Contact Phone',
    'bio'             => 'Bio',
];

// Bonus fields shown alongside the required ones on the completion form, but
// never enforced — the agent can leave these blank and still submit. Kept
// separate from REQUIRED_INTAKE_FIELDS because that list is all-or-nothing
// (api/complete_profile_action.php rejects the whole save if any of those
// come back blank); languages has no such gate.
const OPTIONAL_INTAKE_FIELDS = [
    'languages' => 'Languages Spoken',
];

// Returns [['key'=>'phone','label'=>'Phone Number'], ...] for every required
// field that's still blank on this agent's intake row — empty array means
// nothing missing (an agent with no agent_intake row at all is missing everything).
function get_missing_required_fields(string $email): array {
    $row = agent_intake_row($email);
    $missing = [];
    foreach (REQUIRED_INTAKE_FIELDS as $key => $label) {
        if (trim($row[$key] ?? '') === '') $missing[] = ['key' => $key, 'label' => $label];
    }
    return $missing;
}

// Same shape as get_missing_required_fields(), but for the non-enforced
// bonus fields — only returns ones still blank, so an agent who already has
// it on file isn't asked again.
function get_blank_optional_fields(string $email): array {
    $row = agent_intake_row($email);
    $blank = [];
    foreach (OPTIONAL_INTAKE_FIELDS as $key => $label) {
        if (trim($row[$key] ?? '') === '') $blank[] = ['key' => $key, 'label' => $label];
    }
    return $blank;
}

function agent_intake_row(string $email): array {
    $st = local_db()->prepare("SELECT * FROM agent_intake WHERE email = ?");
    $st->execute([strtolower(trim($email))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function load_agent_profile(string $email): ?array {
    $st = local_db()->prepare(
        "SELECT i.email, i.full_name, i.phone, i.license_number, i.license_state,
                i.license_exp, i.nar_number, i.mls_board, i.mls_id, i.office_location,
                i.birthday, i.mailing_address, i.spouse_name, i.emergency_name, i.emergency_phone,
                i.bio, i.tshirt_size, i.is_military, i.first_responder, i.is_teacher,
                i.phone_last4, i.referring_agent, i.languages,
                i.personal_email, i.commissions_email,
                i.address_line1, i.address_line2, i.city, i.state, i.zip, i.country,
                i.drivers_license, i.gender,
                i.website, i.additional_websites, i.facebook, i.linkedin, i.skype, i.instagram,
                i.specialty, i.career_start, i.prior_occupation, i.prior_affiliation,
                i.full_time, i.show_on_internet,
                i.corporation_start, i.corporation_end,
                i.personal_tax_id_enc, i.corporate_tax_id_enc,
                i.submitted, i.submitted_at, i.updated_at,
                e.hire_date, e.license_renewal, e.alt_email,
                ar.role,
                aa.tax_1099_type, aa.gets_1099, aa.terminated_date, aa.agent_team, aa.coached_by, aa.managed_by,
                aa.recruit_source_email
         FROM agent_intake i
         LEFT JOIN agent_extra e ON e.email = i.email
         LEFT JOIN agent_roles ar ON ar.email = i.email
         LEFT JOIN agent_admin aa ON LOWER(aa.email) = i.email
         WHERE i.email = ?"
    );
    $st->execute([strtolower(trim($email))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function load_agent_additional_licenses(string $email): array {
    $st = local_db()->prepare(
        "SELECT license_number, license_state, license_exp FROM agent_intake_licenses WHERE agent_email=? ORDER BY id"
    );
    $st->execute([$email]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_agent_headshot_count(string $email): int {
    $st = local_db()->prepare("SELECT COUNT(*) FROM agent_intake_files WHERE agent_email=?");
    $st->execute([$email]);
    return (int)$st->fetchColumn();
}

// Most recently uploaded headshot, used as the displayed profile photo.
function load_agent_latest_headshot(string $email): ?string {
    $st = local_db()->prepare(
        "SELECT file_key FROM agent_intake_files WHERE agent_email=? ORDER BY uploaded_at DESC LIMIT 1"
    );
    $st->execute([$email]);
    $key = $st->fetchColumn();
    return $key ?: null;
}

// Full headshot list (file_key + orig_name), used to render inline thumbnails
// instead of linking out to the intake form.
function load_agent_headshots(string $email): array {
    $st = local_db()->prepare(
        "SELECT file_key, orig_name FROM agent_intake_files WHERE agent_email=? ORDER BY uploaded_at"
    );
    $st->execute([$email]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_agent_documents(string $email): array {
    $st = local_db()->prepare(
        "SELECT id, name, source, category, mime_type, size_bytes, storage_key, uploaded_by, created_at
         FROM agent_documents WHERE email=? ORDER BY created_at DESC"
    );
    $st->execute([$email]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Most recent onboarding/offboarding queue row for this agent, if any —
// backs the Task Checklist tab's lightweight status line + deep link.
function load_agent_queue_status(string $email): array {
    $ob = local_db()->prepare(
        "SELECT id, status, added_at FROM onboard_queue WHERE LOWER(agent_email)=? ORDER BY added_at DESC LIMIT 1"
    );
    $ob->execute([$email]);
    $offb = local_db()->prepare(
        "SELECT id, status, added_at FROM offboard_queue WHERE LOWER(agent_email)=? ORDER BY added_at DESC LIMIT 1"
    );
    $offb->execute([$email]);
    return [
        'onboarding'  => $ob->fetch(PDO::FETCH_ASSOC) ?: null,
        'offboarding' => $offb->fetch(PDO::FETCH_ASSOC) ?: null,
    ];
}
