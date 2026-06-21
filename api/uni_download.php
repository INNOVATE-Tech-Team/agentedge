<?php
// Stream a university lesson file (video or doc) from data/uni/.
// Supports HTTP Range headers so video seeking works in the browser.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';

$me = current_agent();
if (!$me) { http_response_code(401); exit; }

$db = local_db();

// Thumbnail mode: ?thumb=1&course_id=X
if (!empty($_GET['thumb'])) {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo 'Bad request'; exit; }
    $cs = $db->prepare("SELECT thumb_key, published FROM uni_courses WHERE id=?");
    $cs->execute([$courseId]);
    $course = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$course || !$course['thumb_key']) { http_response_code(404); echo 'Not found'; exit; }
    if (!$course['published'] && !is_admin()) { http_response_code(403); echo 'Forbidden'; exit; }
    $path = __DIR__ . '/../data/uni/' . $course['thumb_key'];
    $mime = mime_content_type($path) ?: 'image/jpeg';
    header("Content-Type: $mime");
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . filesize($path));
    readfile($path);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$s  = $db->prepare("SELECT l.*, c.published FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?");
$s->execute([$id]);
$lesson = $s->fetch(PDO::FETCH_ASSOC);
if (!$lesson || !$lesson['file_key']) { http_response_code(404); echo 'Not found'; exit; }
if (!$lesson['published'] && !is_admin()) { http_response_code(403); echo 'Forbidden'; exit; }

$path = __DIR__ . '/../data/uni/' . $lesson['file_key'];
if (!file_exists($path)) { http_response_code(404); echo 'File not found'; exit; }

$mime = mime_content_type($path) ?: 'application/octet-stream';
$size = filesize($path);
$start = 0;
$end   = $size - 1;

// Range request support for video seeking
if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int)$m[1];
        $end   = $m[2] !== '' ? min((int)$m[2], $size - 1) : $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

$disp = str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/') ? 'inline' : 'attachment';
header("Content-Type: $mime");
header("Content-Length: " . ($end - $start + 1));
header("Accept-Ranges: bytes");
header("Content-Disposition: $disp");
header("Cache-Control: private");

$fp = fopen($path, 'rb');
fseek($fp, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fp)) {
    $chunk = min(65536, $remaining);
    echo fread($fp, $chunk);
    $remaining -= $chunk;
    if (connection_aborted()) break;
}
fclose($fp);
