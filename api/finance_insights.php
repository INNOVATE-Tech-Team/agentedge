<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isAdmin'])) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action !== 'analyze') {
    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
    exit;
}

$apiKey = cfg()['anthropic_api_key'] ?? '';
if (!$apiKey) { echo json_encode(['ok'=>false,'error'=>'Anthropic API key not configured in config.php']); exit; }

$periodId = (int)($body['period_id'] ?? 0);
if (!$periodId) { echo json_encode(['ok'=>false,'error'=>'period_id required']); exit; }

$db = local_db();

$period = $db->prepare("SELECT * FROM budget_periods WHERE id=?");
$period->execute([$periodId]);
$period = $period->fetch(PDO::FETCH_ASSOC);
if (!$period) { echo json_encode(['ok'=>false,'error'=>'Period not found']); exit; }

$st = $db->prepare("SELECT * FROM budget_lines WHERE period_id=? ORDER BY department, category");
$st->execute([$periodId]);
$lines = $st->fetchAll(PDO::FETCH_ASSOC);
if (empty($lines)) { echo json_encode(['ok'=>false,'error'=>'No budget lines to analyze']); exit; }

// Build a structured summary for the prompt
$byDept = [];
foreach ($lines as $l) {
    $d = $l['department'];
    if (!isset($byDept[$d])) $byDept[$d] = ['budget'=>0,'actual'=>0,'lines'=>[]];
    $byDept[$d]['budget'] += $l['budgeted_amt'];
    $byDept[$d]['actual'] += $l['actual_amt'];
    $byDept[$d]['lines'][] = [
        'category'    => $l['category'],
        'description' => $l['description'],
        'budgeted'    => round($l['budgeted_amt'], 2),
        'actual'      => round($l['actual_amt'],   2),
        'variance'    => round($l['budgeted_amt'] - $l['actual_amt'], 2),
        'pct_used'    => $l['budgeted_amt'] > 0 ? round($l['actual_amt'] / $l['budgeted_amt'] * 100, 1) : null,
    ];
}

$totalBudget = array_sum(array_column($lines, 'budgeted_amt'));
$totalActual = array_sum(array_column($lines, 'actual_amt'));
$totalVar    = round($totalBudget - $totalActual, 2);
$pctUsed     = $totalBudget > 0 ? round($totalActual / $totalBudget * 100, 1) : 0;

$deptSummary = [];
foreach ($byDept as $dname => $d) {
    $deptSummary[$dname] = [
        'total_budgeted' => round($d['budget'], 2),
        'total_actual'   => round($d['actual'], 2),
        'variance'       => round($d['budget'] - $d['actual'], 2),
        'pct_used'       => $d['budget'] > 0 ? round($d['actual'] / $d['budget'] * 100, 1) : null,
        'lines'          => $d['lines'],
    ];
}

$periodLabel = $period['name'];
if ($period['start_date']) $periodLabel .= ' (' . $period['start_date'] . ' – ' . ($period['end_date'] ?: 'present') . ')';

$summaryJson = json_encode([
    'period'         => $periodLabel,
    'total_budgeted' => round($totalBudget, 2),
    'total_actual'   => round($totalActual, 2),
    'total_variance' => $totalVar,
    'pct_used'       => $pctUsed,
    'departments'    => $deptSummary,
], JSON_PRETTY_PRINT);

$prompt = <<<PROMPT
You are a financial advisor reviewing a real estate brokerage's departmental budget. Provide sharp, specific insights based on the actual numbers — no generic advice.

Budget data:
{$summaryJson}

Return a JSON object with this exact structure:
{
  "overall_health": "good|warning|critical",
  "health_score": <integer 0-100>,
  "summary": "<2-3 sentence executive summary referencing specific numbers>",
  "insights": [
    {
      "type": "success|warning|critical|tip",
      "dept": "<department name or 'Overall'>",
      "title": "<short insight title — max 10 words>",
      "detail": "<2-3 sentences specific to the actual data — cite numbers>",
      "action": "<one concrete recommended action>"
    }
  ],
  "quick_wins": [
    "<1-sentence actionable win>",
    "<1-sentence actionable win>",
    "<1-sentence actionable win>"
  ]
}

Rules:
- health_score: 80-100 = good, 60-79 = warning, 0-59 = critical
- Provide 4-8 insights. Prioritize: over-budget items, near-limit items, unusually low actuals (possible missed expenses), and cross-department opportunities.
- type "success" = positive observation; "warning" = approaching limit or concern; "critical" = over budget or serious issue; "tip" = optimization opportunity.
- quick_wins should be immediately actionable — specific, not generic.
- If all budgets look fine, focus tips on optimization and planning.
- Return ONLY valid JSON — no markdown, no text outside the JSON object.
PROMPT;

$payload = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 2048,
    'messages'   => [['role'=>'user','content'=>$prompt]],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT    => 45,
]);
$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$resp || $status !== 200) {
    echo json_encode(['ok'=>false,'error'=>'Claude API error (HTTP ' . $status . ')']);
    exit;
}

$respData = json_decode($resp, true);
$rawJson  = $respData['content'][0]['text'] ?? '';

// Strip accidental markdown fences
$rawJson = preg_replace('/^```(?:json)?\s*/i', '', trim($rawJson));
$rawJson = preg_replace('/\s*```$/', '', $rawJson);

$insights = json_decode($rawJson, true);
if (!is_array($insights)) {
    echo json_encode(['ok'=>false,'error'=>'Could not parse Claude response as JSON']);
    exit;
}

echo json_encode(['ok'=>true, 'insights'=>$insights]);
