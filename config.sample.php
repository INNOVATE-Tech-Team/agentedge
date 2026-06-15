<?php
// AgentEdge configuration.
// On the server: copy this file to `config.php` and fill in the read-only
// database credentials you create in cPanel. config.php is git-ignored and
// never committed.
return [
    // Same-server MySQL — 'localhost' is correct on cPanel.
    'db_host'     => 'localhost',
    'db_name'     => 'innovate_agents',          // the Perfex database (read-only)
    'db_user'     => 'innovate_agentedge_ro',     // the READ-ONLY user you create in cPanel
    'db_pass'     => 'PUT-READ-ONLY-PASSWORD-HERE',

    // Only agents who are active staff can sign in.
    'require_active' => true,

    // Optional: restrict login to staff whose role is an agent role, once we
    // confirm the role ids. Leave empty to allow any active staff for now.
    'agent_role_ids' => [],

    // SAMPLE-DATA MODE. true = no database needed, any login works, the
    // dashboard shows realistic sample numbers (for previewing on bold360.vip).
    // Set false on the production server (innovateonline.com) to use real data.
    'demo' => false,

    // Perfex login bridge — when set, logins are verified through this endpoint
    // (the agent's existing Perfex password) instead of the local DB or demo.
    // This is how real logins work on bold360.vip (which can't reach the Perfex
    // DB directly). Leave blank to fall back to demo/local auth.
    'auth_bridge_url'   => '',   // e.g. https://innovateonline.com/agentedge-auth/verify.php
    'auth_bridge_token' => '',   // must match $BRIDGE_TOKEN in verify.php

    // Show sample dashboard tiles/cap instead of querying a local DB. Keep true
    // on bold360.vip (no local Perfex DB) until the Darwin / tx feed is wired.
    'sample_dashboard' => false,

    // Where the login's "Forgot password?" link sends agents. Resets happen in
    // the Perfex back office (the password's source of truth). Blank = default
    // to the back-office login at https://agents.innovateonline.com/admin.
    'reset_url' => '',

    // bold360.vip CRM — source of the agent roster + profile editing.
    'crm_base'  => 'https://bold360.vip/api',
    // Shared token that unlocks contact details (email/phone) and lets agents
    // save profile edits. Must match AGENTEDGE_TOKEN in the CRM's environment.
    // Leave blank to show the roster without contact info and disable editing.
    'crm_token' => '',
];
