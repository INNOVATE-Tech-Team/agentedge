<?php
// An agent's own personal favorite links, shown in the "My Links" sidebar section.
// Always scoped to the signed-in agent — there is no email param, agents can only
// ever see/manage their own favorites.
// GET               → list my favorite links
// POST action=add    body: { label, url }
// POST action=update body: { id, label, url }
// POST action=delete body: { id }
// POST action=reorder body: { ids: "id,id,id" }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
$email = strtolower(trim($agent['email'] ?? ''));

$pdo = local_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'links' => agent_favorite_links_for($email)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

function normalize_link_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    return $url;
}

if ($action === 'add') {
    $label = trim($body['label'] ?? '');
    $url   = normalize_link_url($body['url'] ?? '');
    if ($label === '' || $url === '') { echo json_encode(['ok' => false, 'error' => 'label and url required']); exit; }
    $s = $pdo->prepare("SELECT COALESCE(MAX(sort_ord),0)+10 FROM agent_favorite_links WHERE email=?");
    $s->execute([$email]); $max = (int)$s->fetchColumn();
    $pdo->prepare("INSERT INTO agent_favorite_links (email,label,url,sort_ord) VALUES (?,?,?,?)")
        ->execute([$email, $label, $url, $max]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

if ($action === 'update') {
    $id    = (int)($body['id'] ?? 0);
    $label = trim($body['label'] ?? '');
    $url   = normalize_link_url($body['url'] ?? '');
    if (!$id || $label === '' || $url === '') { echo json_encode(['ok' => false, 'error' => 'id, label and url required']); exit; }
    $pdo->prepare("UPDATE agent_favorite_links SET label=?,url=? WHERE id=? AND email=?")
        ->execute([$label, $url, $id, $email]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'id required']); exit; }
    $pdo->prepare("DELETE FROM agent_favorite_links WHERE id=? AND email=?")->execute([$id, $email]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'reorder') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $body['ids'] ?? ''))));
    $st  = $pdo->prepare("UPDATE agent_favorite_links SET sort_ord=? WHERE id=? AND email=?");
    foreach ($ids as $i => $id) { if ($id > 0) $st->execute([($i + 1) * 10, $id, $email]); }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
