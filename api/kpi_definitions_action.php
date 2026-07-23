<?php
// KPI catalog editor — lets admin/coaching leadership adjust weekly targets
// without a code change. GET (read-only labels/targets) is open to any
// signed-in agent; POST (upsert) is admin only.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }

$pdo = local_db();

function jok(array $x = []): void { echo json_encode(array_merge(['ok' => true], $x)); exit; }
function jerr(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $program = preg_replace('/[^a-z_]/', '', $_GET['program'] ?? 'launch') ?: 'launch';
    $st = $pdo->prepare("SELECT * FROM kpi_definitions WHERE program=? AND active=1 ORDER BY sort_ord");
    $st->execute([$program]);
    jok(['kpis' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if (!is_admin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'admin only']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'GET or POST only']); exit; }

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$program = preg_replace('/[^a-z_]/', '', $body['program'] ?? 'launch') ?: 'launch';
$kpiKey  = preg_replace('/[^a-z_]/', '', $body['kpi_key'] ?? '');
$label   = trim($body['label'] ?? '');
$target  = (int)($body['weekly_target'] ?? 0);
if ($kpiKey === '' || $label === '') jerr('kpi_key and label required');

$pdo->prepare(
    "INSERT INTO kpi_definitions (program, kpi_key, label, weekly_target, sort_ord) VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(sort_ord),0)+10 FROM kpi_definitions WHERE program=?))
     ON CONFLICT(program, kpi_key) DO UPDATE SET label=excluded.label, weekly_target=excluded.weekly_target"
)->execute([$program, $kpiKey, $label, $target, $program]);

jok();
