<?php
// Admin Work OS — shared category/status vocabulary. Used by admin_work_os.php,
// admin_work_item.php, and api/admin_work_item_action.php so the allow-lists
// and display labels live in exactly one place.

const ADMIN_WORK_CATEGORIES = ['people', 'product', 'operations', 'admin'];
const ADMIN_WORK_STATUSES   = ['inbox', 'next', 'waiting', 'done'];

// 'admin' displays as "Administrative" per approved product copy.
const ADMIN_WORK_CATEGORY_LABELS = [
    'people'     => 'People',
    'product'    => 'Product',
    'operations' => 'Operations',
    'admin'      => 'Administrative',
];
const ADMIN_WORK_STATUS_LABELS = [
    'inbox'   => 'Inbox',
    'next'    => 'Next',
    'waiting' => 'Waiting',
    'done'    => 'Done',
];

function awos_category_label(string $category): string {
    return ADMIN_WORK_CATEGORY_LABELS[$category] ?? ucfirst($category);
}
function awos_status_label(string $status): string {
    return ADMIN_WORK_STATUS_LABELS[$status] ?? ucfirst($status);
}

// NULL and '' are the same "no due date" state -- callers diffing an
// incoming value against a stored one should normalize through this first
// so a no-op resave never produces a false due_date_changed event.
function awos_normalize_date(?string $d): ?string {
    $d = trim((string)$d);
    return $d === '' ? null : $d;
}

function awos_valid_date(?string $d): bool {
    if ($d === null || $d === '') return true; // no due date is always valid
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}
