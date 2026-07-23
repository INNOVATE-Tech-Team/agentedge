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

    // Default password given to new agents pushed over from Advantage CRM
    // (api/onboard_push.php) so they have a working login on day one instead
    // of waiting on a reset link. Only applied if the agent has no password
    // yet — never overwrites one they've already set. Leave blank to disable
    // and require the admin "Agent Login Access" tool / a reset link instead.
    'default_agent_password' => '',   // e.g. Innovate!!

    // Show sample dashboard tiles/cap instead of querying a local DB. Keep true
    // on bold360.vip (no local Perfex DB) until the Darwin / tx feed is wired.
    'sample_dashboard' => false,

    // Intranet ticket API — "Get Support" button in AgentEdge submits tickets to
    // everythinginnovate.com. Set intranet_ticket_url to the intranet base URL
    // and intranet_ticket_token to the same value as AGENTEDGE_TICKET_TOKEN in
    // the intranet's .env file.
    'intranet_ticket_url'   => '',   // e.g. https://everythinginnovate.com
    'intranet_ticket_token' => '',

    // Intranet events API — pull org-wide calendar events into AgentEdge.
    // Set intranet_events_url to https://your-intranet.com/api/events
    // and intranet_events_token to the same value as AGENTEDGE_EVENTS_TOKEN in the intranet .env.
    'intranet_events_url'   => '',
    'intranet_events_token' => '',

    // Permissions API token — used by the intranet and other apps to call
    // /api/permissions.php. Generate with: openssl rand -hex 32
    // Store the same value as AGENTEDGE_PERMISSIONS_TOKEN in each consumer's .env.
    'permissions_token' => '',

    // bold360.vip CRM — source of the agent roster + profile editing.
    'crm_base'  => 'https://bold360.vip/api',
    // Shared token that unlocks contact details (email/phone) and lets agents
    // save profile edits. Must match AGENTEDGE_TOKEN in the CRM's environment.
    // Leave blank to show the roster without contact info and disable editing.
    // Also validates the reverse direction: the CRM calling INTO AgentEdge at
    // api/onboard_push.php (Add to Team → onboarding queue) and
    // api/roster_export.php (retention_status overlay) uses this same value.
    'crm_token' => '',

    // Google OAuth — lets any @innovateonline.com agent sign in with one click.
    // Setup: console.cloud.google.com → APIs & Services → Credentials → Create OAuth client
    //   Application type: Web application
    //   Authorized redirect URI: https://agentedge.bold360.vip/auth_google.php
    // Copy the Client ID and Secret here. Leave blank to hide the Google button.
    'google_client_id'     => '',
    'google_client_secret' => '',
    // Optional: override the redirect URI if your domain differs.
    'google_redirect_uri'  => '',

    // DotLoop — transaction management. Apply for API access at info.dotloop.com/developers.
    // In the DotLoop Partner Portal, register the redirect URI below.
    // The redirect URI defaults to https://agentedge.innovateonline.com/dotloop_callback.php
    // if this key is left blank — only set it if you need to override that.
    'dotloop_client_id'     => '',
    'dotloop_client_secret' => '',
    'dotloop_redirect_uri'  => '',   // leave blank to use the production default

    // Trestle MLS API — used by the Open House Portal to auto-fill listing
    // details when an agent enters an MLS number.
    // Get credentials from: https://trestle.corelogic.com → My Account → API Keys
    // OAuth2 client credentials flow; tokens are cached automatically.
    'trestle_client_id'     => '',
    'trestle_client_secret' => '',

    // Regrid Parcel API — company account (shared by all agents), powers Listing
    // Intel's farm sync (owner/mailing address, sale history, assessed value).
    // Sign up at https://regrid.com/api (1-week free trial), get your token at
    // app.regrid.com → Account → API. Leave blank to show the "coming soon" banner.
    'regrid_api_key' => '',

    // SendGrid — transactional email for announcements and other agent notifications.
    // Get API key at: app.sendgrid.com → Settings → API Keys → Create API Key (Mail Send)
    // Verified sender domain: innovateonline.com (domain auth already complete).
    'sendgrid_key'  => '',                              // SG.xxxxxxxx
    'sendgrid_from' => 'noreply@innovateonline.com',    // must be on verified domain
    'sendgrid_name' => 'INNOVATE Real Estate',          // display name

    // Reply-by-email for support tickets — dedicated subdomain (NOT innovateonline.com
    // itself) receiving mail via SendGrid Inbound Parse, so it never touches real
    // company mailboxes. Setup: 1) add an MX record for this subdomain pointing to
    // mx.sendgrid.net (priority 10); 2) app.sendgrid.com → Settings → Inbound Parse →
    // Add Host & URL, host = ticket_reply_domain below, URL = https://agents.innovateonline.com/api/ticket_email_inbound.php.
    // ticket_reply_secret signs the per-ticket reply token; leave blank to derive it
    // from sendgrid_key instead of provisioning a separate secret.
    'ticket_reply_domain' => 'reply.innovateonline.com',
    'ticket_reply_secret' => '',

    // Twilio — SMS notifications for announcements.
    // Find credentials at: console.twilio.com → Account Info
    'twilio_sid'   => '',    // ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
    'twilio_token' => '',    // your auth token
    'twilio_from'  => '',    // your Twilio phone number, e.g. +18435551234

    // Google Calendar — training events shown on the Calendar page.
    // gcal_key_file: absolute path to the service account JSON key (never commit this file).
    // gcal_calendar_id: the calendar's email address or ID.
    'gcal_key_file'    => __DIR__ . '/agentedge-calendar-key.json',
    'gcal_calendar_id' => 'training@innovateonline.com',

    // Anthropic Claude API — used by the Finance Statement Scanner to categorize
    // spending and generate savings recommendations. Get a key at console.anthropic.com.
    'anthropic_api_key' => '',

    // Onboarding / offboarding notifications — comma-separated list of email addresses
    // that receive a copy of every new onboarding or offboarding email (fires whenever
    // an agent is moved into the onboarding queue, via notify_onboard_added() in
    // lib/notifications.php). The admin who creates the record always receives a copy;
    // add your broker, marketing lead, etc.
    // Example: 'broker@innovateonline.com,marketing@innovateonline.com'
    'onboard_notify_emails' => '',

    // Follow Up Boss — auto-provisions new agents. Get key at: FUB → Admin → API
    'fub_api_key' => '',
    // FUB also requires system identification headers on every request —
    // register at https://apps.followupboss.com/system-registration to get these.
    'fub_system_name' => '',
    'fub_system_key'  => '',

    // Constellation1 — agent website platform user provisioning (SOAP API).
    // Get from Constellation1 support.
    'c1_api_token' => '',
    'c1_api_salt'  => '',

    // Tax ID encryption — encrypts personal SSN / corporate EIN entered on the
    // intake form before they're stored in local_db(). Generate a unique key
    // per environment with: openssl rand -base64 32
    // Never reuse the same key across dev/staging/production, and never commit
    // a real key — config.php is git-ignored, this sample file is not.
    'tax_id_encryption_key' => '',

    // Darwin Cloud custom API (lib/darwin.php, cron/sync_darwin.php) — pulls cap
    // progress, revenue share, and sales volume from INNOVATE's finance/commission
    // system into the cap wheel + growth network. Request credentials from
    // support@accounttech.com. These are only the INITIAL seed values — once
    // synced, the live pair is tracked in the darwin_auth table and rotates on
    // every refresh, so don't expect this file to reflect the current token.
    // Geo-restricted to US IPs (dev + prod) — see the AccountTECH developer guide.
    'darwin_username'      => '',
    'darwin_access_token'  => '',
    'darwin_refresh_token' => '',
    'darwin_token_expires' => '',  // format: MM/DD/YYYY HH:MM:SS, as issued by AccountTECH
];
