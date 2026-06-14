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

    // bold360.vip CRM — source of the agent roster + profile editing.
    'crm_base'  => 'https://bold360.vip/api',
    // Shared token that unlocks contact details (email/phone) and lets agents
    // save profile edits. Must match AGENTEDGE_TOKEN in the CRM's environment.
    // Leave blank to show the roster without contact info and disable editing.
    'crm_token' => '',
];
