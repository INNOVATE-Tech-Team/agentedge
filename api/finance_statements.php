<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isAdmin'])) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$email  = $agent['email'] ?? '';
$db     = local_db();

switch ($action) {

    case 'analyze':
        $raw   = trim($body['raw_text']      ?? '');
        $label = trim($body['account_label'] ?? '');
        $type  = in_array($body['scan_type']??'', ['bank','credit_card']) ? $body['scan_type'] : 'bank';
        if (!$raw) { echo json_encode(['ok'=>false,'error'=>'raw_text required']); exit; }

        $apiKey = cfg()['anthropic_api_key'] ?? '';
        if (!$apiKey) { echo json_encode(['ok'=>false,'error'=>'Anthropic API key not configured']); exit; }

        // Insert a pending scan row first
        $ins = $db->prepare("INSERT INTO statement_scans (account_label,scan_type,uploaded_by,raw_text,status) VALUES (?,?,?,?,'pending')");
        $ins->execute([$label, $type, $email, $raw]);
        $scanId = (int)$db->lastInsertId();

        // Build the prompt
        $typeLabel = $type === 'credit_card' ? 'credit card' : 'bank account';
        $prompt = <<<PROMPT
You are a financial analyst reviewing a {$typeLabel} statement for INNOVATE Real Estate, a real estate brokerage company.

Analyze the following transaction data and return a JSON object with this exact structure:
{
  "total_spending": <number — sum of all debit/expense transactions>,
  "summary": "<2-3 sentence plain-English summary of the overall financial picture>",
  "categories": [
    {
      "name": "<category name>",
      "total": <number>,
      "transactions": [
        {"date": "<YYYY-MM-DD or as given>", "desc": "<description>", "amount": <number>}
      ]
    }
  ],
  "recommendations": [
    {
      "category": "<category name>",
      "title": "<short recommendation title>",
      "detail": "<2-3 sentence actionable explanation>",
      "estimated_savings": <number — estimated monthly dollar savings, 0 if unknown>
    }
  ]
}

Rules:
- Categories should be business-relevant: Software/SaaS, Marketing/Advertising, Office Rent, Utilities, Payroll, Professional Fees, Travel, Meals/Entertainment, Office Supplies, Insurance, Recruiting, Events, Other.
- Only include expense/debit transactions in total_spending. Ignore credits/deposits.
- Provide 4-8 specific, actionable savings recommendations based on what you actually see in the data.
- Focus recommendations on: duplicate subscriptions, unused services, better pricing tiers, negotiation opportunities, policy improvements.
- Return ONLY valid JSON — no markdown, no explanation outside the JSON.

Statement data:
{$raw}
PROMPT;

        // Call Claude API
        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 4096,
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
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$resp || $status !== 200) {
            $db->prepare("UPDATE statement_scans SET status='error' WHERE id=?")->execute([$scanId]);
            echo json_encode(['ok'=>false,'error'=>'Claude API error (HTTP '.$status.')']);
            exit;
        }

        $respData = json_decode($resp, true);
        $rawJson  = $respData['content'][0]['text'] ?? '';

        // Strip any accidental markdown fences
        $rawJson = preg_replace('/^```(?:json)?\s*/i', '', trim($rawJson));
        $rawJson = preg_replace('/\s*```$/', '', $rawJson);

        $analysis = json_decode($rawJson, true);
        if (!is_array($analysis)) {
            $db->prepare("UPDATE statement_scans SET status='error', analysis_json=? WHERE id=?")->execute([$rawJson, $scanId]);
            echo json_encode(['ok'=>false,'error'=>'Could not parse Claude response as JSON']);
            exit;
        }

        $db->prepare("UPDATE statement_scans SET status='complete', analysis_json=? WHERE id=?")
           ->execute([json_encode($analysis), $scanId]);

        echo json_encode(['ok'=>true, 'scan_id'=>$scanId, 'analysis'=>$analysis]);
        break;

    case 'delete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM statement_scans WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'get':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $st = $db->prepare("SELECT * FROM statement_scans WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
        if ($row['analysis_json']) $row['analysis'] = json_decode($row['analysis_json'], true);
        unset($row['raw_text']); // don't send raw text back over the wire
        echo json_encode(['ok'=>true, 'scan'=>$row]);
        break;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
