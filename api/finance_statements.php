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
        $label    = trim($body['account_label'] ?? '');
        $type     = in_array($body['scan_type']??'', ['bank','credit_card']) ? $body['scan_type'] : 'bank';
        $raw      = trim($body['raw_text']  ?? '');
        $fileData = trim($body['file_data'] ?? '');  // base64
        $fileMime = trim($body['file_mime'] ?? '');

        $hasFile = $fileData !== '' && $fileMime !== '';
        $hasText = $raw !== '';

        if (!$hasFile && !$hasText) { echo json_encode(['ok'=>false,'error'=>'Provide a file or raw text']); exit; }

        $apiKey = cfg()['anthropic_api_key'] ?? '';
        if (!$apiKey) { echo json_encode(['ok'=>false,'error'=>'Anthropic API key not configured']); exit; }

        // Validate mime type
        $allowedMimes = ['application/pdf','image/png','image/jpeg','image/jpg'];
        if ($hasFile && !in_array($fileMime, $allowedMimes)) {
            echo json_encode(['ok'=>false,'error'=>'Unsupported file type: ' . $fileMime]); exit;
        }

        // Store scan record
        $rawForDb = $hasFile ? '[file upload: ' . $fileMime . ']' : $raw;
        $ins = $db->prepare("INSERT INTO statement_scans (account_label,scan_type,uploaded_by,raw_text,status) VALUES (?,?,?,?,'pending')");
        $ins->execute([$label, $type, $email, $rawForDb]);
        $scanId = (int)$db->lastInsertId();

        // Build Claude prompt
        $typeLabel = $type === 'credit_card' ? 'credit card' : 'bank account';
        $acctDesc  = $label ? "Account: {$label}" : "Account: unlabeled {$typeLabel}";

        $prompt = <<<PROMPT
You are a sharp financial advisor reviewing a {$typeLabel} statement for INNOVATE Real Estate, a real estate brokerage company. {$acctDesc}

Your primary goal is to identify specific, actionable opportunities to save money. Be concrete — reference actual vendors and amounts you see in the statement.

Extract all expense/debit transactions. Then analyze the spending and return a JSON object with this exact structure:

{
  "statement_period": "<detected date range, e.g. June 2026>",
  "total_spending": <number — sum of all debits/expenses>,
  "total_potential_savings": <number — sum of all estimated_monthly_savings>,
  "summary": "<2-3 sentence executive summary citing the biggest spending areas and top savings opportunity>",
  "savings_opportunities": [
    {
      "category": "<spending category>",
      "vendor": "<specific vendor/service name if identifiable, or empty string>",
      "current_spend": <number — what they're currently spending monthly on this>,
      "title": "<concise savings opportunity title — max 10 words>",
      "detail": "<2-3 sentences explaining the specific issue and why savings are possible — cite actual amounts>",
      "how_to_save": "<one specific action step — e.g. 'Call Spectrum to negotiate, mention competitor rate of $X'>",
      "estimated_monthly_savings": <number>,
      "effort": "low|medium|high",
      "priority": "high|medium|low"
    }
  ],
  "categories": [
    {
      "name": "<category name>",
      "total": <number>
    }
  ],
  "transactions": [
    {
      "date": "<YYYY-MM-DD or as shown>",
      "description": "<transaction description>",
      "amount": <number>,
      "category": "<category>"
    }
  ]
}

Category names to use: Software/SaaS, Marketing/Advertising, Office Rent, Utilities, Payroll, Professional Fees, Travel, Meals/Entertainment, Office Supplies, Insurance, Recruiting, Events, Banking Fees, Subscriptions, Other.

Savings opportunity rules:
- Only include savings you can actually see evidence for in the statement
- Prioritize: duplicate services, monthly→annual billing switches, vendor renegotiation, unused subscriptions, excessive fees
- effort "low" = one email or phone call; "medium" = 1-2 hours research/negotiation; "high" = process change or vendor switch
- priority "high" = over $200/mo savings or urgent issue; "medium" = $50-200/mo; "low" = under $50/mo
- Be specific and realistic — don't invent savings that aren't visible in the data
- If spending looks lean and efficient, say so and provide fewer but more targeted opportunities

Return ONLY valid JSON — no markdown fences, no text outside the JSON object.
PROMPT;

        // Build Claude message content
        if ($hasFile) {
            $contentType = str_starts_with($fileMime, 'image/') ? 'image' : 'document';
            $messageContent = [
                [
                    'type'   => $contentType,
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $fileMime,
                        'data'       => $fileData,
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ];
        } else {
            $messageContent = [
                [
                    'type' => 'text',
                    'text' => $prompt . "\n\nStatement data:\n" . $raw,
                ],
            ];
        }

        // Use Sonnet for file analysis (better at reading complex documents), Haiku for plain text
        $model = $hasFile ? 'claude-sonnet-4-6' : 'claude-haiku-4-5-20251001';

        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => 4096,
            'messages'   => [['role'=>'user','content'=>$messageContent]],
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
            CURLOPT_TIMEOUT    => 120,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!$resp || $status !== 200) {
            $db->prepare("UPDATE statement_scans SET status='error' WHERE id=?")->execute([$scanId]);
            $errMsg = $curlErr ?: ('Claude API error HTTP ' . $status);
            if ($resp) {
                $errDetail = json_decode($resp, true);
                if (!empty($errDetail['error']['message'])) $errMsg = $errDetail['error']['message'];
            }
            echo json_encode(['ok'=>false,'error'=>$errMsg]);
            exit;
        }

        $respData = json_decode($resp, true);
        $rawJson  = $respData['content'][0]['text'] ?? '';

        // Strip accidental markdown fences
        $rawJson = preg_replace('/^```(?:json)?\s*/i', '', trim($rawJson));
        $rawJson = preg_replace('/\s*```$/', '', $rawJson);

        $analysis = json_decode($rawJson, true);
        if (!is_array($analysis)) {
            $db->prepare("UPDATE statement_scans SET status='error', analysis_json=? WHERE id=?")->execute([$rawJson, $scanId]);
            echo json_encode(['ok'=>false,'error'=>'Could not parse response as JSON — model may have returned unexpected format']);
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
        unset($row['raw_text']);
        echo json_encode(['ok'=>true, 'scan'=>$row]);
        break;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
