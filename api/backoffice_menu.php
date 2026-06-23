<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
$perms = current_perms();
if (empty($perms['isSuperAdmin'])) {
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$db = local_db();

switch ($action) {

    case 'add':
        $label  = trim($body['label'] ?? '');
        $url    = trim($body['url']   ?? '');
        $is_ext = (int)($body['is_ext'] ?? 0);
        $dept   = trim($body['department'] ?? 'Operations');
        if (!$label || !$url) { echo json_encode(['ok'=>false,'error'=>'label and url required']); exit; }
        $maxOrd = (int)($db->query("SELECT COALESCE(MAX(sort_ord),0) FROM backoffice_items")->fetchColumn());
        $s = $db->prepare("INSERT INTO backoffice_items (label,url,is_ext,sort_ord,enabled,department) VALUES (?,?,?,?,1,?)");
        $s->execute([$label, $url, $is_ext, $maxOrd + 10, $dept]);
        $id = (int)$db->lastInsertId();
        echo json_encode(['ok'=>true, 'item'=>['id'=>$id,'label'=>$label,'url'=>$url,'is_ext'=>$is_ext,'department'=>$dept]]);
        break;

    case 'update':
        $id      = (int)($body['id'] ?? 0);
        $label   = trim($body['label']      ?? '');
        $url     = trim($body['url']        ?? '');
        $is_ext  = (int)($body['is_ext']    ?? 0);
        $enabled = (int)($body['enabled']   ?? 1);
        $dept    = trim($body['department'] ?? 'Operations');
        if (!$id || !$label || !$url) { echo json_encode(['ok'=>false,'error'=>'id, label and url required']); exit; }
        $s = $db->prepare("UPDATE backoffice_items SET label=?,url=?,is_ext=?,enabled=?,department=? WHERE id=?");
        $s->execute([$label, $url, $is_ext, $enabled, $dept, $id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'delete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $db->prepare("DELETE FROM backoffice_items WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        break;

    case 'move':
        $id  = (int)($body['id']  ?? 0);
        $dir = (int)($body['dir'] ?? 0);
        if (!$id || !in_array($dir, [-1, 1])) { echo json_encode(['ok'=>false]); exit; }
        // Fetch all items in order, swap the target with its neighbor.
        $rows = $db->query("SELECT id,sort_ord FROM backoffice_items ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
        $idx  = array_search($id, array_column($rows, 'id'));
        if ($idx === false) { echo json_encode(['ok'=>false]); exit; }
        $swapIdx = $idx + $dir;
        if ($swapIdx < 0 || $swapIdx >= count($rows)) { echo json_encode(['ok'=>true]); break; }
        // Swap sort_ord values.
        $aOrd = $rows[$idx]['sort_ord'];
        $bOrd = $rows[$swapIdx]['sort_ord'];
        if ($aOrd === $bOrd) { $bOrd = $aOrd + ($dir > 0 ? 1 : -1); }
        $db->prepare("UPDATE backoffice_items SET sort_ord=? WHERE id=?")->execute([$bOrd, $rows[$idx]['id']]);
        $db->prepare("UPDATE backoffice_items SET sort_ord=? WHERE id=?")->execute([$aOrd, $rows[$swapIdx]['id']]);
        echo json_encode(['ok'=>true]);
        break;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
