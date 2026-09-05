<?php
// Fathom.video API — auto-ingests recorded training calls into University.
// Auth: X-Api-Key header (personal API key from Fathom → Settings → API Access).
// Base URL: https://api.fathom.ai/external/v1
// Webhook: registered in Fathom against api/fathom_webhook.php, event
// "new-meeting-content-ready" — verified below (Svix-style signing, see
// fathom_verify_webhook()). Config keys: 'fathom_api_key', 'fathom_webhook_secret'
// (config.php / config.sample.php). Docs: https://developers.fathom.ai
//
// NOTE: fathom_request_recording_download() / fathom_get_download_status()
// below are the least confidently documented part of Fathom's public API as
// of this writing — the docs describe the download flow ("request a
// download" → poll a download_id → get a short-lived signed URL) but didn't
// expose the literal path/verb strings. Verify these two against
// https://developers.fathom.ai (or the response Fathom actually returns)
// once a real API key is live, and adjust the paths/field names here if
// needed — that's the first thing to check if ingestion stalls in the
// 'downloading' state. Everything else (meeting fetch, webhook signing)
// matches Fathom's published quickstart examples directly.

require_once __DIR__ . '/../local_db.php';

function fathom_request(string $method, string $path, ?array $query = null): array {
    $c      = cfg();
    $apiKey = $c['fathom_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'code'=>0,'data'=>null];

    $url = "https://api.fathom.ai/external/v1{$path}";
    if ($query) $url .= (strpos($path, '?') !== false ? '&' : '?') . http_build_query($query);

    $opts = [
        'method'        => $method,
        'timeout'       => 30,
        'header'        => "X-Api-Key: {$apiKey}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ];
    $ctx  = stream_context_create(['http' => $opts]);
    $raw  = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header[0])) {
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
    }
    $d = $raw ? json_decode($raw, true) : null;
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $d];
}

// Fetches the AI-generated summary for a recording — verified against live
// API. Returns {"summary": {"template_name":..., "markdown_formatted":...}}.
function fathom_get_summary(string $recordingId): array {
    return fathom_request('GET', "/recordings/{$recordingId}/summary");
}

// Kicks off async generation of a downloadable video file for a recording.
function fathom_request_recording_download(string $recordingId): array {
    return fathom_request('POST', "/recordings/{$recordingId}/download");
}

// Verifies an inbound Fathom webhook — Svix-style signing: HMAC-SHA256 of
// "{webhook-id}.{webhook-timestamp}.{raw body}", keyed by the base64 portion
// of the webhook secret (prefixed 'whsec_'), base64-encoded, and compared
// against each space-separated, version-prefixed signature in
// webhook-signature. $headers keys are expected lowercased.
function fathom_verify_webhook(string $rawBody, array $headers): bool {
    $c      = cfg();
    $secret = $c['fathom_webhook_secret'] ?? '';
    $id     = $headers['webhook-id'] ?? '';
    $ts     = $headers['webhook-timestamp'] ?? '';
    $sigHdr = $headers['webhook-signature'] ?? '';
    if ($secret === '' || $id === '' || $ts === '' || $sigHdr === '') return false;
    if (abs(time() - (int)$ts) > 300) return false; // 5-minute tolerance

    $secretB64 = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
    $key       = base64_decode($secretB64);
    $expected  = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$rawBody}", $key, true));

    foreach (explode(' ', $sigHdr) as $sig) {
        $sig = strpos($sig, ',') !== false ? substr($sig, strpos($sig, ',') + 1) : $sig;
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

// Converts Fathom's markdown_formatted summary into safe, simple HTML.
// Handles just what Fathom summaries actually use: ## / ### headers,
// **bold**, [text](url) links, and "- " bullet lists. Escapes everything
// else so no raw HTML can be injected from the summary content.
function fathom_markdown_to_html(string $md): string {
    $lines = explode("\n", $md);
    $html = '';
    $inList = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
            $level = min(strlen($m[1]) + 2, 6); // ## -> h4, ### -> h5, etc.
            $text = fathom_md_inline(htmlspecialchars($m[2], ENT_QUOTES));
            $html .= "<h{$level}>{$text}</h{$level}>\n";
            continue;
        }

        if (preg_match('/^-\s+(.*)$/', $trimmed, $m)) {
            if (!$inList) { $html .= "<ul>\n"; $inList = true; }
            $text = fathom_md_inline(htmlspecialchars($m[1], ENT_QUOTES));
            $html .= "<li>{$text}</li>\n";
            continue;
        }

        if ($inList) { $html .= "</ul>\n"; $inList = false; }
        $text = fathom_md_inline(htmlspecialchars($trimmed, ENT_QUOTES));
        $html .= "<p>{$text}</p>\n";
    }
    if ($inList) $html .= "</ul>\n";

    return $html;
}

// Applies inline markdown (bold, links) to already-escaped text.
function fathom_md_inline(string $text): string {
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);
    return $text;
}

// Derives tags and a learning objective straight from Fathom's own summary
// HTML (fathom_markdown_to_html() output) — no external API call, no cost.
// Fathom's summary template is consistent across recordings: an "H4 Meeting
// Purpose" section (one sentence, used as the learning objective) and an
// "H4 Topics" section containing one "H5" sub-heading per topic discussed
// (used as tags). Used in place of the generic 'training-call' / 'Review
// this recorded training call.' placeholders api/fathom_webhook.php sets at
// ingestion time. Returns null if the expected sections aren't found (e.g.
// a differently-templated summary) so callers fall back to whatever the
// lesson already has. Difficulty has no real signal in a meeting summary,
// so this always returns 'beginner' rather than guess.
function fathom_extract_lesson_metadata(string $summaryHtml): ?array {
    if (trim($summaryHtml) === '') return null;

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $summaryHtml . '</div>');
    libxml_clear_errors();
    $headings = $doc->getElementsByTagName('h4');

    $objective = '';
    $topicsHeading = null;
    foreach ($headings as $h) {
        $label = strtolower(trim($h->textContent));
        if ($label === 'meeting purpose') {
            $p = $h->nextSibling;
            while ($p && $p->nodeType !== XML_ELEMENT_NODE) $p = $p->nextSibling;
            if ($p && strtolower($p->nodeName) === 'p') {
                $objective = trim(preg_replace('/\s+/', ' ', $p->textContent));
            }
        } elseif ($label === 'topics') {
            $topicsHeading = $h;
        }
    }

    $tags = [];
    if ($topicsHeading) {
        for ($sib = $topicsHeading->nextSibling; $sib; $sib = $sib->nextSibling) {
            if ($sib->nodeType !== XML_ELEMENT_NODE) continue;
            if ($sib->nodeName === 'h4') break; // reached the next top-level section
            if ($sib->nodeName === 'h5') {
                $slug = fathom_slugify($sib->textContent);
                if ($slug) $tags[] = $slug;
            }
        }
    }
    $tags = array_slice(array_values(array_unique(array_merge($tags, ['training-call']))), 0, 5);

    if (!$tags || $objective === '') return null;
    return ['tags' => $tags, 'learning_objective' => $objective, 'difficulty' => 'beginner'];
}

function fathom_slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[\'’]/', '', $text); // "Estate's" -> "estates", not "estate-s"
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

// All Fathom-ingested lessons land in this one always-published holding
// course; an admin sorts them into the right course afterward via the
// "Move / Transfer to Another Course" control in admin_university_course.php's
// lesson editor. pending_review gates per-lesson visibility until then.
function fathom_get_or_create_holding_course(): int {
    $db = local_db();
    $id = $db->query("SELECT id FROM uni_courses WHERE title='Recorded Training Calls'")->fetchColumn();
    if ($id) return (int)$id;
    $db->prepare("INSERT INTO uni_courses (title,description,published,created_by) VALUES (?,?,1,?)")
       ->execute(['Recorded Training Calls', 'Training calls recorded via Fathom, added automatically.', 'fathom-webhook']);
    return (int)$db->lastInsertId();
}
