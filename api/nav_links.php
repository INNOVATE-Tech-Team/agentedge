<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
$perms = current_perms();
if (empty($perms['isSuperAdmin'])) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$db     = local_db();

function jok(array $x = []): void { echo json_encode(array_merge(['ok'=>true],$x)); exit; }
function jerr(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

switch ($action) {

    // ── NAV EXT LINKS ─────────────────────────────────────────────────────────
    case 'add_nav':
        $key   = preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($body['key'] ?? '')));
        $label = trim($body['label'] ?? '');
        $url   = trim($body['url']   ?? '') ?: '#';
        $group = trim($body['group_label'] ?? 'Links');
        if (!$key || !$label) jerr('key and label required');
        $max = (int)$db->query("SELECT COALESCE(MAX(sort_ord),0)+10 FROM nav_ext_links")->fetchColumn();
        try {
            $db->prepare("INSERT INTO nav_ext_links (key,label,url,sort_ord,group_label) VALUES (?,?,?,?,?)")
               ->execute([$key,$label,$url,$max,$group]);
            $id = (int)$db->lastInsertId();
            jok(['item'=>['id'=>$id,'key'=>$key,'label'=>$label,'url'=>$url,'group_label'=>$group]]);
        } catch (\Exception $e) { jerr('Key already exists'); }
        break;

    case 'update_nav':
        $id    = (int)($body['id'] ?? 0);
        $label = trim($body['label']       ?? '');
        $url   = trim($body['url']         ?? '') ?: '#';
        $group = trim($body['group_label'] ?? '');
        if (!$id || !$label) jerr('id and label required');
        $db->prepare("UPDATE nav_ext_links SET label=?,url=?,group_label=? WHERE id=?")->execute([$label,$url,$group,$id]);
        jok();
        break;

    case 'delete_nav':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jerr('id required');
        $db->prepare("DELETE FROM nav_ext_links WHERE id=?")->execute([$id]);
        jok();
        break;

    case 'move_nav':
        $id  = (int)($body['id']  ?? 0);
        $dir = (int)($body['dir'] ?? 0);
        if (!$id || !in_array($dir,[-1,1])) { jok(); break; }
        $rows = $db->query("SELECT id,sort_ord FROM nav_ext_links ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
        $idx  = array_search($id, array_column($rows,'id'));
        if ($idx === false) { jok(); break; }
        $sw = $idx + $dir;
        if ($sw < 0 || $sw >= count($rows)) { jok(); break; }
        $a = $rows[$idx]['sort_ord']; $b = $rows[$sw]['sort_ord'];
        if ($a === $b) $b = $a + ($dir > 0 ? 1 : -1);
        $db->prepare("UPDATE nav_ext_links SET sort_ord=? WHERE id=?")->execute([$b,$rows[$idx]['id']]);
        $db->prepare("UPDATE nav_ext_links SET sort_ord=? WHERE id=?")->execute([$a,$rows[$sw]['id']]);
        jok();
        break;

    // ── CORE ORDER ────────────────────────────────────────────────────────────
    case 'move_core':
        $key = preg_replace('/[^a-z0-9_]/', '', $body['key'] ?? '');
        $dir = (int)($body['dir'] ?? 0);
        if (!$key || !in_array($dir,[-1,1])) { jok(); break; }
        $rows = $db->query("SELECT key,sort_ord FROM nav_core_order ORDER BY sort_ord")->fetchAll(PDO::FETCH_ASSOC);
        $idx  = array_search($key, array_column($rows,'key'));
        if ($idx === false) { jok(); break; }
        $sw = $idx + $dir;
        if ($sw < 0 || $sw >= count($rows)) { jok(); break; }
        $a = $rows[$idx]['sort_ord']; $b = $rows[$sw]['sort_ord'];
        if ($a === $b) $b = $a + ($dir > 0 ? 1 : -1);
        $db->prepare("INSERT OR REPLACE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute([$rows[$idx]['key'],$b]);
        $db->prepare("INSERT OR REPLACE INTO nav_core_order (key,sort_ord) VALUES (?,?)")->execute([$rows[$sw]['key'],$a]);
        jok();
        break;

    // ── MC RESOURCE LINKS ─────────────────────────────────────────────────────
    case 'add_mc':
        $slug  = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($body['mc_slug'] ?? '')));
        $label = trim($body['label'] ?? '');
        $url   = trim($body['url']   ?? '') ?: '#';
        if (!$slug || !$label) jerr('mc_slug and label required');
        $s = $db->prepare("SELECT COALESCE(MAX(sort_ord),0)+10 FROM mc_resource_links WHERE mc_slug=?");
        $s->execute([$slug]); $max = (int)$s->fetchColumn();
        $db->prepare("INSERT INTO mc_resource_links (mc_slug,label,url,sort_ord) VALUES (?,?,?,?)")
           ->execute([$slug,$label,$url,$max]);
        $id = (int)$db->lastInsertId();
        jok(['item'=>['id'=>$id,'mc_slug'=>$slug,'label'=>$label,'url'=>$url]]);
        break;

    case 'update_mc':
        $id    = (int)($body['id']    ?? 0);
        $label = trim($body['label']  ?? '');
        $url   = trim($body['url']    ?? '') ?: '#';
        if (!$id || !$label) jerr('id and label required');
        $db->prepare("UPDATE mc_resource_links SET label=?,url=? WHERE id=?")->execute([$label,$url,$id]);
        jok();
        break;

    case 'delete_mc':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jerr('id required');
        $db->prepare("DELETE FROM mc_resource_links WHERE id=?")->execute([$id]);
        jok();
        break;

    case 'move_mc':
        $id   = (int)($body['id']   ?? 0);
        $slug = preg_replace('/[^a-z0-9\-]/', '', $body['mc_slug'] ?? '');
        $dir  = (int)($body['dir']  ?? 0);
        if (!$id || !$slug || !in_array($dir,[-1,1])) { jok(); break; }
        $s = $db->prepare("SELECT id,sort_ord FROM mc_resource_links WHERE mc_slug=? ORDER BY sort_ord,id");
        $s->execute([$slug]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $idx = array_search($id, array_column($rows,'id'));
        if ($idx === false) { jok(); break; }
        $sw = $idx + $dir;
        if ($sw < 0 || $sw >= count($rows)) { jok(); break; }
        $a = $rows[$idx]['sort_ord']; $b = $rows[$sw]['sort_ord'];
        if ($a === $b) $b = $a + ($dir > 0 ? 1 : -1);
        $db->prepare("UPDATE mc_resource_links SET sort_ord=? WHERE id=?")->execute([$b,$rows[$idx]['id']]);
        $db->prepare("UPDATE mc_resource_links SET sort_ord=? WHERE id=?")->execute([$a,$rows[$sw]['id']]);
        jok();
        break;

    default:
        jerr('Unknown action');
}
