<?php
// Referral Network — AI-assisted partner contact lookup. Given a name (and
// optional location/company hints), asks Claude to search the web for a real
// estate agent's public contact info and return it as structured data for
// the Add Partner form to prefill. Best-effort only — the frontend must
// present every result as AI-suggested/unverified, never auto-saved as-is.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';

function rpl_json_out(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

$agent = current_agent();
if (!$agent) { rpl_json_out(['ok' => false, 'error' => 'Not signed in'], 401); }

$in   = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim($in['name'] ?? '');
if ($name === '') { rpl_json_out(['ok' => false, 'error' => 'Name is required'], 400); }

$stateHint   = trim($in['state_hint'] ?? '');
$metroHint   = trim($in['metro_hint'] ?? '');
$companyHint = trim($in['company_hint'] ?? '');

$apiKey = cfg()['anthropic_api_key'] ?? '';
if ($apiKey === '') { rpl_json_out(['ok' => false, 'error' => 'Anthropic API key not configured']); }

$hints = [];
if ($metroHint !== '' || $stateHint !== '') {
    $hints[] = 'They likely work in or near ' . trim($metroHint . ' ' . $stateHint) . '.';
}
if ($companyHint !== '') {
    $hints[] = "Their brokerage/company may be \"{$companyHint}\".";
}
$hintText = $hints ? (' ' . implode(' ', $hints)) : '';

$system = <<<SYS
You are a careful research assistant helping a real estate agent find accurate, current, publicly available contact information for another real estate agent, so they can be added to a professional referral network. Use web search to check sources like Realtor.com, Zillow agent profiles, the agent's own brokerage website, and state real estate license lookups. Cross-check across more than one source when the name is common or results conflict. Only report a field if you found real evidence for it in your search results — never guess or infer a phone number or email from a pattern. If you cannot confidently find someone, say so.

Reply with ONLY a single JSON object and nothing else — no markdown code fences, no explanation before or after. Match this exact shape:
{
  "found": true or false,
  "name": "full name as found, or null",
  "company": "brokerage/company name, or null",
  "phone": "phone number, or null",
  "email": "email address, or null",
  "specialty": "a short specialty description if stated somewhere (e.g. \"Luxury\", \"Relocation\"), or null",
  "city": "city or metro area, or null",
  "state": "2-letter state code, or null",
  "confidence": "high", "medium", or "low",
  "note": "one short sentence: what you found, any ambiguity, or why nothing was found",
  "sources": ["url", "..."]
}
SYS;

$userMsg = "Find contact info for a real estate agent named: {$name}.{$hintText}";

$payload = [
    'model'      => 'claude-opus-5',
    'max_tokens' => 8000,
    'system'     => $system,
    'tools'      => [
        ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 4],
    ],
    'messages'   => [
        ['role' => 'user', 'content' => $userMsg],
    ],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);
$raw    = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    rpl_json_out(['ok' => false, 'error' => 'Could not reach Claude: ' . $err], 502);
}

$resp = json_decode($raw, true);
if ($status < 200 || $status >= 300 || !is_array($resp)) {
    $msg = $resp['error']['message'] ?? ('HTTP ' . $status);
    rpl_json_out(['ok' => false, 'error' => 'Lookup failed: ' . $msg], 502);
}

if (($resp['stop_reason'] ?? '') === 'refusal') {
    rpl_json_out(['ok' => false, 'error' => 'Claude declined this search.']);
}

// Gather any web search result URLs Claude actually looked at, as a fallback
// source list in case the model's own "sources" field in its JSON answer
// comes back empty.
$searchedUrls = [];
foreach ($resp['content'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'web_search_tool_result') {
        $items = $block['content'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!empty($item['url'])) $searchedUrls[] = $item['url'];
            }
        }
    }
}

// The final answer is the last text block.
$text = '';
foreach ($resp['content'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'text') $text = $block['text'];
}

// Claude was told not to wrap in markdown fences, but extract defensively —
// find the outermost {...} in case it did anyway or added stray text.
$json = null;
if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
    $json = json_decode($m[0], true);
}

if (!is_array($json)) {
    rpl_json_out(['ok' => false, 'error' => 'Could not parse a result from Claude.']);
}

$sources = $json['sources'] ?? [];
if (!is_array($sources) || !$sources) $sources = array_values(array_unique($searchedUrls));

rpl_json_out([
    'ok'         => true,
    'found'      => (bool)($json['found'] ?? false),
    'name'       => $json['name'] ?? null,
    'company'    => $json['company'] ?? null,
    'phone'      => $json['phone'] ?? null,
    'email'      => $json['email'] ?? null,
    'specialty'  => $json['specialty'] ?? null,
    'city'       => $json['city'] ?? null,
    'state'      => $json['state'] ?? null,
    'confidence' => $json['confidence'] ?? null,
    'note'       => $json['note'] ?? null,
    'sources'    => array_slice($sources, 0, 5),
]);
