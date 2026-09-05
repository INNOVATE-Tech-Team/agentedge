<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }
$db = local_db();

$lessonId = (int)($_GET['lesson_id'] ?? 0);
if (!$lessonId) { header('Location: admin_university.php'); exit; }

$ls = $db->prepare(
    "SELECT l.*, c.id as course_id, c.title as course_title
     FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?"
);
$ls->execute([$lessonId]);
$lesson = $ls->fetch(PDO::FETCH_ASSOC);
if (!$lesson) { header('Location: admin_university.php'); exit; }

$uploads  = [];
$answers  = [];
// Feedback: aggregates (Overview), open-ended list, response roster, and an
// optional single-response detail (?response_id=). All read-only, all scoped
// to this lesson_id -- no writes anywhere on this page for any lesson type.
$fbQuestions   = [];       // id => question row (ordered by sort_ord)
$fbAgg         = [];       // question_id => ['avg'=>float|null,'answered'=>int,'na'=>int,'dist'=>[value=>count]]
$fbOpenEnded   = [];       // question_id => [ {agent_email,name,value_text,submitted_at}, ... ]
$fbResponses   = [];       // response rows, newest first
$fbCounts      = ['submitted' => 0, 'draft' => 0];
$fbNames       = [];       // agent_email => display name
$fbView        = null;     // single response detail: ['response'=>row, 'answers'=>[question_id=>answer row]]
if ($lesson['type'] === 'upload') {
    $us = $db->prepare("SELECT * FROM uni_learner_uploads WHERE lesson_id=? ORDER BY submitted_at DESC");
    $us->execute([$lessonId]);
    $uploads = $us->fetchAll(PDO::FETCH_ASSOC);
} elseif ($lesson['type'] === 'quiz') {
    $as = $db->prepare(
        "SELECT qa.*, q.question, q.qtype, q.options
         FROM uni_quiz_answers qa
         JOIN uni_questions q ON q.id=qa.question_id
         WHERE qa.lesson_id=?
         ORDER BY qa.agent_email, q.sort_ord, q.id"
    );
    $as->execute([$lessonId]);
    $answers = $as->fetchAll(PDO::FETCH_ASSOC);
} elseif ($lesson['type'] === 'feedback') {
    $fq = $db->prepare("SELECT * FROM uni_feedback_questions WHERE lesson_id=? ORDER BY sort_ord,id");
    $fq->execute([$lessonId]);
    foreach ($fq->fetchAll(PDO::FETCH_ASSOC) as $q) { $fbQuestions[(int)$q['id']] = $q; }

    $rs = $db->prepare("SELECT * FROM uni_feedback_responses WHERE lesson_id=? ORDER BY COALESCE(submitted_at, started_at) DESC");
    $rs->execute([$lessonId]);
    $fbResponses = $rs->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fbResponses as $r) { $fbCounts[$r['status']] = ($fbCounts[$r['status']] ?? 0) + 1; }

    // One grouped query covers every rating_5/scale_10 question's full distribution --
    // not one query per question.
    $ratingIds = array_keys(array_filter($fbQuestions, fn($q) => in_array($q['qtype'], ['rating_5', 'scale_10'], true)));
    if ($ratingIds) {
        $ph = implode(',', array_fill(0, count($ratingIds), '?'));
        $ra = $db->prepare(
            "SELECT a.question_id, a.value_number, a.is_na, COUNT(*) as cnt
             FROM uni_feedback_answers a JOIN uni_feedback_responses r ON r.id = a.response_id
             WHERE a.question_id IN ($ph) AND r.status = 'submitted'
             GROUP BY a.question_id, a.value_number, a.is_na"
        );
        $ra->execute($ratingIds);
        foreach ($ratingIds as $qid) { $fbAgg[$qid] = ['sum' => 0, 'answered' => 0, 'na' => 0, 'dist' => []]; }
        foreach ($ra->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = (int)$row['question_id']; $cnt = (int)$row['cnt'];
            if (!empty($row['is_na'])) { $fbAgg[$qid]['na'] += $cnt; continue; }
            if ($row['value_number'] === null) continue; // shouldn't happen for these qtypes, but never divide by a phantom bucket
            $v = (int)$row['value_number'];
            $fbAgg[$qid]['dist'][$v] = ($fbAgg[$qid]['dist'][$v] ?? 0) + $cnt;
            $fbAgg[$qid]['sum'] += $v * $cnt;
            $fbAgg[$qid]['answered'] += $cnt;
        }
        foreach ($fbAgg as $qid => &$agg) { $agg['avg'] = $agg['answered'] > 0 ? $agg['sum'] / $agg['answered'] : null; }
        unset($agg);
    }

    // Open-Ended: non-intro short_text/long_text/date questions only (adjustment 1) --
    // Agent Name/Cohort/Facilitator/Graduation Date/Market Center stay out of this tab
    // even though they share these same qtypes, because is_intro_field=1 excludes them.
    $textIds = array_keys(array_filter($fbQuestions, fn($q) => !$q['is_intro_field'] && in_array($q['qtype'], ['short_text', 'long_text', 'date'], true)));
    if ($textIds) {
        $ph = implode(',', array_fill(0, count($textIds), '?'));
        $oe = $db->prepare(
            "SELECT a.question_id, a.value_text, r.agent_email, r.submitted_at
             FROM uni_feedback_answers a JOIN uni_feedback_responses r ON r.id = a.response_id
             WHERE a.question_id IN ($ph) AND r.status = 'submitted' AND a.value_text IS NOT NULL AND a.value_text != ''
             ORDER BY r.submitted_at"
        );
        $oe->execute($textIds);
        foreach ($oe->fetchAll(PDO::FETCH_ASSOC) as $row) { $fbOpenEnded[(int)$row['question_id']][] = $row; }
    }

    // Single-response detail. response_id is validated against THIS lesson_id -- a
    // mismatched pair (wrong lesson, tampered URL) just finds nothing, never another
    // lesson's data.
    $responseId = (int)($_GET['response_id'] ?? 0);
    if ($responseId) {
        $vr = $db->prepare("SELECT * FROM uni_feedback_responses WHERE id=? AND lesson_id=?");
        $vr->execute([$responseId, $lessonId]);
        $vrRow = $vr->fetch(PDO::FETCH_ASSOC);
        if ($vrRow) {
            $va = $db->prepare("SELECT * FROM uni_feedback_answers WHERE response_id=?");
            $va->execute([$responseId]);
            $vAnswers = [];
            foreach ($va->fetchAll(PDO::FETCH_ASSOC) as $a) { $vAnswers[(int)$a['question_id']] = $a; }
            $fbView = ['response' => $vrRow, 'answers' => $vAnswers];
        }
    }

    // Batched agent-name resolution -- one query for every distinct email on this
    // page (responses list + open-ended attributions), never one lookup per row.
    $emails = array_unique(array_merge(
        array_column($fbResponses, 'agent_email'),
        $fbView ? [$fbView['response']['agent_email']] : []
    ));
    if ($emails) {
        $ph = implode(',', array_fill(0, count($emails), '?'));
        foreach (db_query_safe("SELECT email, firstname, lastname FROM tblstaff WHERE email IN ($ph)", $emails) as $row) {
            $fbNames[strtolower($row['email'])] = trim($row['firstname'] . ' ' . $row['lastname']) ?: $row['email'];
        }
    }
    function fb_display_name(string $email, array $names): string { return $names[strtolower($email)] ?? $email; }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($lesson['title']) ?> — Submissions — University Admin</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .back-link{font-size:12px;color:#5b8e0d;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:4px;margin-bottom:16px}
    .back-link:hover{text-decoration:underline}
    table.sub-table{width:100%;border-collapse:collapse;font-size:13px}
    table.sub-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#888;padding:8px 12px;border-bottom:2px solid #eee}
    table.sub-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
    table.sub-table tr:last-child td{border-bottom:none}
    .dl-btn{padding:5px 12px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333;text-decoration:none;display:inline-block}
    .dl-btn:hover{border-color:#82C112;color:#5b8e0d}
    .empty{text-align:center;color:#bbb;padding:40px;font-size:13px;border:1px dashed #eee;border-radius:8px}
    .agent-email{font-weight:700;color:#111}
    .qtype-tag{font-size:10px;color:#aaa;font-weight:700;text-transform:uppercase}
    /* Feedback results */
    .tabs{display:flex;gap:4px;border-bottom:1px solid #eee;margin-bottom:18px}
    .tab{padding:10px 20px;font-size:13px;font-weight:700;color:#888;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;background:none;border-top:none;border-left:none;border-right:none}
    .tab.active{color:#82C112;border-bottom-color:#82C112}
    .tab-panel{display:none}.tab-panel.active{display:block}
    .fb-count-row{display:flex;gap:24px;margin-bottom:24px}
    .fb-count{background:#f9f9f9;border-radius:8px;padding:14px 20px;min-width:120px}
    .fb-count-num{font-size:24px;font-weight:900;color:#111}
    .fb-count-label{font-size:11px;color:#888;text-transform:uppercase;font-weight:700;letter-spacing:.04em;margin-top:2px}
    .fb-q-card{border:1px solid #eee;border-radius:8px;padding:16px 20px;margin-bottom:14px}
    .fb-q-section{font-size:10px;font-weight:700;color:#82C112;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
    .fb-q-text{font-size:13px;font-weight:700;color:#111;margin-bottom:10px}
    .fb-q-avg{font-size:20px;font-weight:900;color:#111}
    .fb-q-avg-label{font-size:11px;color:#888;margin-bottom:10px}
    .fb-dist-row{display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:3px}
    .fb-dist-label{width:38px;color:#666;font-weight:700;flex-shrink:0}
    .fb-dist-bar-wrap{flex:1;background:#f2f2f2;border-radius:3px;height:14px;overflow:hidden}
    .fb-dist-bar{background:#82C112;height:100%}
    .fb-dist-count{width:32px;text-align:right;color:#888;flex-shrink:0}
    .fb-na-note{font-size:11px;color:#aaa;margin-top:8px}
    .fb-oe-item{border-bottom:1px solid #f0f0f0;padding:12px 0}
    .fb-oe-item:last-child{border-bottom:none}
    .fb-oe-meta{font-size:11px;color:#888;margin-top:6px}
    .fb-status-tag{font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px}
    .fb-status-submitted{background:#e8f5e9;color:#2e7d32}
    .fb-status-draft{background:#fff3cd;color:#856404}
    .fb-view-header{font-size:15px;font-weight:800;color:#111;margin-bottom:2px}
    .fb-view-field{margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #f5f5f5}
    .fb-view-field:last-child{border-bottom:none}
    .fb-view-q{font-size:12px;font-weight:700;color:#555;margin-bottom:6px}
    .fb-view-a{font-size:13px;color:#111}
    .fb-view-a.na,.fb-view-a.unanswered{color:#aaa;font-style:italic}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_university', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Submissions</div>
    </header>
    <main class="wrap">
      <a class="back-link" href="admin_university_course.php?id=<?= (int)$lesson['course_id'] ?>">← Back to <?= htmlspecialchars($lesson['course_title']) ?></a>

      <div class="card" style="padding:20px 24px">
        <div style="font-size:14px;font-weight:800;color:#111;margin-bottom:4px"><?= htmlspecialchars($lesson['title']) ?></div>
        <div style="font-size:12px;color:#888;margin-bottom:18px">
          <?php if ($lesson['type'] === 'upload'): ?>Learner file submissions
          <?php elseif ($lesson['type'] === 'quiz'): ?>Quiz responses (open-ended and selected answers)
          <?php elseif ($lesson['type'] === 'feedback'): ?>Feedback results
          <?php endif; ?>
        </div>

        <?php if ($lesson['type'] === 'upload'): ?>
          <?php if (!$uploads): ?>
          <div class="empty">No submissions yet.</div>
          <?php else: ?>
          <table class="sub-table">
            <thead><tr><th>Agent</th><th>File</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($uploads as $u): ?>
              <tr>
                <td class="agent-email"><?= htmlspecialchars($u['agent_email']) ?></td>
                <td><?= htmlspecialchars($u['original_name']) ?></td>
                <td><?= fmt_dt_et($u['submitted_at']) ?></td>
                <td><a class="dl-btn" href="api/uni_download.php?submission=<?= (int)$u['id'] ?>" target="_blank">Download</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>

        <?php elseif ($lesson['type'] === 'quiz'): ?>
          <?php if (!$answers): ?>
          <div class="empty">No responses recorded yet.</div>
          <?php else: ?>
          <table class="sub-table">
            <thead><tr><th>Agent</th><th>Question</th><th>Response</th><th>Submitted</th></tr></thead>
            <tbody>
              <?php foreach ($answers as $a):
                $opts = json_decode($a['options'] ?? '[]', true) ?: [];
                if ($a['qtype'] === 'text') {
                    $resp = nl2br(htmlspecialchars($a['answer_text']));
                } else {
                    $sel = json_decode($a['selected_indexes'] ?? '[]', true) ?: [];
                    $resp = htmlspecialchars(implode(', ', array_map(fn($i) => $opts[$i] ?? '?', $sel)));
                }
              ?>
              <tr>
                <td class="agent-email"><?= htmlspecialchars($a['agent_email']) ?></td>
                <td><?= htmlspecialchars($a['question']) ?><br><span class="qtype-tag"><?= htmlspecialchars($a['qtype']) ?></span></td>
                <td><?= $resp ?></td>
                <td><?= fmt_dt_et($a['submitted_at']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>

        <?php elseif ($lesson['type'] === 'feedback'): ?>
          <?php if ($fbView): ?>
          <a class="back-link" href="admin_university_submissions.php?lesson_id=<?= $lessonId ?>">← Back to Responses</a>
          <div class="fb-view-header"><?= fb_display_name($fbView['response']['agent_email'], $fbNames) ?></div>
          <div style="font-size:12px;color:#888;margin-bottom:6px"><?= htmlspecialchars($fbView['response']['agent_email']) ?></div>
          <?php if ($fbView['response']['status'] === 'submitted'): ?>
          <span class="fb-status-tag fb-status-submitted">Submitted</span> <span style="font-size:12px;color:#888">on <?= fmt_dt_et($fbView['response']['submitted_at']) ?></span>
          <?php else: ?>
          <span class="fb-status-tag fb-status-draft">Draft — in progress</span> <span style="font-size:12px;color:#888">started <?= fmt_dt_et($fbView['response']['started_at']) ?></span>
          <?php endif; ?>
          <div style="margin-top:24px">
            <?php foreach ($fbQuestions as $qid => $q):
              $a = $fbView['answers'][$qid] ?? null;
              $cfg = json_decode($q['config'] ?? '{}', true) ?: [];
            ?>
            <div class="fb-view-field">
              <div class="fb-view-q"><?php if ($q['section_label']): ?><?= htmlspecialchars($q['section_label']) ?> — <?php endif; ?><?= htmlspecialchars($q['question']) ?></div>
              <?php if ($q['qtype'] === 'rating_5'): ?>
                <?php if (!$a): ?><div class="fb-view-a unanswered">Not answered</div>
                <?php elseif (!empty($a['is_na'])): ?><div class="fb-view-a na"><?= htmlspecialchars($cfg['na_label'] ?? 'N/A') ?></div>
                <?php else: ?><div class="fb-view-a"><?= (int)$a['value_number'] ?> / 5<?php $lbl = trim($cfg['labels'][(string)$a['value_number']] ?? ''); if ($lbl): ?> — <?= htmlspecialchars($lbl) ?><?php endif; ?></div>
                <?php endif; ?>
              <?php elseif ($q['qtype'] === 'scale_10'): ?>
                <?php if (!$a || $a['value_number'] === null): ?><div class="fb-view-a unanswered">Not answered</div>
                <?php else: ?><div class="fb-view-a"><?= (int)$a['value_number'] ?> / 10</div>
                <?php endif; ?>
              <?php else: /* short_text / long_text / date */ ?>
                <?php if (!$a || $a['value_text'] === null || $a['value_text'] === ''): ?><div class="fb-view-a unanswered">Not answered</div>
                <?php else: ?><div class="fb-view-a"><?= nl2br(htmlspecialchars($a['value_text'])) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <?php else: ?>
          <div class="tabs">
            <button class="tab active" onclick="switchTab('overview',this)">Overview</button>
            <button class="tab" onclick="switchTab('openended',this)">Open-Ended</button>
            <button class="tab" onclick="switchTab('responses',this)">Responses</button>
          </div>

          <div class="tab-panel active" id="tab-overview">
            <div class="fb-count-row">
              <div class="fb-count"><div class="fb-count-num"><?= $fbCounts['submitted'] ?? 0 ?></div><div class="fb-count-label">Submitted</div></div>
              <div class="fb-count"><div class="fb-count-num"><?= $fbCounts['draft'] ?? 0 ?></div><div class="fb-count-label">Draft / In Progress</div></div>
            </div>
            <?php if (!$fbAgg): ?>
            <div class="empty">No rating or scale questions on this form.</div>
            <?php else: ?>
            <?php foreach ($fbQuestions as $qid => $q):
              if (!isset($fbAgg[$qid])) continue;
              $agg = $fbAgg[$qid];
              $isRating = $q['qtype'] === 'rating_5';
              $range = $isRating ? [1,2,3,4,5] : range(1,10);
              $maxCount = max(array_merge([1], array_values($agg['dist'])));
              $qCfg = json_decode($q['config'] ?? '{}', true) ?: [];
            ?>
            <div class="fb-q-card">
              <?php if ($q['section_label']): ?><div class="fb-q-section"><?= htmlspecialchars($q['section_label']) ?></div><?php endif; ?>
              <div class="fb-q-text"><?= htmlspecialchars($q['question']) ?></div>
              <div class="fb-q-avg"><?= $agg['avg'] !== null ? number_format($agg['avg'], 1) : '—' ?></div>
              <div class="fb-q-avg-label">average of <?= $agg['answered'] ?> answered / <?= $fbCounts['submitted'] ?? 0 ?> submitted</div>
              <?php foreach ($range as $v): $c = $agg['dist'][$v] ?? 0; ?>
              <div class="fb-dist-row">
                <div class="fb-dist-label"><?= $v ?></div>
                <div class="fb-dist-bar-wrap"><div class="fb-dist-bar" style="width:<?= $maxCount > 0 ? round($c / $maxCount * 100) : 0 ?>%"></div></div>
                <div class="fb-dist-count"><?= $c ?></div>
              </div>
              <?php endforeach; ?>
              <?php if ($isRating && !empty($qCfg['allow_na'])): ?>
              <div class="fb-na-note"><?= htmlspecialchars($qCfg['na_label'] ?? 'N/A') ?>: <?= $agg['na'] ?></div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="tab-panel" id="tab-openended">
            <?php if (!$fbOpenEnded): ?>
            <div class="empty">No open-ended responses yet.</div>
            <?php else: ?>
            <?php foreach ($fbQuestions as $qid => $q):
              if (empty($fbOpenEnded[$qid])) continue;
            ?>
            <div class="fb-q-card">
              <?php if ($q['section_label']): ?><div class="fb-q-section"><?= htmlspecialchars($q['section_label']) ?></div><?php endif; ?>
              <div class="fb-q-text"><?= htmlspecialchars($q['question']) ?></div>
              <?php foreach ($fbOpenEnded[$qid] as $row): ?>
              <div class="fb-oe-item">
                <div><?= nl2br(htmlspecialchars($row['value_text'])) ?></div>
                <div class="fb-oe-meta"><?= fb_display_name($row['agent_email'], $fbNames) ?> · <?= fmt_dt_et($row['submitted_at']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="tab-panel" id="tab-responses">
            <?php if (!$fbResponses): ?>
            <div class="empty">No responses yet.</div>
            <?php else: ?>
            <table class="sub-table">
              <thead><tr><th>Agent</th><th>Status</th><th>Started</th><th>Submitted</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($fbResponses as $r): ?>
                <tr>
                  <td><span class="agent-email"><?= fb_display_name($r['agent_email'], $fbNames) ?></span><br><span style="font-size:11px;color:#aaa"><?= htmlspecialchars($r['agent_email']) ?></span></td>
                  <td><span class="fb-status-tag fb-status-<?= $r['status'] ?>"><?= $r['status'] === 'submitted' ? 'Submitted' : 'Draft' ?></span></td>
                  <td><?= fmt_dt_et($r['started_at']) ?></td>
                  <td><?= $r['submitted_at'] ? fmt_dt_et($r['submitted_at']) : '—' ?></td>
                  <td><a class="dl-btn" href="admin_university_submissions.php?lesson_id=<?= $lessonId ?>&response_id=<?= (int)$r['id'] ?>">View Response</a></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        <?php else: ?>
        <div class="empty">This lesson type doesn't have submissions to review.</div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<?php if ($lesson['type'] === 'feedback' && !$fbView): ?>
<script>
function switchTab(name, el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
}
</script>
<?php endif; ?>
</body>
</html>
