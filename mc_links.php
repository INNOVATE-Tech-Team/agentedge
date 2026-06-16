<?php
// ─────────────────────────────────────────────────────────────────────────────
// Market-center resource links shown in the "My Resources" sidebar section.
//
// HOW TO FIND YOUR SLUGS
//   Log in as an agent from a market center → open DevTools (F12) → Network
//   tab → reload → click api/profile.php → look for "marketCenter" in the JSON.
//   Slugify it: lowercase, spaces → hyphens, drop special chars.
//   Example: "Myrtle Beach" → "myrtle-beach"
//
// ADDING LINKS
//   Each entry: ['label' => 'Display name', 'url' => 'https://...']
//   Entries with url = '#' are hidden from agents until you add a real URL.
// ─────────────────────────────────────────────────────────────────────────────
return [

    // ── South Carolina ────────────────────────────────────────────────────────
    'myrtle-beach' => [
        ['label' => 'MLS (CCAR)',           'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'SC License Renewal',   'url' => '#'],
        ['label' => 'SC State Resources',   'url' => '#'],
    ],
    'columbia' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'SC License Renewal',   'url' => '#'],
    ],
    'charleston' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'SC License Renewal',   'url' => '#'],
    ],
    'greenville' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'SC License Renewal',   'url' => '#'],
    ],

    // ── North Carolina ────────────────────────────────────────────────────────
    'north-carolina' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'NC License Renewal',   'url' => '#'],
        ['label' => 'NC State Resources',   'url' => '#'],
    ],

    // ── Virginia ──────────────────────────────────────────────────────────────
    'virginia' => [
        ['label' => 'MLS (Bright MLS)',     'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'VA License Renewal',   'url' => '#'],
        ['label' => 'VA State Resources',   'url' => '#'],
    ],

    // ── Maryland ──────────────────────────────────────────────────────────────
    'maryland' => [
        ['label' => 'MLS (Bright MLS)',     'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'MD License Renewal',   'url' => '#'],
        ['label' => 'MD State Resources',   'url' => '#'],
    ],

    // ── Delaware ──────────────────────────────────────────────────────────────
    'delaware' => [
        ['label' => 'MLS (Bright MLS)',     'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'DE License Renewal',   'url' => '#'],
        ['label' => 'DE State Resources',   'url' => '#'],
    ],

    // ── New Jersey ────────────────────────────────────────────────────────────
    'new-jersey' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'NJ License Renewal',   'url' => '#'],
        ['label' => 'NJ State Resources',   'url' => '#'],
    ],

    // ── Pennsylvania ──────────────────────────────────────────────────────────
    'pennsylvania' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'PA License Renewal',   'url' => '#'],
        ['label' => 'PA State Resources',   'url' => '#'],
    ],

    // ── Ohio ──────────────────────────────────────────────────────────────────
    'ohio' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'OH License Renewal',   'url' => '#'],
        ['label' => 'OH State Resources',   'url' => '#'],
    ],

    // ── Georgia ───────────────────────────────────────────────────────────────
    'georgia' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'GA License Renewal',   'url' => '#'],
        ['label' => 'GA State Resources',   'url' => '#'],
    ],

    // ── Tennessee ─────────────────────────────────────────────────────────────
    'tennessee' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'TN License Renewal',   'url' => '#'],
        ['label' => 'TN State Resources',   'url' => '#'],
    ],

    // ── Florida ───────────────────────────────────────────────────────────────
    'florida' => [
        ['label' => 'MLS',                  'url' => '#'],
        ['label' => 'ShowingTime',          'url' => '#'],
        ['label' => 'FL License Renewal',   'url' => '#'],
        ['label' => 'FL State Resources',   'url' => '#'],
    ],

];
