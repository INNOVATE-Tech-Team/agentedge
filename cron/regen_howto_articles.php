<?php
// How-To library: nightly regenerator.
//
// Enumerates every feature registered in nav.php (nav_items(),
// backoffice_nav_items(true), agent_assets_items() -- these already merge in
// the DB-driven nav_ext_links/backoffice_items entries, so calling them is
// enough, no separate query needed), reads each feature's own PHP source,
// and asks Claude to write a short plain-English how-to whenever that
// source has changed since the last run (tracked via a sha256 hash, so an
// unattended nightly run costs nothing on a night with no code changes).
//
// Run via crontab:
//   0 5 * * * /usr/bin/php /home/ec2-user/agentedge/cron/regen_howto_articles.php >> /home/ec2-user/agentedge/cron/regen_howto_articles.log 2>&1
// There is no registration mechanism in this repo (see the other cron/*.php
// files) -- this line has to be added to the box's crontab by hand.

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../nav.php';
require_once __DIR__ . '/../lib/notifications.php';

function howto_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

$apiKey = cfg()['anthropic_api_key'] ?? '';
if (!$apiKey) {
    howto_log('anthropic_api_key not configured in config.php -- nothing to do.');
    exit(0);
}

$db = local_db();

// Every nav-registered feature, from the same three functions render_sidebar()
// itself calls -- this IS the product's feature list, not a hand-maintained copy.
$allItems = array_merge(nav_items(), backoffice_nav_items(true), agent_assets_items());

$seenKeys = [];
$generated = 0;
$skippedUnchanged = 0;
$skippedNoSource = 0;
$failed = 0;

foreach ($allItems as $item) {
    $key = $item['key'] ?? '';
    $label = $item['label'] ?? '';
    $href = $item['href'] ?? '';
    if ($key === '' || $key === '__assets__' || $label === '') continue;
    if (!empty($item['external'])) continue; // nothing local to read source from

    // Strip a query string (e.g. 'docs.php?folder=1') to get the real file.
    $file = strtok($href, '?');
    $path = realpath(__DIR__ . '/../' . $file);
    // realpath() also guards against a nav href ever pointing outside the
    // app root -- if that ever happened this silently skips rather than
    // reads/sends an arbitrary file to Claude.
    if (!$path || strpos($path, realpath(__DIR__ . '/..')) !== 0 || !is_file($path)) {
        $skippedNoSource++;
        continue;
    }

    $seenKeys[] = $key;
    $source = file_get_contents($path);
    $hash = hash('sha256', $source);

    $existing = $db->prepare("SELECT source_hash FROM howto_articles WHERE page_key = ?");
    $existing->execute([$key]);
    $existingHash = $existing->fetchColumn();

    if ($existingHash === $hash) {
        $skippedUnchanged++;
        continue;
    }

    $body = howto_generate_article($apiKey, $label, $source);
    if ($body === null) {
        $failed++;
        howto_log("FAILED generating article for '$key' ($label)");
        usleep(300000);
        continue;
    }

    $db->prepare(
        "INSERT INTO howto_articles (page_key, label, href, body_markdown, source_hash, generated_at, is_stale)
         VALUES (?, ?, ?, ?, ?, datetime('now'), 0)
         ON CONFLICT(page_key) DO UPDATE SET
           label = excluded.label, href = excluded.href, body_markdown = excluded.body_markdown,
           source_hash = excluded.source_hash, generated_at = excluded.generated_at, is_stale = 0"
    )->execute([$key, $label, $href, $body, $hash]);

    $generated++;
    usleep(300000); // stay rate-limit-friendly across ~60+ possible calls on a first run
}

// Anything with a stored article whose key we didn't see this run has left
// the nav registry -- soft-hide it, never delete (same convention as
// agent_sites.archived_at elsewhere).
$staled = 0;
if ($seenKeys) {
    $placeholders = implode(',', array_fill(0, count($seenKeys), '?'));
    $staled = $db->prepare(
        "UPDATE howto_articles SET is_stale = 1 WHERE is_stale = 0 AND page_key NOT IN ($placeholders)"
    );
    $staled->execute($seenKeys);
    $staled = $staled->rowCount();
}

howto_log("Done. generated=$generated skipped_unchanged=$skippedUnchanged skipped_no_source=$skippedNoSource failed=$failed newly_staled=$staled");

process_notification_queue();

/**
 * Ask Claude for a short plain-English how-to for one AgentEdge page, given
 * its label and full PHP source. Returns markdown (headers/paragraphs/bold/
 * bullet-or-numbered-lists/tables only -- the only constructs
 * render_launch_markdown() actually supports, see lib/markdown.php) or null
 * on any failure.
 */
function howto_generate_article(string $apiKey, string $label, string $source): ?string {
    // Cap source length sent per call -- a couple of the largest pages here
    // run several thousand lines; Claude only needs enough to describe
    // user-visible behavior, not the entire implementation.
    if (strlen($source) > 60000) $source = substr($source, 0, 60000) . "\n... (truncated)";

    $prompt = <<<PROMPT
You are writing a short internal how-to article for a real estate brokerage's staff/agent tool called "AgentEdge". Below is the actual PHP source code for one page/feature, called "{$label}".

Write a plain-English how-to for the person USING this feature -- never describe implementation details, variable names, database queries, or code structure. Describe only what a user sees and can do.

Source code:
```
{$source}
```

Output ONLY Markdown, using nothing but headers (##, ###), plain paragraphs, **bold**, bullet lists (- item), numbered lists (1. item), and simple pipe tables if genuinely useful. No links, no images, no blockquotes -- the renderer that will display this doesn't support them.

Structure:
## What this is for
One or two sentences.

## Who sees it
One sentence, based on what the code's access checks imply -- only if you can tell from the source; omit this section if it's unclear.

## How to use it
Numbered steps if it's a workflow, otherwise a short bullet list of what's on the page.

Return ONLY the markdown. No preamble, no code fences around the whole response.
PROMPT;

    $payload = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1200,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 45,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $status !== 200) return null;

    $data = json_decode($resp, true);
    $text = trim($data['content'][0]['text'] ?? '');
    if ($text === '') return null;

    // Strip an accidental wrapping code fence around the whole response.
    $text = preg_replace('/^```(?:markdown)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    return $text;
}
