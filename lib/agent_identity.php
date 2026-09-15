<?php
// Cross-system agent identity matching for the Coach Dashboard production
// layer. Resolves, per innovate_roster row, three independent IDs:
//   - canonical_agent_id     (Coastline's innovate.canonical_agents.id, UUID)
//   - darwin_agent_person_id (Darwin/AccountTECH's agent_person_id)
//   - perfex_staff_id        (Perfex's tblstaff.staffid)
//
// Matching is exact-email only, never agent name. Each system is matched
// independently: an agent can be found on Darwin and not on Perfex, etc.
// See backfill_agent_identity.php for the script that drives this.

// Normalizes an email for matching: lowercase + trim. Returns '' for empty/null.
function ai_norm_email(?string $email): string {
    return strtolower(trim((string)$email));
}

// Builds a normalized-email -> [candidate values] index from a list of rows.
// Deliberately keeps every candidate (not just the first) so a collision
// (2+ distinct records sharing a normalized email) is visible at lookup
// time as an ambiguous match, rather than silently dropped or overwritten.
function ai_build_index(array $rows, string $emailField, string $valueField): array {
    $index = [];
    foreach ($rows as $row) {
        $email = ai_norm_email($row[$emailField] ?? null);
        if ($email === '') continue;
        $value = $row[$valueField] ?? null;
        if ($value === null || $value === '') continue;
        $index[$email][] = $value;
    }
    return $index;
}

// Resolves one system's match for a given primary/alt email pair against
// its index. Returns:
//   ['status' => 'matched',    'value' => <id>, 'source' => 'primary_email'|'alt_email']
//   ['status' => 'unmatched',  'value' => null,  'reason' => 'no_candidate']
//   ['status' => 'ambiguous',  'value' => null,  'candidates' => [...], 'source' => ...]
// Primary email is always tried first; alt_email is only consulted when
// primary produces zero candidates (never blended, never preferred over
// primary, and never chained after an ambiguous primary result).
function ai_resolve_one(array $index, string $primaryEmail, string $altEmail): array {
    $primary = ai_norm_email($primaryEmail);
    if ($primary !== '' && isset($index[$primary])) {
        $candidates = $index[$primary];
        if (count($candidates) === 1) {
            return ['status' => 'matched', 'value' => $candidates[0], 'source' => 'primary_email'];
        }
        return ['status' => 'ambiguous', 'value' => null, 'candidates' => $candidates, 'source' => 'primary_email'];
    }

    $alt = ai_norm_email($altEmail);
    if ($alt !== '' && isset($index[$alt])) {
        $candidates = $index[$alt];
        if (count($candidates) === 1) {
            return ['status' => 'matched', 'value' => $candidates[0], 'source' => 'alt_email'];
        }
        return ['status' => 'ambiguous', 'value' => null, 'candidates' => $candidates, 'source' => 'alt_email'];
    }

    return ['status' => 'unmatched', 'value' => null, 'reason' => 'no_candidate'];
}

// Compares a freshly-resolved value against whatever is already stored in
// the roster column and decides the outcome for that one ID:
//   - column empty, fresh match found      -> ['action' => 'set',        'value' => fresh]
//   - column empty, no fresh match         -> ['action' => 'none']
//   - column has value, fresh agrees       -> ['action' => 'confirm']
//   - column has value, fresh disagrees    -> ['action' => 'conflict',   'existing' => ..., 'fresh' => ...]
//   - column has value, fresh not found    -> ['action' => 'keep']   (can't prove existing wrong)
//   - fresh result is ambiguous            -> ['action' => 'ambiguous']
function ai_reconcile(?string $existingValue, array $resolved): array {
    $existing = ($existingValue !== null && $existingValue !== '') ? (string)$existingValue : null;

    if ($resolved['status'] === 'ambiguous') {
        return ['action' => 'ambiguous'];
    }

    if ($resolved['status'] === 'unmatched') {
        return $existing !== null ? ['action' => 'keep'] : ['action' => 'none'];
    }

    // matched
    $fresh = (string)$resolved['value'];
    if ($existing === null) {
        return ['action' => 'set', 'value' => $resolved['value']];
    }
    if ($existing === $fresh) {
        return ['action' => 'confirm'];
    }
    return ['action' => 'conflict', 'existing' => $existing, 'fresh' => $resolved['value']];
}

// Rolls up the three per-system reconciliation outcomes into one
// id_match_status value for the roster row.
function ai_rollup_status(array $outcomes): string {
    $actions = array_column($outcomes, 'action');
    if (in_array('conflict', $actions, true) || in_array('ambiguous', $actions, true)) {
        return 'conflict';
    }
    $resolvedCount = 0;
    $missingCount  = 0;
    foreach ($outcomes as $o) {
        if (in_array($o['action'], ['set', 'confirm', 'keep'], true)) $resolvedCount++;
        else $missingCount++; // 'none'
    }
    if ($resolvedCount === count($outcomes)) return 'matched';
    if ($resolvedCount > 0) return 'partial';
    return 'unmatched';
}

// Full per-row resolution: given a roster row (id, email, alt_email) and
// the three prebuilt indexes, returns the per-system resolved+reconciled
// outcomes plus the rolled-up status, ready to either print (dry-run) or
// apply (write pass).
function ai_match_roster_row(array $rosterRow, array $existing, array $indexes): array {
    $primary = $rosterRow['email'] ?? '';
    $alt     = $rosterRow['alt_email'] ?? '';

    $systems = [
        'canonical_agent_id'     => $indexes['coastline'],
        'darwin_agent_person_id' => $indexes['darwin'],
        'perfex_staff_id'        => $indexes['perfex'],
    ];

    $result = [];
    $outcomes = [];
    foreach ($systems as $column => $index) {
        $resolved = ai_resolve_one($index, $primary, $alt);
        $outcome  = ai_reconcile($existing[$column] ?? null, $resolved);
        $result[$column] = ['resolved' => $resolved, 'outcome' => $outcome];
        $outcomes[] = $outcome;
    }

    return [
        'columns' => $result,
        'status'  => ai_rollup_status($outcomes),
    ];
}
