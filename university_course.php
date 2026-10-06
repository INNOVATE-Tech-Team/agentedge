<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/uni_feedback.php';
$agent = require_login();
$db    = local_db();
$email = $agent['email'];

$courseId = (int)($_GET['id'] ?? 0);
if (!$courseId) { header('Location: university.php'); exit; }

$cs = $db->prepare(
    "SELECT c.*, COALESCE(cat.name,'Uncategorized') as cat_name, COALESCE(cat.icon,'📚') as cat_icon
     FROM uni_courses c LEFT JOIN uni_categories cat ON cat.id=c.category_id WHERE c.id=?"
);
$cs->execute([$courseId]);
$course = $cs->fetch(PDO::FETCH_ASSOC);
if (!$course || (!$course['published'] && !is_admin())) { header('Location: university.php'); exit; }

// Enforce access control for non-admins
if (!is_admin()) {
    // Invite-only check
    if (!empty($course['invite_only'])) {
        $inv = $db->prepare("SELECT 1 FROM uni_course_invites WHERE course_id=? AND LOWER(agent_email)=?");
        $inv->execute([$courseId, strtolower($email)]);
        if (!$inv->fetchColumn()) { header('Location: university.php'); exit; }
    }
    // State filter check
    $sf = json_decode($course['state_filter'] ?? '[]', true);
    if (!empty($sf)) {
        $aiRow = $db->prepare("SELECT mc.state_code FROM agent_intake ai LEFT JOIN market_centers mc ON mc.slug=ai.office_location OR LOWER(mc.name)=LOWER(ai.office_location) WHERE LOWER(ai.email)=? LIMIT 1");
        $aiRow->execute([strtolower($email)]);
        $stateCode = ($aiRow->fetch(PDO::FETCH_ASSOC))['state_code'] ?? null;
        if (!$stateCode || !in_array($stateCode, $sf, true)) { header('Location: university.php'); exit; }
    }
    // Role filter check
    $rf = json_decode($course['role_filter'] ?? '[]', true);
    if (!empty($rf) && !array_intersect(my_roles(), $rf)) { header('Location: university.php'); exit; }
}

// Lessons — non-admins never see a lesson still awaiting review (pending_review),
// regardless of the course's own published state.
$ls = $db->prepare("SELECT * FROM uni_lessons WHERE course_id=?" . (is_admin() ? "" : " AND pending_review=0") . " ORDER BY sort_ord,id");
$ls->execute([$courseId]);
$lessons = $ls->fetchAll(PDO::FETCH_ASSOC);

// Folders
$fs = $db->prepare("SELECT * FROM uni_folders WHERE course_id=? ORDER BY sort_ord,id");
$fs->execute([$courseId]);
$folders = $fs->fetchAll(PDO::FETCH_ASSOC);
$lessonsByFolder = [];
foreach ($lessons as $lesson) { $lessonsByFolder[$lesson['folder_id'] ?: 0][] = $lesson; }

// Exact render order (folders in order, then ungrouped) -- drives the on-demand hero's
// prev/next, "Lesson X of Y", and rail row numbering. Computed once, up front, so both the
// rail and the lesson-body loop (which render in that order) agree on each lesson's position
// without either one depending on the other having run first.
$heroLessonOrder = [];
foreach ($folders as $folder) { foreach ($lessonsByFolder[$folder['id']] ?? [] as $lesson) $heroLessonOrder[] = (int)$lesson['id']; }
foreach ($lessonsByFolder[0] ?? [] as $lesson) $heroLessonOrder[] = (int)$lesson['id'];
$heroLessonIndex = array_flip($heroLessonOrder); // lesson id -> 0-based position

// This agent's progress
$progressMap = [];
if ($lessons) {
    $lessonIds    = array_column($lessons, 'id');
    $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
    $ps = $db->prepare("SELECT lesson_id, score, completed_at, attempts FROM uni_progress WHERE agent_email=? AND lesson_id IN ($placeholders)");
    $ps->execute(array_merge([$email], $lessonIds));
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) $progressMap[$r['lesson_id']] = $r;
}

// Cert
$certQ = $db->prepare("SELECT cert_code, issued_at, expires_at FROM uni_certs WHERE agent_email=? AND course_id=?");
$certQ->execute([$email, $courseId]);
$cert = $certQ->fetch(PDO::FETCH_ASSOC);

// Placeholders don't count toward completion or the cert
$gradableLessons = array_values(array_filter($lessons, fn($l) => $l['type'] !== 'placeholder'));
$totalLessons = count($gradableLessons);
$doneLessons  = count(array_filter($gradableLessons, fn($l) => isset($progressMap[$l['id']])));
$pct          = $totalLessons > 0 ? round($doneLessons / $totalLessons * 100) : 0;

// "Lesson X of Y" display numbering (on-demand hero only) -- X must be drawn from the same
// gradable set as Y ($totalLessons above), or a placeholder (excluded from that set) can
// produce an impossible "Lesson 5 of 4". A placeholder displays the same number as the
// gradable lesson immediately before it (0 if it's before any gradable lesson).
$gradableIdSet  = array_flip(array_column($gradableLessons, 'id'));
$heroDisplayNum = [];
$gradableSeen   = 0;
foreach ($heroLessonOrder as $lid) {
    if (isset($gradableIdSet[$lid])) $gradableSeen++;
    $heroDisplayNum[$lid] = $gradableSeen;
}

// Find first incomplete lesson for "Continue" button, and the folder it lives
// in so that folder can default to open (rather than always folder index 0).
$firstIncomplete = null;
$defaultOpenFolderId = null;
foreach ($gradableLessons as $lesson) {
    if (!isset($progressMap[$lesson['id']])) {
        $firstIncomplete = $lesson['id'];
        $defaultOpenFolderId = (int)($lesson['folder_id'] ?: 0);
        break;
    }
}
if ($defaultOpenFolderId === null) {
    // Nothing incomplete (course finished, or has no gradable lessons) — fall
    // back to whichever folder would normally come first.
    if ($folders) $defaultOpenFolderId = (int)$folders[0]['id'];
    elseif ($lessonsByFolder[0] ?? null) $defaultOpenFolderId = 0;
}

// ── On-demand hero landing view — presentational only, gated entirely on
// layout_style so manual courses (layout_style='standard') are completely
// unaffected and render through the exact same path as before.
$isHero = ($course['layout_style'] ?? 'standard') === 'on_demand_hero';

// CTA state: three labels by progress, per the learner-card spec. "Review
// Course" targets the first lesson overall (nothing left to resolve to via
// "first incomplete" once the course is done). Hero-only, but cheap to
// always compute.
if ($doneLessons === 0) {
    $ctaLabel = 'Start Course';
} elseif ($firstIncomplete) {
    $ctaLabel = 'Continue Course';
} else {
    $ctaLabel = 'Review Course';
}
$ctaTarget = $firstIncomplete ?: ($gradableLessons[0]['id'] ?? null);

// Hero time line — backed by the course-level admin-entered
// overview_estimated_minutes column, not a computed sum of remaining lesson
// durations. Hidden whenever the field is unset (0).
$estimatedMinutes   = (int)($course['overview_estimated_minutes'] ?? 0);
$showEstimate       = $estimatedMinutes > 0;
if ($showEstimate) {
    $h = intdiv($estimatedMinutes, 60);
    $m = $estimatedMinutes % 60;
    $remainingLabel = $h > 0 ? ($m > 0 ? "{$h} hr {$m} min" : "{$h} hr") : "{$m} min";
}

// ── Hero-only: per-lesson content data for the in-card course container.
// Mirrors university_lesson.php's own per-lesson queries exactly (same
// SELECT shapes, same exclusion of correct_index/correct_indexes from the
// client), just gathered for every lesson up front instead of one at a
// time. university_lesson.php itself is untouched -- manual courses keep
// using it exactly as today, and it stays reachable directly for on-demand
// courses too (bookmarks, admin preview).
$heroQuestionsByLesson   = [];
$heroAttachmentsByLesson = [];
$heroUploadByLesson      = [];
$heroFeedbackStepsByLesson    = [];
$heroFeedbackAnswersByLesson  = []; // lesson_id => [question_id => answer row]
$heroFeedbackStatusByLesson   = []; // lesson_id => 'draft'|'submitted'|null (no response yet)
$heroFeedbackStepByLesson     = []; // lesson_id => current_step
if ($isHero && $lessons) {
    $quizLessonIds     = array_column(array_filter($lessons, fn($l) => $l['type'] === 'quiz'), 'id');
    $fileLessonIds     = array_column(array_filter($lessons, fn($l) => in_array($l['type'], ['video','doc','upload'], true)), 'id');
    $uploadLessonIds   = array_column(array_filter($lessons, fn($l) => $l['type'] === 'upload'), 'id');
    $feedbackLessonIds = array_column(array_filter($lessons, fn($l) => $l['type'] === 'feedback'), 'id');

    if ($quizLessonIds) {
        $ph = implode(',', array_fill(0, count($quizLessonIds), '?'));
        $qs = $db->prepare("SELECT id, lesson_id, question, options, qtype FROM uni_questions WHERE lesson_id IN ($ph) ORDER BY lesson_id, sort_ord, id");
        $qs->execute($quizLessonIds);
        foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $q['options'] = json_decode($q['options'] ?? '[]', true) ?: [];
            $q['qtype']   = $q['qtype'] ?: 'single';
            $heroQuestionsByLesson[$q['lesson_id']][] = $q;
        }
    }
    if ($fileLessonIds) {
        $ph = implode(',', array_fill(0, count($fileLessonIds), '?'));
        $as = $db->prepare("SELECT id, lesson_id, original_name FROM uni_lesson_files WHERE lesson_id IN ($ph) ORDER BY lesson_id, sort_ord, id");
        $as->execute($fileLessonIds);
        foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $a) { $heroAttachmentsByLesson[$a['lesson_id']][] = $a; }
    }
    if ($uploadLessonIds) {
        $ph = implode(',', array_fill(0, count($uploadLessonIds), '?'));
        $us = $db->prepare("SELECT lesson_id, original_name, submitted_at FROM uni_learner_uploads WHERE agent_email=? AND lesson_id IN ($ph)");
        $us->execute(array_merge([$email], $uploadLessonIds));
        foreach ($us->fetchAll(PDO::FETCH_ASSOC) as $u) { $heroUploadByLesson[$u['lesson_id']] = $u; }
    }
    if ($feedbackLessonIds) {
        $ph = implode(',', array_fill(0, count($feedbackLessonIds), '?'));
        $fq = $db->prepare("SELECT * FROM uni_feedback_questions WHERE lesson_id IN ($ph) ORDER BY lesson_id, sort_ord, id");
        $fq->execute($feedbackLessonIds);
        $questionsByLesson = [];
        foreach ($fq->fetchAll(PDO::FETCH_ASSOC) as $q) { $questionsByLesson[$q['lesson_id']][] = $q; }
        foreach ($questionsByLesson as $lid => $qs) { $heroFeedbackStepsByLesson[$lid] = feedback_build_steps($qs); }

        $fr = $db->prepare("SELECT * FROM uni_feedback_responses WHERE agent_email=? AND lesson_id IN ($ph)");
        $fr->execute(array_merge([$email], $feedbackLessonIds));
        $responsesByLesson = [];
        foreach ($fr->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $responsesByLesson[$r['lesson_id']] = $r;
            $heroFeedbackStatusByLesson[$r['lesson_id']] = $r['status'];
            $heroFeedbackStepByLesson[$r['lesson_id']] = (int)$r['current_step'];
        }
        $responseIds = array_column($responsesByLesson, 'id');
        if ($responseIds) {
            $ph2 = implode(',', array_fill(0, count($responseIds), '?'));
            $fa = $db->prepare("SELECT * FROM uni_feedback_answers WHERE response_id IN ($ph2)");
            $fa->execute($responseIds);
            $answersByResponse = [];
            foreach ($fa->fetchAll(PDO::FETCH_ASSOC) as $a) { $answersByResponse[$a['response_id']][(int)$a['question_id']] = $a; }
            foreach ($responsesByLesson as $lid => $r) { $heroFeedbackAnswersByLesson[$lid] = $answersByResponse[$r['id']] ?? []; }
        }
    }
}

// Same embed-URL normalization as university_lesson.php, duplicated under a
// different name rather than shared, so that file needs zero changes.
function hero_make_embed_url(string $url): string {
    if (preg_match('/youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]+)/', $url, $m)) return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $m)) return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('/vimeo\.com\/(\d+)(?:\/([a-f0-9]+))?/', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1] . (!empty($m[2]) ? '?h=' . $m[2] : '');
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
    return $url;
}

// Renders one lesson's full content (video/doc/quiz/upload/placeholder/feedback) for
// the in-card course container. Every id/name is namespaced by lesson id
// (l{id}-...) so 30+ of these can sit on one page without colliding —
// unlike university_lesson.php, which only ever has one lesson on a page
// and can get away with plain ids. Content and completion logic are
// otherwise a direct port of that file's per-type blocks.
function render_hero_lesson_content(array $lesson, int $lessonNum, int $totalLessons, ?array $prog, array $questions, array $attachments, ?array $upload, array $feedbackSteps, array $feedbackAnswers, ?string $feedbackStatus, int $feedbackCurrentStep, array $agent): void {
    $lid = (int)$lesson['id'];
    $isComplete = $prog !== null;
    $typeLabels = ['video' => 'Video', 'doc' => 'Document', 'quiz' => 'Quiz', 'upload' => 'Upload', 'feedback' => 'Feedback'];
    $durSec     = (int)($lesson['duration_sec'] ?? 0);
    $durLabel   = '';
    if ($durSec > 0) {
        $dh = intdiv($durSec, 3600);
        $dm = intdiv($durSec % 3600, 60);
        $durLabel = $dh > 0 ? ($dm > 0 ? "{$dh}h {$dm}m" : "{$dh}h") : "{$dm} min";
    }
    $embedUrl   = !empty($lesson['embed_url']) ? hero_make_embed_url($lesson['embed_url']) : '';
    $isPdfDoc   = strtolower(pathinfo($lesson['file_key'] ?? '', PATHINFO_EXTENSION)) === 'pdf';
    ?>
    <div class="lc-section" id="lesson-<?= $lid ?>" data-lesson-id="<?= $lid ?>" data-lesson-type="<?= htmlspecialchars($lesson['type']) ?>">
      <div class="lc-header">
        <div class="lc-num">Lesson <?= $lessonNum ?> of <?= $totalLessons ?></div>
        <?php if (isset($typeLabels[$lesson['type']])): ?>
        <span class="lc-type-tag"><?= $typeLabels[$lesson['type']] ?><?= $durLabel ? ' · ' . $durLabel : '' ?></span>
        <?php endif; ?>
        <?php if ($isComplete): ?><span class="lc-done-tag">✓ Completed</span><?php endif; ?>
        <h1 class="lc-title-text"><?= htmlspecialchars($lesson['title']) ?></h1>
      </div>

      <?php if ($lesson['type'] === 'placeholder'): ?>
      <div class="placeholder-wrap">
        <div style="font-size:14px;font-weight:600;color:var(--od-text2)">This lesson isn't available yet</div>
      </div>

      <?php elseif ($lesson['type'] === 'video'): ?>
      <?php if ($embedUrl): ?>
      <div class="video-wrap lazy-media" data-src="<?= htmlspecialchars($embedUrl) ?>" style="padding-top:56.25%;position:relative">
        <div class="lazy-media-slot" style="position:absolute;top:0;left:0;width:100%;height:100%;background:#000;display:flex;align-items:center;justify-content:center;color:var(--od-dimmer);font-size:12px">Loading…</div>
      </div>
      <?php elseif ($lesson['file_key']): ?>
      <div class="video-wrap lazy-media">
        <video id="lvideo-<?= $lid ?>" controls preload="none" onended="heroOnVideoEnd(<?= $lid ?>)">
          <source data-src="api/uni_download.php?id=<?= $lid ?>" type="video/mp4">
        </video>
      </div>
      <?php else: ?>
      <!-- Quiet empty state, deliberately -- no warning iconography, no amber. -->
      <div class="video-wrap video-empty">
        <div class="doc-icon">▶</div>
        <div class="doc-title">Video not uploaded yet</div>
        <div class="video-empty-caption">MP4 OR VIMEO LINK · 16:9</div>
      </div>
      <?php endif; ?>
      <?php if ($lesson['content_html']): ?><div class="lc-content-html"><?= $lesson['content_html'] ?></div><?php endif; ?>
      <?php hero_render_attachments($attachments); ?>
      <div id="complete-area-<?= $lid ?>">
        <?php if ($isComplete): ?><span class="done-badge">✓ Lesson Complete</span>
        <?php else: ?><button class="mark-done-btn" id="mark-done-btn-<?= $lid ?>" onclick="heroMarkComplete(<?= $lid ?>)">Mark as Complete</button><?php endif; ?>
      </div>

      <?php elseif ($lesson['type'] === 'doc'): ?>
      <?php if ($lesson['file_key'] && $isPdfDoc): ?>
      <div class="pdf-wrap">
        <div class="pdf-toolbar">
          <div class="doc-title"><?= htmlspecialchars($lesson['title']) ?></div>
          <a class="doc-dl" href="api/uni_download.php?id=<?= $lid ?>&download=1" onclick="heroScheduleComplete(<?= $lid ?>)">⬇ Download</a>
        </div>
        <iframe class="pdf-frame lazy-media" data-src="api/uni_download.php?id=<?= $lid ?>" onload="heroScheduleComplete(<?= $lid ?>)"></iframe>
      </div>
      <?php elseif ($lesson['file_key']): ?>
      <div class="doc-wrap">
        <div class="doc-title"><?= htmlspecialchars($lesson['title']) ?></div>
        <a class="doc-dl" href="api/uni_download.php?id=<?= $lid ?>&download=1" target="_blank" onclick="heroScheduleComplete(<?= $lid ?>)">⬇ Open / Download</a>
      </div>
      <?php else: ?>
      <div class="doc-wrap">
        <div class="doc-title">Document not uploaded yet</div>
      </div>
      <?php endif; ?>
      <?php if ($lesson['content_html']): ?><div class="lc-content-html"><?= $lesson['content_html'] ?></div><?php endif; ?>
      <?php hero_render_attachments($attachments); ?>
      <div id="complete-area-<?= $lid ?>">
        <?php if ($isComplete): ?><span class="done-badge">✓ Lesson Complete</span>
        <?php else: ?><button class="mark-done-btn" id="mark-done-btn-<?= $lid ?>" onclick="heroMarkComplete(<?= $lid ?>)">Mark as Complete</button><?php endif; ?>
      </div>

      <?php elseif ($lesson['type'] === 'quiz'): ?>
      <?php if ($lesson['content_html']): ?><div class="lc-content-html"><?= $lesson['content_html'] ?></div><?php endif; ?>
      <div id="quiz-container-<?= $lid ?>">
        <?php if (!$questions): ?>
        <div style="color:var(--od-dim);text-align:center;padding:32px;font-size:13px">No questions have been added to this quiz yet.</div>
        <?php elseif ($isComplete): ?>
        <div class="quiz-result pass">
          <div class="qr-score"><?= $prog['score'] ?>%</div>
          <div class="qr-msg">You passed this quiz! <?php if (($prog['attempts'] ?? 1) > 1): ?>(Attempt <?= $prog['attempts'] ?>)<?php endif; ?></div>
        </div>
        <div id="complete-area-<?= $lid ?>"><span class="done-badge">✓ Quiz Passed</span></div>
        <?php else: ?>
        <div id="quiz-form-<?= $lid ?>">
          <div class="quiz-progress" id="quiz-progress-<?= $lid ?>">Question 1 of <?= count($questions) ?></div>
          <?php foreach ($questions as $qi => $q): $qtype = $q['qtype'] ?: 'single'; ?>
          <div class="question-card" id="qcard-<?= $lid ?>-<?= $qi ?>" style="<?= $qi > 0 ? 'display:none' : '' ?>">
            <div class="question-text"><?= htmlspecialchars($q['question']) ?></div>
            <?php if ($qtype === 'text'): ?>
            <textarea class="quiz-text-answer" id="qtext-<?= $lid ?>-<?= $qi ?>" rows="4" placeholder="Type your answer…" oninput="HERO_STATE[<?= $lid ?>].answers[<?= $qi ?>]=this.value"></textarea>
            <?php else: ?>
            <?php foreach ($q['options'] as $oi => $opt): ?>
            <label class="option-label" id="opt-<?= $lid ?>-<?= $qi ?>-<?= $oi ?>" onclick="heroSelectOption(<?= $lid ?>,<?= $qi ?>,<?= $oi ?>,this,'<?= $qtype ?>')">
              <input type="<?= $qtype === 'multiple' ? 'checkbox' : 'radio' ?>" name="l<?= $lid ?>q<?= $qi ?>" value="<?= $oi ?>">
              <span class="opt-letter"><?= chr(65 + $oi) ?></span>
              <span class="opt-label-text"><?= htmlspecialchars($opt) ?></span>
              <span class="opt-selected-tag">SELECTED</span>
            </label>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <div class="quiz-nav">
            <button class="lesson-nav-btn" id="quiz-prev-<?= $lid ?>" onclick="heroQuizNav(<?= $lid ?>,-1)" style="display:none">← Previous</button>
            <span class="quiz-nav-helper" style="margin-left:auto">You can retake this check as many times as you need.</span>
            <button class="lesson-nav-btn" id="quiz-next-<?= $lid ?>" onclick="heroQuizNav(<?= $lid ?>,1)" style="<?= count($questions) <= 1 ? 'display:none' : '' ?>">Next question →</button>
            <button class="lesson-nav-btn primary" id="quiz-submit-<?= $lid ?>" onclick="heroSubmitQuiz(<?= $lid ?>)" style="<?= count($questions) <= 1 ? '' : 'display:none' ?>">Submit Quiz</button>
          </div>
        </div>
        <div id="quiz-result-<?= $lid ?>" style="display:none"></div>
        <div id="complete-area-<?= $lid ?>"></div>
        <?php endif; ?>
      </div>
      <?php if ($questions): ?>
      <script>HERO_STATE[<?= $lid ?>] = {
        answers: <?= json_encode(array_map(fn($q) => $q['qtype'] === 'multiple' ? [] : ($q['qtype'] === 'text' ? '' : null), $questions)) ?>,
        currentQ: 0, totalQ: <?= count($questions) ?>,
        qtypes: <?= json_encode(array_map(fn($q) => $q['qtype'] ?: 'single', $questions)) ?>
      };</script>
      <?php endif; ?>

      <?php elseif ($lesson['type'] === 'upload'): ?>
      <?php if ($lesson['content_html']): ?><div class="lc-content-html"><?= $lesson['content_html'] ?></div><?php endif; ?>
      <?php hero_render_attachments($attachments); ?>
      <?php if ($upload): ?>
      <div class="upload-submitted">
        <div class="upload-submitted-title">✓ Submitted</div>
        <div class="upload-submitted-sub"><?= htmlspecialchars($upload['original_name']) ?> — <?= fmt_dt_et($upload['submitted_at'], 'F j, Y g:ia') ?></div>
        <button class="lesson-nav-btn" onclick="document.getElementById('upload-input-<?= $lid ?>').click()">Re-upload</button>
      </div>
      <?php else: ?>
      <div class="upload-dropzone" onclick="document.getElementById('upload-input-<?= $lid ?>').click()" ondragover="event.preventDefault();this.classList.add('drag')" ondragleave="this.classList.remove('drag')" ondrop="heroHandleUploadDrop(event,<?= $lid ?>)">
        <p><strong>Click to upload</strong> or drag your file here</p>
      </div>
      <?php endif; ?>
      <input type="file" id="upload-input-<?= $lid ?>" style="display:none" onchange="heroSubmitLearnerUpload(<?= $lid ?>,this.files[0])">
      <div class="upload-status" id="upload-status-<?= $lid ?>" style="font-size:12px;color:var(--od-dim);min-height:18px"></div>
      <div id="complete-area-<?= $lid ?>"></div>

      <?php elseif ($lesson['type'] === 'feedback'): ?>
      <?php if ($feedbackStatus === 'submitted'): ?>
      <div class="fb-submitted">
        <div class="fb-submitted-title">✓ Submitted</div>
      </div>
      <div id="complete-area-<?= $lid ?>"><span class="done-badge">✓ Lesson Complete</span></div>
      <?php elseif (!$feedbackSteps): ?>
      <div style="color:var(--od-dim);text-align:center;padding:32px;font-size:13px">No questions have been added to this feedback form yet.</div>
      <?php else: ?>
      <div class="fb-progress" id="fb-progress-<?= $lid ?>">Step 1 of <?= count($feedbackSteps) ?></div>
      <?php foreach ($feedbackSteps as $si => $step): ?>
      <div class="fb-step" id="fb-step-<?= $lid ?>-<?= $si ?>" style="<?= $si > 0 ? 'display:none' : '' ?>">
        <?php if ($si === 0 && $lesson['content_html']): ?>
        <div class="lc-content-html"><?= $lesson['content_html'] ?></div>
        <?php endif; ?>
        <?php if ($step['type'] === 'intro'): ?>
          <?php foreach ($step['questions'] as $q): ?>
          <?php hero_render_feedback_field($q, $feedbackAnswers[(int)$q['id']] ?? null, $agent, true); ?>
          <?php endforeach; ?>
        <?php else: $q = $step['question']; ?>
          <?php if ($q['section_label']): ?><div class="fb-section-eyebrow"><?= htmlspecialchars($q['section_label']) ?></div><?php endif; ?>
          <div class="fb-question-text"><?= htmlspecialchars($q['question']) ?></div>
          <?php hero_render_feedback_field($q, $feedbackAnswers[(int)$q['id']] ?? null, $agent, false); ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div class="fb-nav">
        <button class="lesson-nav-btn" id="fb-prev-<?= $lid ?>" onclick="heroFbNav(<?= $lid ?>,-1)" style="display:none">← Previous</button>
        <button class="lesson-nav-btn primary" id="fb-next-<?= $lid ?>" onclick="heroFbNav(<?= $lid ?>,1)">Next →</button>
        <span class="fb-save-hint" id="fb-save-hint-<?= $lid ?>"></span>
      </div>
      <div id="complete-area-<?= $lid ?>"></div>
      <script>HERO_FB_STATE[<?= $lid ?>] = { step: <?= max(0, min($feedbackCurrentStep, count($feedbackSteps) - 1)) ?>, totalSteps: <?= count($feedbackSteps) ?>, pending: null };</script>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}

// Mirrors university_lesson.php's render_feedback_field() -- same field shapes
// and prefill rule (existing answer always wins over a configured prefill),
// namespaced by lesson id (fb-input-{lessonId}-{questionId}) since every
// lesson's markup coexists on one page here, unlike that file.
function hero_render_feedback_field(array $q, ?array $answer, array $agent, bool $showLabel): void {
    $qid   = (int)$q['id'];
    $lid   = (int)$q['lesson_id'];
    $cfg   = json_decode($q['config'] ?? '{}', true) ?: [];
    $qtype = $q['qtype'];
    $isNa        = $answer ? (int)($answer['is_na'] ?? 0) : 0;
    $valueNumber = $answer && $answer['value_number'] !== null ? (int)$answer['value_number'] : null;
    $valueText   = $answer['value_text'] ?? null;
    $inputId = "fb-input-{$lid}-{$qid}";
    ?>
    <div class="fb-field">
      <?php if ($showLabel): ?><label><?= htmlspecialchars($q['question']) ?></label><?php endif; ?>
      <?php if ($qtype === 'rating_5'): ?>
      <div class="fb-rating-row" id="<?= $inputId ?>">
        <?php for ($v = 1; $v <= 5; $v++): $label = trim($cfg['labels'][(string)$v] ?? ''); ?>
        <div class="fb-rating-opt<?= (!$isNa && $valueNumber === $v) ? ' selected' : '' ?>" data-value="<?= $v ?>" onclick="heroFbSelectRating(<?= $lid ?>,<?= $qid ?>,<?= $v ?>,false,this)">
          <span class="fb-rating-num"><?= $v ?></span><?= $label !== '' ? htmlspecialchars($label) : '' ?>
        </div>
        <?php endfor; ?>
        <?php if (!empty($cfg['allow_na'])): ?>
        <div class="fb-rating-opt<?= $isNa ? ' selected' : '' ?>" data-value="na" onclick="heroFbSelectRating(<?= $lid ?>,<?= $qid ?>,null,true,this)">
          <span class="fb-rating-num"><?= htmlspecialchars($cfg['na_label'] !== '' ? $cfg['na_label'] : 'N/A') ?></span>
        </div>
        <?php endif; ?>
      </div>
      <?php elseif ($qtype === 'scale_10'): ?>
      <div class="fb-scale-row" id="<?= $inputId ?>">
        <?php for ($v = 1; $v <= 10; $v++): ?>
        <div class="fb-scale-opt<?= $valueNumber === $v ? ' selected' : '' ?>" data-value="<?= $v ?>" onclick="heroFbSelectScale(<?= $lid ?>,<?= $qid ?>,<?= $v ?>,this)"><?= $v ?></div>
        <?php endfor; ?>
      </div>
      <?php if (!empty($cfg['low_label']) || !empty($cfg['high_label'])): ?>
      <div class="fb-scale-helpers"><span><?= htmlspecialchars($cfg['low_label'] ?? '') ?></span><span><?= htmlspecialchars($cfg['high_label'] ?? '') ?></span></div>
      <?php endif; ?>
      <?php else:
        $prefillKey = $cfg['prefill'] ?? '';
        $prefillVal = $prefillKey ? feedback_prefill_value($prefillKey, $agent) : '';
        $val = ($valueText !== null && $valueText !== '') ? $valueText : $prefillVal;
      ?>
      <?php $placeholder = $cfg['placeholder'] ?? ''; ?>
      <?php if ($qtype === 'short_text'): ?>
      <input type="text" id="<?= $inputId ?>" value="<?= htmlspecialchars($val) ?>" placeholder="<?= htmlspecialchars($placeholder) ?>" oninput="heroFbOnTextInput(<?= $lid ?>,<?= $qid ?>,this)" onblur="heroFbFlushPending(<?= $lid ?>)">
      <?php elseif ($qtype === 'long_text'): ?>
      <textarea id="<?= $inputId ?>" rows="4" placeholder="<?= htmlspecialchars($placeholder) ?>" oninput="heroFbOnTextInput(<?= $lid ?>,<?= $qid ?>,this)" onblur="heroFbFlushPending(<?= $lid ?>)"><?= htmlspecialchars($val) ?></textarea>
      <?php elseif ($qtype === 'date'): ?>
      <input type="date" id="<?= $inputId ?>" value="<?= htmlspecialchars($val) ?>" onchange="heroFbOnDateChange(<?= $lid ?>,<?= $qid ?>,this)">
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}

function hero_render_attachments(array $attachments): void {
    if (!$attachments) return;
    ?>
    <div class="attach-list">
      <?php foreach ($attachments as $att): ?>
      <a class="attach-item" href="api/uni_download.php?attachment=<?= (int)$att['id'] ?>" target="_blank">📎 <?= htmlspecialchars($att['original_name'] ?: 'Download file') ?></a>
      <?php endforeach; ?>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($course['title']) ?> — INNOVATE University</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .course-header{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:28px 32px;color:white;margin-bottom:20px;display:flex;gap:24px;align-items:flex-start}
    .course-header-thumb{width:140px;height:100px;border-radius:8px;object-fit:cover;flex-shrink:0;background:#333;display:flex;align-items:center;justify-content:center;font-size:40px;overflow:hidden}
    .course-header-thumb img{width:100%;height:100%;object-fit:cover}
    .course-header-meta{flex:1}
    .course-back{font-size:11px;color:rgba(255,255,255,.6);text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-bottom:8px}
    .course-back:hover{color:white}
    .course-header-cat{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#82C112;margin-bottom:6px}
    .course-header-title{font-size:20px;font-weight:900;margin:0 0 8px}
    .course-header-desc{font-size:13px;color:rgba(255,255,255,.75);margin:0 0 14px;line-height:1.5}
    .course-header-stats{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
    .ch-stat{font-size:12px;color:rgba(255,255,255,.7)}
    .ch-stat strong{color:white}
    .course-progress-bar{height:6px;background:rgba(255,255,255,.2);border-radius:3px;overflow:hidden;margin-top:10px}
    .course-progress-fill{height:100%;background:#82C112;border-radius:3px;transition:width 400ms}
    .course-header-cta{padding:10px 20px;background:#82C112;color:#000;font-weight:800;font-size:13px;border-radius:6px;text-decoration:none;white-space:nowrap;align-self:center}
    .course-header-cta:hover{background:#5b8e0d;color:#fff}
    .folder-list{display:flex;flex-direction:column;gap:14px;margin-bottom:14px}
    .folder-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .folder-header{width:100%;display:flex;align-items:center;gap:18px;padding:18px 22px;background:none;border:0;text-align:left;cursor:pointer;font:inherit;color:inherit;transition:background .12s ease}
    .folder-header:hover{background:#fcfcfa}
    .folder-header:focus-visible{outline:2px solid var(--green);outline-offset:-2px}
    .folder-code{width:34px;height:34px;flex:none;border-radius:7px;background:#eef5e2;display:flex;align-items:center;justify-content:center;font:700 12px/1 ui-monospace,Menlo,Monaco,Consolas,monospace;color:var(--green-d)}
    .folder-text{flex:1;display:flex;flex-direction:column;gap:5px;min-width:0}
    .folder-title{font:700 15.5px/1.2 inherit;color:#1c1c17}
    .folder-desc{font-size:12.5px;line-height:1.4;color:#8b8b82}
    .folder-progress{width:170px;flex:none;display:flex;flex-direction:column;gap:7px}
    .folder-count{font:400 11px/1 ui-monospace,Menlo,Monaco,Consolas,monospace;color:#9c9c94}
    .folder-track{height:5px;border-radius:3px;background:#ececE5;overflow:hidden}
    .folder-track-fill{height:5px;background:var(--green);border-radius:3px;transition:width .3s ease}
    .folder-caret{font-size:13px;color:#b4b4ab;transition:transform .15s ease}
    .folder-header[aria-expanded="true"] .folder-caret{transform:rotate(180deg)}
    .folder-body{border-top:1px solid #eeeee8;background:#fbfbf9;padding:8px 22px 14px}
    .folder-header[aria-expanded="false"] + .folder-body{display:none}
    .lesson-row{display:flex;align-items:center;gap:14px;padding:11px 4px;border-bottom:1px solid #f2f2ec;text-decoration:none;color:inherit}
    .lesson-row:last-child{border-bottom:0}
    .lesson-row:hover .lesson-title{color:var(--green-d)}
    .lesson-row.placeholder{cursor:default}
    .lesson-dot{width:14px;height:14px;flex:none;border-radius:50%;border:1.5px solid #d8d8d0;background:transparent}
    .lesson-row.completed .lesson-dot{background:var(--green);border-color:var(--green)}
    .lesson-info{flex:1;min-width:0}
    .lesson-title{font-size:14px;font-weight:500;line-height:1.3;color:#25251f}
    .lesson-type{font:400 11px/1 ui-monospace,Menlo,Monaco,Consolas,monospace;color:#a8a89f;flex:none}
    .status-quiz-score{background:#e8f0ff;color:#2255cc;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px;flex:none}
    @media(max-width:720px){
      .folder-desc{display:none}
      .folder-progress{width:auto;flex-direction:row;align-items:center}
      .folder-track{display:none}
    }
    .cert-card{background:linear-gradient(135deg,#fffbea,#fff8d0);border:2px solid #f5c842;border-radius:12px;padding:24px;text-align:center;margin-top:24px}
    .cert-card-icon{font-size:40px;margin-bottom:8px}
    .cert-card-title{font-size:16px;font-weight:900;color:#111;margin-bottom:4px}
    .cert-card-sub{font-size:12px;color:#888;margin-bottom:14px}
    .cert-card-code{font-size:11px;font-family:monospace;background:#fff;border:1px solid #e0d080;padding:4px 10px;border-radius:4px;color:#555}
    .btn-cert{display:inline-block;padding:8px 20px;background:#f5c842;color:#000;font-weight:800;font-size:13px;border-radius:6px;text-decoration:none;margin-top:12px}
    .btn-cert:hover{background:#d4a800}
    .draft-banner{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:10px 16px;font-size:12px;color:#856404;margin-bottom:16px}
    /* ── On-demand course player v2 — flat black editorial system ──────────
       layout_style='on_demand_hero' only; manual courses (layout_style=
       'standard') never load any of this. Design ref: docs/design/on-demand-v2/.
       Tokens are declared on .hero-card itself (not :root) so nothing here
       can leak into the rest of the app's light theme.
       ONE card, TWO states, modeled as two separate sibling containers (not one shared scroll
       area with something hidden inside it): #hero-view (state A: title/desc/progress/CTA)
       and #hero-scroll (state B: rail + one-lesson-at-a-time feed). Only one is ever attached
       to the DOM at a time -- heroEnterLesson()/heroExitLesson() detach/reattach #hero-view
       outright (Node.remove(), not display:none), so state A genuinely cannot be scrolled to
       from state B. */
    .hero-card{
      --od-bg:#000; --od-line:#1C1C1C; --od-line2:#2A2A2A; --od-line3:#2E2E2E; --od-dashed:#3A3A3A;
      --od-ghost:#232323; --od-text:#F2F4F2; --od-text2:#C4C8C4; --od-muted:#9A9E9A; --od-dim:#7C807C;
      --od-dimmer:#6E726E; --od-disabled:#4A4E4A; --od-disabled2:#5E625E; --od-lime:#8CC63E;
      --od-tint:#0F1509; --od-raised:#0F0F0F; --od-orange:#FF6B35; --od-orange-line:#52301F;
      background:var(--od-bg);border:1px solid var(--od-line);border-radius:10px;color:var(--od-text);
      margin:0 0 20px;width:100%;overflow:hidden;position:relative;transition:border-radius 240ms ease;
      display:flex;flex-direction:column}
    /* .hero-scroll's `flex:1` (below) only has any effect because this is a flex container --
       without this, tall lesson content had no bounded height to scroll within, so it just got
       clipped by this box's own `overflow:hidden` instead of scrolling inside .hero-lesson-body. */
    .hero-card.lesson-mode{position:fixed;inset:0;width:100vw;height:100vh;
      margin:0;border:0;border-radius:0;z-index:1000;animation:heroCardGrow 240ms ease}
    @keyframes heroCardGrow{from{opacity:.5;transform:scale(.97)}to{opacity:1;transform:scale(1)}}

    /* State A — landing hero band. Full-width, banded (hairline rules), not a boxed/centered
       card. Grid mirrors the design's 1.28fr/.72fr hero, collapsing to one column ~900px --
       degrades cleanly with no leftover gap since the stat-strip and day-map bands this design
       shipped with are deliberately not built here (v2 decision: base layout for every on-demand
       course, not onboarding-only; day-map is a deferred optional add-on, see HANDOFF.md). */
    .hero-view{padding:44px 44px 40px;display:grid;grid-template-columns:1.28fr .72fr;gap:40px;align-items:center}
    @media (max-width:900px){.hero-view{grid-template-columns:1fr;padding:28px 24px}}
    .hero-copy{min-width:0}
    .hero-eyebrow-row{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap}
    .hero-eyebrow{font:700 11px/1 "JetBrains Mono",monospace;letter-spacing:.16em;text-transform:uppercase;color:var(--od-lime)}
    .hero-required{font:700 10px/1 "JetBrains Mono",monospace;letter-spacing:.12em;color:var(--od-orange);border:1px solid var(--od-orange-line);padding:3px 8px;border-radius:3px}
    .hero-title{font-size:42px;font-weight:800;letter-spacing:-.02em;line-height:1.08;margin:0 0 18px;color:var(--od-text)}
    .hero-desc{font-size:16px;line-height:1.55;color:var(--od-muted);margin:0 0 30px;max-width:520px}
    .hero-cta{padding:15px 28px;background:var(--od-lime);color:#000;font-weight:700;font-size:14px;border-radius:4px;text-decoration:none;display:inline-block;border:none;cursor:pointer;transition:background 140ms ease}
    .hero-cta:hover{background:#79ad35}
    .hero-progress-wrap{display:flex;align-items:center;gap:14px;margin-top:32px;max-width:420px}
    .hero-progress-bar{flex:1;height:3px;background:var(--od-line);border-radius:0;overflow:hidden}
    .hero-progress-fill{height:100%;background:var(--od-lime);transition:width 320ms ease-out}
    .hero-progress-pct{font:700 11px/1 "JetBrains Mono",monospace;letter-spacing:.08em;color:var(--od-dim);white-space:nowrap}
    .hero-estimate{font:500 12px "JetBrains Mono",monospace;letter-spacing:.04em;color:var(--od-dim);margin-top:14px}
    .hero-image-slot{border:1px solid var(--od-line);min-height:260px;border-radius:6px;overflow:hidden;
      display:flex;align-items:flex-end;position:relative}
    .hero-image-slot img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    .hero-image-slot.placeholder{background:repeating-linear-gradient(135deg,#0C0C0C 0 8px,#000 8px 16px)}
    .hero-image-caption{position:relative;padding:18px;font:500 11px "JetBrains Mono",monospace;letter-spacing:.1em;color:var(--od-dimmer)}
    @media (max-width:900px){.hero-image-slot{min-height:160px}}

    /* State B: rail + one lesson at a time. #hero-scroll is a grid of two columns -- the
       persistent 288px outline rail and .hero-main (the scrollable body, prev/next nav, and a
       pinned progress footer, stacked). Only .hero-lesson-body ever scrolls, so reaching its
       bottom can never reveal the next lesson -- switching lessons is a JS active-class swap
       (heroShowLesson), not a scroll position. */
    .hero-scroll{display:none;grid-template-columns:288px 1fr;flex:1;min-height:0}
    .hero-card.lesson-mode .hero-scroll{display:grid}
    .hero-exit-btn{display:none;position:absolute;top:16px;right:20px;z-index:10;width:34px;height:34px;
      border-radius:50%;background:transparent;border:1px solid var(--od-line2);
      color:var(--od-dim);font-size:16px;cursor:pointer;align-items:center;justify-content:center;transition:all 140ms ease}
    .hero-exit-btn:hover{color:var(--od-text);border-color:var(--od-lime)}
    .hero-card.lesson-mode .hero-exit-btn{display:flex}

    /* Rail */
    .hero-rail{border-right:1px solid var(--od-line);display:flex;flex-direction:column;overflow-y:auto;min-height:0}
    .rail-head{padding:22px 24px;border-bottom:1px solid var(--od-line);flex-shrink:0}
    .rail-back{font:500 11px "JetBrains Mono",monospace;letter-spacing:.12em;color:var(--od-dim);text-decoration:none;display:inline-block;margin-bottom:8px}
    .rail-back:hover{color:var(--od-text)}
    .rail-track-title{font-size:16px;font-weight:700;letter-spacing:-.01em;line-height:1.25;color:var(--od-text)}
    .rail-progress-row{display:flex;align-items:center;gap:10px;margin-top:14px}
    .rail-progress-bar{flex:1;height:3px;background:var(--od-line);overflow:hidden}
    .rail-progress-fill{height:100%;background:var(--od-lime);transition:width 320ms ease-out}
    .rail-progress-pct{font:700 10px "JetBrains Mono",monospace;color:var(--od-lime)}
    .rail-modules{flex:1;min-height:0}
    .rail-module{padding:18px 24px;border-bottom:1px solid var(--od-line)}
    .rail-module-head{font:700 10px "JetBrains Mono",monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--od-dim);margin-bottom:14px}
    .rail-lessons{display:flex;flex-direction:column;gap:2px}
    .rail-row{display:flex;align-items:center;gap:11px;padding:9px 10px;border-left:2px solid transparent;text-decoration:none;cursor:pointer;background:none;border-radius:0;width:100%;box-sizing:border-box;text-align:left;font:inherit;transition:background 140ms ease}
    .rail-row:hover{background:#0A0A0A}
    .rail-row .rail-idx{font:700 10px "JetBrains Mono",monospace;color:var(--od-disabled2);width:16px;flex-shrink:0}
    .rail-row .rail-lesson-title{font-size:13px;font-weight:400;color:var(--od-muted)}
    .rail-row.active{background:var(--od-raised);border-left-color:var(--od-lime)}
    .rail-row.active .rail-idx{color:var(--od-lime)}
    .rail-row.active .rail-lesson-title{font-weight:600;color:var(--od-text)}
    .rail-row.done .rail-idx{color:var(--od-lime)}
    /* Quiz status card — swaps in for .rail-modules while the active lesson is a quiz */
    .rail-quiz{display:none;padding:18px 24px;flex:1;min-height:0}
    .rail-quiz.active{display:block}
    .rail-quiz-head{font:700 10px "JetBrains Mono",monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--od-dim);margin-bottom:14px}
    .rail-quiz-body{font-size:13px;line-height:1.6;color:var(--od-muted)}
    .rail-quiz-pips{display:flex;gap:6px;margin-top:16px}
    .rail-pip{flex:1;height:4px;background:var(--od-line)}
    .rail-pip.filled{background:var(--od-lime)}
    .rail-toggle{display:none}
    @media (max-width:1080px){
      .hero-scroll{grid-template-columns:1fr}
      .hero-rail{display:none;position:absolute;inset:0 auto 0 0;width:288px;max-width:80vw;z-index:20;background:var(--od-bg)}
      .hero-scroll.rail-open .hero-rail{display:flex}
      .rail-toggle{display:inline-flex;align-items:center;gap:8px;font:600 12px "JetBrains Mono",monospace;letter-spacing:.08em;color:var(--od-dim);background:none;border:1px solid var(--od-line2);border-radius:4px;padding:6px 12px;cursor:pointer;margin-bottom:14px}
      .rail-toggle:hover{color:var(--od-text);border-color:var(--od-lime)}
      .hero-lesson-body{padding-left:24px!important;padding-right:24px!important}
      .hero-lesson-nav,.hero-lesson-footer{padding-left:24px!important;padding-right:24px!important}
    }

    .hero-main{display:flex;flex-direction:column;min-height:0}
    .hero-lesson-body{flex:1;min-height:0;overflow-y:auto;padding:34px 44px 0}
    .lc-section{display:none;padding:0 0 8px}
    .lc-section.active{display:block}
    .hero-lesson-nav{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:20px 44px;
      border-top:1px solid var(--od-line);flex-shrink:0}
    .hero-lesson-nav .lesson-nav-btn:disabled{color:var(--od-disabled);border-color:var(--od-line2);opacity:1;cursor:default}
    .hero-lesson-nav-meta{font:500 11px "JetBrains Mono",monospace;letter-spacing:.1em;color:var(--od-dimmer)}
    .hero-lesson-footer{display:none}
    .lc-header{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
    .lc-num{font:700 11px "JetBrains Mono",monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--od-lime)}
    .lc-title-text{font-size:36px;font-weight:800;letter-spacing:-.025em;line-height:1.12;color:var(--od-text);margin:0;flex-basis:100%;order:2}
    .lc-type-tag{display:inline-flex;align-items:center;padding:3px 8px;border:1px solid var(--od-line2);border-radius:3px;font:700 10px "JetBrains Mono",monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--od-muted)}
    .lc-done-tag{color:var(--od-lime);font-size:13px;font-weight:700}
    /* Content boxes -- flat black surfaces, hairline borders only, no shadows/gradients.
       The one unavoidable exception is .pdf-frame, an iframe rendering an external PDF via
       the browser's native viewer -- that surface is the embedded document itself and isn't
       reachable from this page's CSS. */
    .video-wrap{background:var(--od-bg);border:1px solid var(--od-line);border-radius:0;overflow:hidden;margin:18px 0}
    .video-wrap video{width:100%;max-height:70vh;display:block}
    .video-empty{aspect-ratio:16/9;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:repeating-linear-gradient(135deg,#0C0C0C 0 8px,#000 8px 16px)}
    .video-empty-caption{font:500 11px "JetBrains Mono",monospace;letter-spacing:.1em;color:var(--od-dimmer)}
    .doc-wrap{border:1px solid var(--od-line);border-radius:0;padding:32px;text-align:center;background:repeating-linear-gradient(135deg,#0C0C0C 0 8px,#000 8px 16px);margin:18px 0}
    .doc-wrap.warn{background:repeating-linear-gradient(135deg,#0C0C0C 0 8px,#000 8px 16px);border-color:var(--od-line)}
    .doc-icon{width:44px;height:44px;margin:0 auto 14px;border:1px solid var(--od-line3);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--od-dim)}
    .doc-title{font-size:14px;font-weight:600;color:var(--od-text2);margin-bottom:8px}
    .doc-dl{display:inline-block;padding:9px 22px;background:var(--od-lime);color:#000;font-weight:700;font-size:13px;border-radius:4px;text-decoration:none}
    .doc-dl:hover{background:#79ad35}
    .pdf-wrap{border:1px solid var(--od-line);border-radius:0;overflow:hidden;margin:18px 0;background:var(--od-bg)}
    .pdf-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-bottom:1px solid var(--od-line)}
    .pdf-toolbar .doc-title{margin:0}
    .pdf-frame{width:100%;height:60vh;min-height:420px;border:0;display:block}
    .lc-content-html{line-height:1.6;font-size:15px;color:var(--od-muted);margin:18px 0;max-width:620px}
    .lc-content-html p{margin:0 0 12px}
    .lc-content-html img{max-width:100%;height:auto}
    .lc-content-html li.lc-task-item{list-style:none;margin-left:-20px}
    .lc-content-html li.lc-task-item input[type=checkbox]{accent-color:var(--od-lime);margin-right:6px;vertical-align:middle}
    .question-card{border:none;padding:0;margin:0 0 32px}
    .question-text{font-size:32px;font-weight:700;letter-spacing:-.02em;line-height:1.24;color:var(--od-text);margin-bottom:32px;max-width:760px}
    .option-label{display:flex;align-items:center;gap:16px;padding:17px 20px;border:1px solid var(--od-line);border-radius:0;cursor:pointer;margin-bottom:10px;font-size:16px;font-weight:500;transition:border-color 140ms ease,background 140ms ease;color:var(--od-text);background:none;max-width:760px}
    .option-label:hover{border-color:var(--od-line3)}
    .option-label input[type=radio],.option-label input[type=checkbox]{position:absolute;opacity:0;pointer-events:none}
    .option-label .opt-letter{font:700 12px "JetBrains Mono",monospace;color:var(--od-dim);flex-shrink:0}
    .option-label .opt-label-text{flex:1}
    .option-label .opt-selected-tag{margin-left:auto;font:700 10px "JetBrains Mono",monospace;letter-spacing:.1em;color:var(--od-lime);display:none}
    .option-label.selected{border-color:var(--od-lime);background:var(--od-tint);font-weight:600}
    .option-label.selected .opt-letter{color:var(--od-lime)}
    .option-label.selected .opt-selected-tag{display:inline}
    .quiz-text-answer{width:100%;box-sizing:border-box;padding:14px 16px;border:1px solid var(--od-line);border-radius:0;background:none;color:var(--od-text);font-size:14px;font-family:inherit;max-width:760px}
    .quiz-text-answer::placeholder{color:var(--od-dimmer)}
    .quiz-nav{display:flex;gap:16px;align-items:center;margin-top:28px;max-width:760px}
    .quiz-progress{font:500 11px "JetBrains Mono",monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--od-dim);margin-bottom:18px!important}
    .quiz-nav-helper{font-size:13px;color:var(--od-dim)}
    .quiz-result{border-radius:0;padding:24px;text-align:left;margin-bottom:14px;background:none;border:1px solid var(--od-line);max-width:760px}
    .quiz-result.pass{border-color:var(--od-lime);background:var(--od-tint)}
    .quiz-result.fail{border-color:var(--od-orange);background:#150A06}
    .qr-icon{display:none}
    .qr-score{font-size:24px;font-weight:800;margin-bottom:4px}
    .qr-msg{font-size:13px;color:var(--od-muted)}
    .mark-done-btn{padding:12px 22px;background:none;color:var(--od-text);font-weight:600;font-size:14px;border:1px solid var(--od-line2);border-radius:4px;cursor:pointer;transition:border-color 140ms ease}
    .mark-done-btn:hover{border-color:var(--od-lime);color:var(--od-lime)}
    .mark-done-btn:disabled{color:var(--od-disabled);cursor:default}
    .mark-done-btn:disabled:hover{border-color:var(--od-line2);color:var(--od-disabled)}
    .done-badge{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:var(--od-tint);border:1px solid var(--od-lime);color:var(--od-lime);font-weight:700;font-size:13px;border-radius:4px}
    .lesson-nav-btn{padding:12px 24px;border-radius:4px;font-weight:600;font-size:14px;text-decoration:none;border:1px solid var(--od-line2);color:var(--od-text);background:none;cursor:pointer;transition:background 140ms ease,border-color 140ms ease}
    .lesson-nav-btn:hover{border-color:var(--od-lime);color:var(--od-lime)}
    .lesson-nav-btn.primary{background:var(--od-lime);border-color:var(--od-lime);color:#000;font-weight:700}
    .lesson-nav-btn.primary:hover{background:#79ad35;color:#000}
    .attach-list{display:flex;flex-direction:column;gap:8px;margin:0 0 18px}
    .attach-item{display:flex;align-items:center;gap:10px;padding:9px 14px;border:1px solid var(--od-line);text-decoration:none;color:var(--od-muted);font-size:13px;font-weight:600}
    .attach-item:hover{border-color:var(--od-lime);color:var(--od-lime)}
    .upload-dropzone{border:1px dashed var(--od-dashed);border-radius:0;padding:30px;text-align:center;cursor:pointer;margin-bottom:14px}
    .upload-dropzone:hover,.upload-dropzone.drag{border-color:var(--od-lime);background:var(--od-tint)}
    .upload-dropzone p{margin:4px 0;font-size:13px;color:var(--od-dim)}
    .upload-submitted{background:var(--od-tint);border:1px solid var(--od-lime);border-radius:0;padding:16px 20px;margin-bottom:14px}
    .upload-submitted-title{font-size:13px;font-weight:700;color:var(--od-lime);margin-bottom:4px}
    .upload-submitted-sub{font-size:12px;color:var(--od-muted);margin-bottom:8px}
    .placeholder-wrap{border:1px dashed var(--od-line3);border-radius:0;padding:40px;text-align:center;margin-bottom:18px}
    /* Feedback lesson -- reuses the shell's existing tokens/quiz-adjacent shapes, no new visual language */
    .fb-progress{font:500 11px "JetBrains Mono",monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--od-dim);margin-bottom:18px}
    .fb-section-eyebrow{font:700 11px "JetBrains Mono",monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--od-lime);margin-bottom:10px}
    .fb-question-text{font-size:24px;font-weight:700;letter-spacing:-.01em;line-height:1.3;color:var(--od-text);margin-bottom:24px;max-width:700px}
    .fb-field{margin-bottom:18px;max-width:700px}
    .fb-field label{display:block;font-size:12px;font-weight:700;color:var(--od-muted);margin-bottom:8px}
    .fb-field input[type=text],.fb-field input[type=date],.fb-field textarea{width:100%;box-sizing:border-box;padding:12px 16px;border:1px solid var(--od-line);border-radius:0;background:none;color:var(--od-text);font-size:14px;font-family:inherit}
    .fb-field textarea{min-height:100px}
    .fb-rating-row{display:flex;gap:8px;flex-wrap:wrap;max-width:700px}
    .fb-rating-opt{flex:1;min-width:72px;text-align:center;padding:14px 8px;border:1px solid var(--od-line);border-radius:0;cursor:pointer;font-size:12px;color:var(--od-muted);transition:border-color 140ms ease,background 140ms ease}
    .fb-rating-opt:hover{border-color:var(--od-line3)}
    .fb-rating-opt.selected{border-color:var(--od-lime);background:var(--od-tint);color:var(--od-text);font-weight:600}
    .fb-rating-num{display:block;font:700 16px "JetBrains Mono",monospace;margin-bottom:4px;color:var(--od-text)}
    .fb-rating-opt.selected .fb-rating-num{color:var(--od-lime)}
    .fb-scale-row{display:flex;gap:6px;flex-wrap:wrap;max-width:700px}
    .fb-scale-opt{flex:1;min-width:38px;text-align:center;padding:12px 4px;border:1px solid var(--od-line);border-radius:0;cursor:pointer;font:700 13px "JetBrains Mono",monospace;color:var(--od-muted);transition:border-color 140ms ease,background 140ms ease}
    .fb-scale-opt:hover{border-color:var(--od-line3)}
    .fb-scale-opt.selected{border-color:var(--od-lime);background:var(--od-tint);color:var(--od-lime)}
    .fb-scale-helpers{display:flex;justify-content:space-between;font-size:11px;color:var(--od-dim);margin-top:8px;max-width:700px}
    .fb-nav{display:flex;gap:16px;align-items:center;margin-top:28px;max-width:700px}
    .fb-save-hint{font:500 11px "JetBrains Mono",monospace;letter-spacing:.08em;color:var(--od-dim)}
    .fb-submitted{border:1px solid var(--od-lime);background:var(--od-tint);border-radius:0;padding:20px 24px;text-align:center;margin-bottom:18px}
    .fb-submitted-title{font-size:14px;font-weight:700;color:var(--od-lime)}
    .lc-cert-card{background:none;border:1px solid var(--od-lime);border-radius:0;padding:24px;text-align:center;margin-top:8px;color:var(--od-text)}
    .lc-cert-card .cert-title-dark{color:var(--od-text)}
    .lc-cert-card .cert-sub-dark{color:var(--od-muted)}
    .lc-cert-card .cert-code-dark{background:none;border:1px solid var(--od-line2);color:var(--od-lime)}
    /* The app-wide floating help bubble (#help-widget-root, assets/global.js) sits at
       z-index 1900 -- above the full-screen lesson-mode overlay (z-index 1000) -- and would
       float on top of the footer nav. Hidden only while a lesson is actually open. */
    body.od-lesson-active #help-widget-root{display:none}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('university', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">INNOVATE University</div>
    </header>
    <main class="wrap">

      <?php if (!$course['published']): ?>
      <div class="draft-banner">⚠️ This course is unpublished — only admins can view it.</div>
      <?php endif; ?>

      <?php if ($isHero): ?>
      <!-- On-demand course container: the black card the whole course lives inside -->
      <a class="course-back" href="university.php" style="color:#7C807C;margin-bottom:10px;display:inline-flex">← Back to Catalog</a>
      <div class="hero-card" id="hero-card">
        <button class="hero-exit-btn" id="hero-exit-btn" onclick="heroExitLesson()" title="Back to course" aria-label="Back to course">←</button>
        <!-- State A: landing. Its own top-level container, sibling to #hero-scroll below --
             heroEnterLesson() detaches this node from the DOM entirely (not display:none). -->
        <div class="hero-view" id="hero-view">
          <div class="hero-copy">
            <?php if (!empty($course['category_id']) || $course['is_required']): ?>
            <div class="hero-eyebrow-row">
              <?php if (!empty($course['category_id'])): ?>
              <div class="hero-eyebrow"><?= htmlspecialchars($course['cat_name']) ?></div>
              <?php endif; ?>
              <?php if ($course['is_required']): ?>
              <div class="hero-required">Required</div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <h1 class="hero-title"><?= htmlspecialchars($course['title']) ?></h1>
            <?php if ($course['description']): ?>
            <div class="hero-desc"><?= htmlspecialchars($course['description']) ?></div>
            <?php endif; ?>
            <?php if ($ctaTarget): ?>
            <button class="hero-cta" id="hero-cta-btn" data-target="<?= $ctaTarget ?>" onclick="heroEnterLesson(<?= $ctaTarget ?>)"><?= $ctaLabel ?> →</button>
            <?php endif; ?>
            <div class="hero-progress-wrap">
              <div class="hero-progress-bar"><div class="hero-progress-fill" id="hero-progress-fill" style="width:<?= $pct ?>%"></div></div>
              <div class="hero-progress-pct" id="hero-progress-pct"><?= $doneLessons ?> / <?= $totalLessons ?> LESSONS</div>
            </div>
            <?php if ($showEstimate): ?>
            <div class="hero-estimate" id="hero-estimate">About <?= $remainingLabel ?></div>
            <?php endif; ?>
            <!-- Certificate lives on the landing state, not inside the per-lesson view -- it's
                 what "Finish Course" returns you to. heroPaintCert() repaints #hero-cert-pending
                 here if a cert is earned mid-session. -->
            <?php if ($cert): ?>
            <div class="lc-cert-card" style="margin-top:24px">
              <div class="cert-title-dark" style="font-size:15px;font-weight:800;margin-bottom:4px">Certificate Earned!</div>
              <div class="cert-sub-dark" style="font-size:12px;margin-bottom:12px">
                You completed <strong><?= htmlspecialchars($course['title']) ?></strong> on <?= fmt_dt_et($cert['issued_at'], 'F j, Y') ?>
                <?php if (!empty($cert['expires_at'])): ?><br>Valid through <?= fmt_dt_et($cert['expires_at'], 'F j, Y') ?><?php endif; ?>
              </div>
              <div class="cert-code-dark" style="font-size:11px;font-family:monospace;padding:4px 10px;display:inline-block;margin-bottom:12px"><?= htmlspecialchars($cert['cert_code']) ?></div>
              <div><a href="university_certs.php?print=1&code=<?= urlencode($cert['cert_code']) ?>" target="_blank" style="display:inline-block;padding:8px 20px;background:var(--od-lime);color:#000;font-weight:700;font-size:13px;border-radius:4px;text-decoration:none">Print Certificate</a></div>
            </div>
            <?php elseif ($totalLessons > 0 && $doneLessons >= $totalLessons): ?>
            <div class="lc-cert-card" id="hero-cert-pending" style="margin-top:24px">
              <div class="cert-title-dark" style="font-size:15px;font-weight:800;margin-bottom:4px">Course Complete!</div>
              <div class="cert-sub-dark" style="font-size:12px">Your certificate will appear here momentarily.</div>
            </div>
            <?php else: ?>
            <div id="hero-cert-pending"></div>
            <?php endif; ?>
          </div>
          <?php if ($course['thumb_key']): ?>
          <div class="hero-image-slot">
            <img src="api/uni_download.php?thumb=1&course_id=<?= $courseId ?>" alt="">
          </div>
          <?php else: ?>
          <div class="hero-image-slot placeholder">
            <div class="hero-image-caption">COURSE IMAGE</div>
          </div>
          <?php endif; ?>
        </div>
        <!-- State B: one lesson at a time. display:none until .lesson-mode is active (see CSS). -->
        <div class="hero-scroll" id="hero-scroll">
          <div class="hero-rail" id="hero-rail">
            <div class="rail-head">
              <a class="rail-back" href="university.php">← ALL TRACKS</a>
              <div class="rail-track-title"><?= htmlspecialchars($course['title']) ?></div>
              <div class="rail-progress-row">
                <div class="rail-progress-bar"><div class="rail-progress-fill" id="rail-progress-fill" style="width:<?= $pct ?>%"></div></div>
                <div class="rail-progress-pct" id="rail-progress-pct"><?= $pct ?>%</div>
              </div>
            </div>
            <div class="rail-modules" id="rail-modules">
              <?php
                $railModuleNum = 0;
                foreach ($folders as $folder) {
                    $railModuleNum++;
                    $railLessons = $lessonsByFolder[$folder['id']] ?? [];
                    if (!$railLessons) continue;
                    ?>
                    <div class="rail-module">
                      <div class="rail-module-head">Module <?= $railModuleNum ?> — <?= htmlspecialchars($folder['title']) ?></div>
                      <div class="rail-lessons">
                        <?php foreach ($railLessons as $lesson): $rlid = (int)$lesson['id']; $rIdx = $heroLessonIndex[$rlid] ?? 0; ?>
                        <button type="button" class="rail-row<?= isset($progressMap[$rlid]) ? ' done' : '' ?>" id="rail-row-<?= $rlid ?>" onclick="heroShowLesson(<?= $rlid ?>)">
                          <span class="rail-idx"><?= str_pad((string)($rIdx + 1), 2, '0', STR_PAD_LEFT) ?></span>
                          <span class="rail-lesson-title"><?= htmlspecialchars($lesson['title']) ?></span>
                        </button>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <?php
                }
                if ($lessonsByFolder[0] ?? null) {
                    ?>
                    <div class="rail-module">
                      <div class="rail-module-head">Ungrouped</div>
                      <div class="rail-lessons">
                        <?php foreach ($lessonsByFolder[0] as $lesson): $rlid = (int)$lesson['id']; $rIdx = $heroLessonIndex[$rlid] ?? 0; ?>
                        <button type="button" class="rail-row<?= isset($progressMap[$rlid]) ? ' done' : '' ?>" id="rail-row-<?= $rlid ?>" onclick="heroShowLesson(<?= $rlid ?>)">
                          <span class="rail-idx"><?= str_pad((string)($rIdx + 1), 2, '0', STR_PAD_LEFT) ?></span>
                          <span class="rail-lesson-title"><?= htmlspecialchars($lesson['title']) ?></span>
                        </button>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <?php
                }
              ?>
            </div>
            <!-- Swaps in for #rail-modules while the active lesson is a quiz (heroSyncRailActive). -->
            <div class="rail-quiz" id="rail-quiz-panel">
              <div class="rail-quiz-head">Knowledge Check</div>
              <div class="rail-quiz-body" id="rail-quiz-body"></div>
              <div class="rail-quiz-pips" id="rail-quiz-pips"></div>
            </div>
          </div>
          <div class="hero-main" id="hero-main">
            <div class="hero-lesson-body" id="hero-lesson-body">
            <button type="button" class="rail-toggle" id="rail-toggle-btn" onclick="heroToggleRail()">☰ Lessons</button>
            <script>
              const HERO_STATE = {}; const HERO_FB_STATE = {};
              // HERO_ALREADY_DONE and heroScheduleComplete must exist before this point too, not just
              // before the big function block further down: a fast-loading doc/PDF lazy-media iframe
              // can fire its onload -- which calls heroScheduleComplete() -- before the browser has
              // parsed as far as that later block, throwing "heroScheduleComplete is not defined".
              // heroMarkComplete() itself can stay defined later since it's only ever reached via
              // this function's own 2s setTimeout, by which point the rest of the page has always
              // finished loading.
              const HERO_ALREADY_DONE = new Set(<?= json_encode(array_values(array_map('intval', array_keys(array_filter($progressMap, fn($p, $lid) => in_array($lid, array_column($gradableLessons, 'id'), true), ARRAY_FILTER_USE_BOTH))))) ?>);
              function heroScheduleComplete(lessonId) { if (!HERO_ALREADY_DONE.has(lessonId)) setTimeout(() => heroMarkComplete(lessonId), 2000); }
            </script><!-- declared before the loop below: each quiz
              lesson's inline script (see render_hero_lesson_content) writes into HERO_STATE[lid]
              (and each feedback lesson's into HERO_FB_STATE[lid]) as it renders, which needs the
              object to already exist at that point in the page. -->
            <?php
              foreach ($folders as $folder) {
                  foreach ($lessonsByFolder[$folder['id']] ?? [] as $lesson) {
                      render_hero_lesson_content(
                          $lesson, $heroDisplayNum[$lesson['id']] ?? 0, $totalLessons,
                          $progressMap[$lesson['id']] ?? null,
                          $heroQuestionsByLesson[$lesson['id']] ?? [],
                          $heroAttachmentsByLesson[$lesson['id']] ?? [],
                          $heroUploadByLesson[$lesson['id']] ?? null,
                          $heroFeedbackStepsByLesson[$lesson['id']] ?? [],
                          $heroFeedbackAnswersByLesson[$lesson['id']] ?? [],
                          $heroFeedbackStatusByLesson[$lesson['id']] ?? null,
                          $heroFeedbackStepByLesson[$lesson['id']] ?? 0,
                          $agent
                      );
                  }
              }
              foreach ($lessonsByFolder[0] ?? [] as $lesson) {
                  render_hero_lesson_content(
                      $lesson, $heroDisplayNum[$lesson['id']] ?? 0, $totalLessons,
                      $progressMap[$lesson['id']] ?? null,
                      $heroQuestionsByLesson[$lesson['id']] ?? [],
                      $heroAttachmentsByLesson[$lesson['id']] ?? [],
                      $heroUploadByLesson[$lesson['id']] ?? null,
                      $heroFeedbackStepsByLesson[$lesson['id']] ?? [],
                      $heroFeedbackAnswersByLesson[$lesson['id']] ?? [],
                      $heroFeedbackStatusByLesson[$lesson['id']] ?? null,
                      $heroFeedbackStepByLesson[$lesson['id']] ?? 0,
                      $agent
                  );
              }
            ?>
            </div>
            <div class="hero-lesson-nav">
              <button class="lesson-nav-btn" id="hero-prev-btn" onclick="heroPrevLesson()">← Previous</button>
              <span class="hero-lesson-nav-meta" id="hero-lesson-num">LESSON 1 / <?= $totalLessons ?></span>
              <button class="lesson-nav-btn primary" id="hero-next-btn" onclick="heroNextLesson()">Next →</button>
            </div>
          </div>
        </div>
      </div>

      <?php else: ?>
      <!-- Course header (standard layout — prod's redesigned folder-card accordion,
           unchanged from what's currently live) -->
      <div class="course-header">
        <div class="course-header-thumb">
          <?php if ($course['thumb_key']): ?>
          <img src="api/uni_download.php?thumb=1&course_id=<?= $courseId ?>" alt="">
          <?php else: ?>
          <?= htmlspecialchars($course['cat_icon']) ?>
          <?php endif; ?>
        </div>
        <div class="course-header-meta">
          <a class="course-back" href="university.php">← Back to Catalog</a>
          <div class="course-header-cat"><?= htmlspecialchars($course['cat_name']) ?></div>
          <div class="course-header-title">
            <?= htmlspecialchars($course['title']) ?>
            <?php if ($course['is_required']): ?><span style="font-size:11px;background:#ff6b35;color:white;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:700;vertical-align:middle">Required</span><?php endif; ?>
          </div>
          <?php if ($course['description']): ?>
          <div class="course-header-desc"><?= htmlspecialchars($course['description']) ?></div>
          <?php endif; ?>
          <div class="course-header-stats">
            <div class="ch-stat"><strong><?= $totalLessons ?></strong> lesson<?= $totalLessons !== 1 ? 's' : '' ?></div>
            <div class="ch-stat"><strong><?= $pct ?>%</strong> complete</div>
          </div>
          <div class="course-progress-bar"><div class="course-progress-fill" style="width:<?= $pct ?>%"></div></div>
        </div>
        <?php if ($firstIncomplete): ?>
        <a class="course-header-cta" href="university_lesson.php?id=<?= $firstIncomplete ?>">
          <?= $doneLessons > 0 ? 'Continue →' : 'Start Course' ?>
        </a>
        <?php endif; ?>
      </div>

      <!-- Lessons -->
      <?php if (!$lessons): ?>
      <div class="card" style="padding:32px;text-align:center;color:#bbb;font-size:13px">No lessons added to this course yet.</div>
      <?php else: ?>
      <?php
        $typeTags = ['video' => 'VID', 'doc' => 'DOC', 'quiz' => 'QUIZ', 'placeholder' => '', 'upload' => 'UPLOAD'];

        function render_learner_lesson_row($lesson, $progressMap, $typeTags) {
          $isPlaceholder = $lesson['type'] === 'placeholder';
          $prog    = $progressMap[$lesson['id']] ?? null;
          $isDone  = $prog !== null;
          $typeTag = $typeTags[$lesson['type']] ?? strtoupper($lesson['type']);
          $tag = $isPlaceholder ? 'div' : 'a';
          ?>
          <<?= $tag ?> class="lesson-row<?= $isDone ? ' completed' : '' ?><?= $isPlaceholder ? ' placeholder' : '' ?>" <?= $isPlaceholder ? '' : 'href="university_lesson.php?id=' . (int)$lesson['id'] . '"' ?>>
            <span class="lesson-dot"></span>
            <div class="lesson-info"><div class="lesson-title"><?= htmlspecialchars($lesson['title']) ?></div></div>
            <?php if ($isDone && $lesson['type'] === 'quiz' && $prog['score'] !== null): ?>
            <span class="status-quiz-score"><?= $prog['score'] ?>%</span>
            <?php elseif ($typeTag !== ''): ?>
            <span class="lesson-type"><?= htmlspecialchars($typeTag) ?></span>
            <?php endif; ?>
          </<?= $tag ?>>
          <?php
        }

        // A folder id of 0 stands in for the "Ungrouped" bucket of lessons
        // with no folder_id — it gets the same card treatment so lessons
        // never render outside the card-accordion structure.
        function render_folder_card($folderId, $code, $title, $description, $folderLessons, $progressMap, $typeTags, $isDefaultOpen) {
          $gradable = array_values(array_filter($folderLessons, fn($l) => $l['type'] !== 'placeholder'));
          $total = count($gradable);
          $done  = count(array_filter($gradable, fn($l) => isset($progressMap[$l['id']])));
          $pct   = $total > 0 ? round($done / $total * 100) : 0;
          $chip  = $code ?: strtoupper(substr($title, 0, 3));
          ?>
          <div class="folder-card">
            <button type="button" class="folder-header" data-folder="<?= (int)$folderId ?>" aria-expanded="<?= $isDefaultOpen ? 'true' : 'false' ?>">
              <span class="folder-code"><?= htmlspecialchars($chip) ?></span>
              <span class="folder-text">
                <span class="folder-title"><?= htmlspecialchars($title) ?></span>
                <?php if ($description): ?><span class="folder-desc"><?= htmlspecialchars($description) ?></span><?php endif; ?>
              </span>
              <span class="folder-progress">
                <span class="folder-count"><?= $done ?> / <?= $total ?></span>
                <span class="folder-track"><span class="folder-track-fill" style="width:<?= $pct ?>%"></span></span>
              </span>
              <span class="folder-caret">▼</span>
            </button>
            <div class="folder-body">
              <?php foreach ($folderLessons as $lesson) render_learner_lesson_row($lesson, $progressMap, $typeTags); ?>
            </div>
          </div>
          <?php
        }
      ?>
      <div class="folder-list">
        <?php foreach ($folders as $folder): ?>
        <?php render_folder_card((int)$folder['id'], $folder['code'], $folder['title'], $folder['description'], $lessonsByFolder[$folder['id']] ?? [], $progressMap, $typeTags, (int)$folder['id'] === $defaultOpenFolderId); ?>
        <?php endforeach; ?>
        <?php if ($lessonsByFolder[0] ?? null): ?>
        <?php render_folder_card(0, '', 'Ungrouped', '', $lessonsByFolder[0], $progressMap, $typeTags, $defaultOpenFolderId === 0); ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Certificate -->
      <?php if ($cert): ?>
      <div class="cert-card">
        <div class="cert-card-icon">🏆</div>
        <div class="cert-card-title">Certificate Earned!</div>
        <div class="cert-card-sub">You completed <strong><?= htmlspecialchars($course['title']) ?></strong> on <?= fmt_dt_et($cert['issued_at'], 'F j, Y') ?></div>
        <div class="cert-card-code"><?= htmlspecialchars($cert['cert_code']) ?></div>
        <a class="btn-cert" href="university_certs.php?print=1&code=<?= urlencode($cert['cert_code']) ?>" target="_blank">Print Certificate</a>
      </div>
      <?php elseif ($totalLessons > 0 && $doneLessons >= $totalLessons): ?>
      <div class="cert-card" style="background:#f9fdf5;border-color:#c3dfa8">
        <div class="cert-card-icon">🎉</div>
        <div class="cert-card-title">Course Complete!</div>
        <div class="cert-card-sub">You finished all lessons — your certificate will appear here momentarily.</div>
      </div>
      <?php endif; ?>

      <?php endif; ?>

    </main>
  </div>
</div>
<?php if ($isHero): ?>
<script>
// ── On-demand in-card course container ──────────────────────────────────
// Same api/uni_progress.php actions and payloads as university_lesson.php
// (complete / submit_quiz / submit_learner_upload / feedback_autosave /
// feedback_submit) -- nothing here changes how completion, grading, or
// certificates work, only how the results are displayed and navigated to.
const HERO_COURSE_ID    = <?= $courseId ?>;
const HERO_LESSON_ORDER = <?= json_encode($heroLessonOrder) ?>;
// Same numbering used server-side for each lesson's "Lesson X of Y" header (lesson id -> display
// number, drawn from the gradable set so it never exceeds HERO_TOTAL) -- see $heroDisplayNum.
const HERO_LESSON_DISPLAY_NUM = <?= json_encode($heroDisplayNum) ?>;
const HERO_TOTAL        = <?= $totalLessons ?>;
let HERO_DONE_COUNT     = <?= $doneLessons ?>;
// HERO_STATE and HERO_ALREADY_DONE are declared earlier, right before the lesson loop (see
// #hero-lesson-body above) -- each quiz lesson's inline script writes into HERO_STATE as it
// renders, and HERO_ALREADY_DONE has to exist before then too (see the comment there).
// Rail-only, presentational lookups -- which lesson ids are quizzes (so the rail can swap its
// module list for the quiz status card) and how many questions each quiz has (for the pips).
// No grading data here: correctness is still only known server-side at Submit Quiz time.
const HERO_LESSON_TYPES  = <?= json_encode(array_combine(array_column($lessons, 'id'), array_column($lessons, 'type'))) ?>;
const HERO_QUIZ_META     = <?= json_encode(array_map(fn($qs) => ['total' => count($qs)], $heroQuestionsByLesson)) ?>;
const HERO_QUIZ_PASS_SCORE = <?= (int)($course['quiz_pass_score'] ?? 70) ?>;

// One lesson at a time: showing a lesson means marking it .active and
// everything else stays display:none (see CSS) -- never a scroll position,
// so there is no way to scroll from one lesson's content into the next.
let HERO_ACTIVE_LESSON_ID = null;

function heroShowLesson(lessonId) {
  document.querySelectorAll('.lc-section.active').forEach(el => el.classList.remove('active'));
  const el = document.getElementById('lesson-' + lessonId);
  if (el) el.classList.add('active');
  HERO_ACTIVE_LESSON_ID = lessonId;
  const body = document.getElementById('hero-lesson-body');
  if (body) body.scrollTop = 0;
  heroUpdateLessonChrome(lessonId);
  heroSyncRailActive(lessonId);
  document.getElementById('hero-scroll')?.classList.remove('rail-open'); // close the mobile drawer, if open
}

// Rail: highlight the current row, and swap the module list for the quiz status card
// (progress pips) while a quiz lesson is active. Presentational only -- reuses the same
// heroShowLesson() call the plain lesson rows already trigger.
function heroSyncRailActive(lessonId) {
  document.querySelectorAll('.rail-row.active').forEach(r => r.classList.remove('active'));
  document.getElementById('rail-row-' + lessonId)?.classList.add('active');
  const isQuiz = HERO_LESSON_TYPES[lessonId] === 'quiz';
  const modules = document.getElementById('rail-modules');
  const quizPanel = document.getElementById('rail-quiz-panel');
  if (modules) modules.style.display = isQuiz ? 'none' : '';
  if (quizPanel) quizPanel.classList.toggle('active', isQuiz);
  if (isQuiz) heroSyncQuizRail(lessonId);
}

function heroSyncQuizRail(lessonId) {
  const meta = HERO_QUIZ_META[lessonId];
  const bodyEl = document.getElementById('rail-quiz-body');
  const pipsEl = document.getElementById('rail-quiz-pips');
  if (!meta) { if (bodyEl) bodyEl.textContent = ''; if (pipsEl) pipsEl.innerHTML = ''; return; }
  const st = HERO_STATE[lessonId];
  const current = st ? st.currentQ : 0;
  if (bodyEl) bodyEl.textContent = `${meta.total} question${meta.total !== 1 ? 's' : ''}. Pass score ${HERO_QUIZ_PASS_SCORE}%.`;
  if (pipsEl) {
    pipsEl.innerHTML = '';
    for (let i = 0; i < meta.total; i++) {
      const pip = document.createElement('div');
      pip.className = 'rail-pip' + (i <= current ? ' filled' : '');
      pipsEl.appendChild(pip);
    }
  }
}

function heroToggleRail() {
  document.getElementById('hero-scroll')?.classList.toggle('rail-open');
}

function heroUpdateLessonChrome(lessonId) {
  const idx = HERO_LESSON_ORDER.indexOf(lessonId);
  const prevBtn = document.getElementById('hero-prev-btn');
  const nextBtn = document.getElementById('hero-next-btn');
  if (prevBtn) prevBtn.disabled = (idx <= 0);
  if (nextBtn) nextBtn.textContent = (idx === HERO_LESSON_ORDER.length - 1) ? 'Finish Course' : 'Next →';
  const numEl = document.getElementById('hero-lesson-num');
  if (numEl) numEl.textContent = `LESSON ${HERO_LESSON_DISPLAY_NUM[lessonId] ?? (idx + 1)} / ${HERO_TOTAL}`;
  heroSyncFooterProgress();
}

function heroSyncFooterProgress() {
  const pct = HERO_TOTAL > 0 ? Math.round(HERO_DONE_COUNT / HERO_TOTAL * 100) : 0;
  const fill = document.getElementById('rail-progress-fill');
  const pctEl = document.getElementById('rail-progress-pct');
  if (fill) fill.style.width = pct + '%';
  if (pctEl) pctEl.textContent = pct + '%';
}

function heroPrevLesson() {
  const idx = HERO_LESSON_ORDER.indexOf(HERO_ACTIVE_LESSON_ID);
  if (idx > 0) heroShowLesson(HERO_LESSON_ORDER[idx - 1]);
}

// Last lesson: "Next" becomes "Finish Course" (see heroUpdateLessonChrome)
// and exits back to state A instead of advancing to a lesson that doesn't exist.
function heroNextLesson() {
  const idx = HERO_LESSON_ORDER.indexOf(HERO_ACTIVE_LESSON_ID);
  if (idx < 0) return;
  if (idx === HERO_LESSON_ORDER.length - 1) { heroExitLesson(); return; }
  heroShowLesson(HERO_LESSON_ORDER[idx + 1]);
}

// State A <-> state B swap. #hero-view (state A) is detached from the DOM
// outright on entry -- not display:none -- so there is no way to scroll to
// it from inside a lesson; it's simply not attached to the document. Held
// in these three variables so heroExitLesson() can put it back exactly
// where it came from.
let HERO_VIEW_NODE = null, HERO_VIEW_PARENT = null, HERO_VIEW_NEXT = null;

function heroEnterLesson(lessonId) {
  const card = document.getElementById('hero-card');
  const heroView = document.getElementById('hero-view');
  if (heroView && heroView.parentNode) {
    HERO_VIEW_PARENT = heroView.parentNode;
    HERO_VIEW_NEXT = heroView.nextSibling;
    HERO_VIEW_NODE = heroView;
    heroView.remove();
  }
  if (card) card.classList.add('lesson-mode');
  document.body.classList.add('od-lesson-active'); // hides the floating help bubble -- see CSS
  heroShowLesson(lessonId);
}

function heroExitLesson() {
  const card = document.getElementById('hero-card');
  if (card) card.classList.remove('lesson-mode');
  document.body.classList.remove('od-lesson-active');
  if (HERO_VIEW_NODE && HERO_VIEW_PARENT) {
    HERO_VIEW_PARENT.insertBefore(HERO_VIEW_NODE, HERO_VIEW_NEXT);
    HERO_VIEW_NODE = null; HERO_VIEW_PARENT = null; HERO_VIEW_NEXT = null;
  }
  heroSyncHeroView(); // #hero-view was detached the whole time it was in lesson-mode --
                      // repaint it (and the cert card) now that it's back.
}

function heroNextLessonId(lessonId) {
  const i = HERO_LESSON_ORDER.indexOf(lessonId);
  return (i >= 0 && i < HERO_LESSON_ORDER.length - 1) ? HERO_LESSON_ORDER[i + 1] : null;
}

// Auto-advance only on a *fresh* completion (this function's callers already
// guard on "wasn't done before"), not just because the agent revisited an
// already-finished lesson. No-op on the last lesson -- the agent presses
// "Finish Course" themselves rather than being pushed out automatically.
function heroAdvanceAfterCompletion(lessonId) {
  const next = heroNextLessonId(lessonId);
  if (next) setTimeout(() => heroShowLesson(next), 600);
}

// Paints current progress/CTA/cert state onto #hero-view's elements. Null-safe
// by design: while a lesson is open, #hero-view is detached from the DOM, so
// these getElementById calls just return null and this is a no-op until
// heroExitLesson() reattaches it and calls this again to catch up.
function heroSyncHeroView(preferredNext) {
  const pct = HERO_TOTAL > 0 ? Math.round(HERO_DONE_COUNT / HERO_TOTAL * 100) : 0;
  const fill = document.getElementById('hero-progress-fill');
  const pctEl = document.getElementById('hero-progress-pct');
  if (fill) fill.style.width = pct + '%';
  if (pctEl) pctEl.textContent = `${HERO_DONE_COUNT} / ${HERO_TOTAL} LESSONS`;
  const cta = document.getElementById('hero-cta-btn');
  if (cta) {
    if (HERO_DONE_COUNT >= HERO_TOTAL) {
      cta.textContent = 'Review Course →';
      cta.setAttribute('onclick', 'heroEnterLesson(' + HERO_LESSON_ORDER[0] + ')');
    } else {
      const next = preferredNext ?? heroFirstNotDone();
      if (next) {
        cta.textContent = (HERO_DONE_COUNT > 0 ? 'Continue Course' : 'Start Course') + ' →';
        cta.setAttribute('onclick', 'heroEnterLesson(' + next + ')');
      }
    }
  }
  heroPaintCert();
}

function heroUpdateProgress(lessonId) {
  if (HERO_ALREADY_DONE.has(lessonId)) return;
  HERO_ALREADY_DONE.add(lessonId);
  HERO_DONE_COUNT++;
  heroSyncFooterProgress();
  heroSyncHeroView(heroNextLessonId(lessonId));
}
function heroFirstNotDone() {
  for (const id of HERO_LESSON_ORDER) if (!HERO_ALREADY_DONE.has(id)) return id;
  return null;
}

// Cert data is cached here because it can arrive while #hero-view is detached
// (agent just finished the last lesson without having exited yet) -- heroPaintCert()
// is re-run from heroSyncHeroView() once #hero-view is back, so it always catches up.
let HERO_EARNED_CERT = null;
function heroShowCert(cert) {
  HERO_EARNED_CERT = cert;
  heroPaintCert();
}
function heroPaintCert() {
  if (!HERO_EARNED_CERT) return;
  const holder = document.getElementById('hero-cert-pending');
  if (!holder) return;
  holder.outerHTML = `<div class="lc-cert-card" id="hero-cert-pending" style="margin-top:24px">
    <div class="cert-title-dark" style="font-size:15px;font-weight:800;margin-bottom:4px">Certificate Earned!</div>
    <div class="cert-sub-dark" style="font-size:12px;margin-bottom:12px">Code: ${HERO_EARNED_CERT.cert_code}</div>
    <div><a href="university_certs.php?print=1&code=${encodeURIComponent(HERO_EARNED_CERT.cert_code)}" target="_blank" style="display:inline-block;padding:8px 20px;background:#8CC63E;color:#000;font-weight:700;font-size:13px;border-radius:4px;text-decoration:none">Print Certificate</a></div>
  </div>`;
}

function heroMarkComplete(lessonId) {
  const wasDone = HERO_ALREADY_DONE.has(lessonId);
  const btn = document.getElementById('mark-done-btn-' + lessonId);
  if (btn) btn.disabled = true;
  fetch('api/uni_progress.php', {
    method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'complete', lesson_id: lessonId}),
  }).then(r => r.json()).then(d => {
    if (!d.ok) return;
    const area = document.getElementById('complete-area-' + lessonId);
    if (area) area.innerHTML = '<span class="done-badge">✓ Lesson Complete</span>';
    heroUpdateProgress(lessonId);
    if (d.cert) heroShowCert(d.cert);
    if (!wasDone) heroAdvanceAfterCompletion(lessonId);
  });
}

function heroOnVideoEnd(lessonId) { if (!HERO_ALREADY_DONE.has(lessonId)) heroMarkComplete(lessonId); }
// heroScheduleComplete is now declared earlier, before the lesson loop -- see #hero-lesson-body above.

// ── Learner upload ───────────────────────────────────────────────────────
function heroHandleUploadDrop(e, lessonId) { e.preventDefault(); e.currentTarget.classList.remove('drag'); if (e.dataTransfer.files[0]) heroSubmitLearnerUpload(lessonId, e.dataTransfer.files[0]); }
function heroSubmitLearnerUpload(lessonId, file) {
  if (!file) return;
  const status = document.getElementById('upload-status-' + lessonId);
  if (status) status.textContent = 'Uploading…';
  const fd = new FormData();
  fd.append('action', 'submit_learner_upload');
  fd.append('lesson_id', lessonId);
  fd.append('file', file);
  fetch('api/uni_progress.php', {method: 'POST', credentials: 'same-origin', body: fd})
    .then(r => r.json()).then(d => {
      // Full reload here (same as university_lesson.php) rather than
      // re-rendering just this lesson's upload state client-side -- loses
      // scroll position, a known rough edge, not built out further since
      // uploads are outside what this build was asked to cover.
      if (d.ok) location.reload();
      else if (status) status.textContent = 'Error: ' + (d.error || 'upload failed');
    });
}

// ── Quiz ──────────────────────────────────────────────────────────────────
function heroSelectOption(lessonId, qIdx, optIdx, el, qtype) {
  const st = HERO_STATE[lessonId];
  if (qtype === 'multiple') {
    el.classList.toggle('selected');
    const set = new Set(st.answers[qIdx]);
    if (set.has(optIdx)) set.delete(optIdx); else set.add(optIdx);
    st.answers[qIdx] = [...set];
  } else {
    document.querySelectorAll(`#qcard-${lessonId}-${qIdx} .option-label`).forEach(l => l.classList.remove('selected'));
    el.classList.add('selected');
    st.answers[qIdx] = optIdx;
  }
}

function heroQuizNav(lessonId, dir) {
  const st = HERO_STATE[lessonId];
  document.getElementById(`qcard-${lessonId}-${st.currentQ}`).style.display = 'none';
  st.currentQ += dir;
  document.getElementById(`qcard-${lessonId}-${st.currentQ}`).style.display = '';
  document.getElementById(`quiz-progress-${lessonId}`).textContent = `Question ${st.currentQ + 1} of ${st.totalQ}`;
  document.getElementById(`quiz-prev-${lessonId}`).style.display = st.currentQ > 0 ? '' : 'none';
  const isLast = st.currentQ === st.totalQ - 1;
  document.getElementById(`quiz-next-${lessonId}`).style.display = isLast ? 'none' : '';
  document.getElementById(`quiz-submit-${lessonId}`).style.display = isLast ? '' : 'none';
  heroSyncQuizRail(lessonId); // keep the rail's progress pips in step with the visible question
}

function heroSubmitQuiz(lessonId) {
  const st = HERO_STATE[lessonId];
  const unanswered = st.answers.some((a, i) => st.qtypes[i] === 'multiple' ? a.length === 0 : (st.qtypes[i] === 'text' ? !String(a || '').trim() : a === null));
  if (unanswered) { alert('Please answer all questions before submitting.'); return; }
  const btn = document.getElementById('quiz-submit-' + lessonId);
  btn.disabled = true; btn.textContent = 'Grading…';
  const wasDone = HERO_ALREADY_DONE.has(lessonId);
  fetch('api/uni_progress.php', {
    method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'submit_quiz', lesson_id: lessonId, answers: st.answers}),
  }).then(r => r.json()).then(d => {
    const form = document.getElementById('quiz-form-' + lessonId);
    const result = document.getElementById('quiz-result-' + lessonId);
    const area = document.getElementById('complete-area-' + lessonId);
    if (d.passed) {
      form.style.display = 'none';
      result.style.display = '';
      result.innerHTML = `<div class="quiz-result pass"><div class="qr-score">${d.score}%</div><div class="qr-msg">Passed! ${d.correct} of ${d.total} correct.</div></div>`;
      area.innerHTML = '<span class="done-badge">✓ Quiz Passed</span>';
      heroUpdateProgress(lessonId);
      if (d.cert) heroShowCert(d.cert);
      if (!wasDone) heroAdvanceAfterCompletion(lessonId);
    } else {
      result.style.display = '';
      result.innerHTML = `<div class="quiz-result fail"><div class="qr-score">${d.score}%</div><div class="qr-msg">${d.correct} of ${d.total} correct — need ${d.pass_score ?? 70}% to pass.</div></div>`;
      btn.disabled = false; btn.textContent = 'Submit Quiz';
    }
  });
}

// ── Feedback lesson ──────────────────────────────────────────────────────────
// Per-lesson-id state (HERO_FB_STATE), unlike university_lesson.php's plain
// top-level globals -- every lesson in the course coexists on this one page,
// so state can't be a single set of globals the way a full-page-per-lesson
// reload gets away with.
function heroFbShowStep(lessonId, idx) {
  const st = HERO_FB_STATE[lessonId];
  document.querySelectorAll(`[id^="fb-step-${lessonId}-"]`).forEach(el => el.style.display = 'none');
  const el = document.getElementById(`fb-step-${lessonId}-${idx}`);
  if (el) el.style.display = '';
  st.step = idx;
  document.getElementById(`fb-progress-${lessonId}`).textContent = `Step ${idx + 1} of ${st.totalSteps}`;
  document.getElementById(`fb-prev-${lessonId}`).style.display = idx > 0 ? '' : 'none';
  document.getElementById(`fb-next-${lessonId}`).textContent = idx === st.totalSteps - 1 ? 'Submit' : 'Next →';
}

function heroFbFlushPending(lessonId) {
  const st = HERO_FB_STATE[lessonId];
  if (st && st.pending) { clearTimeout(st.pending.timer); st.pending.fn(); st.pending = null; }
}

function heroFbAutosave(lessonId, payload) {
  const hint = document.getElementById(`fb-save-hint-${lessonId}`);
  fetch('api/uni_progress.php', {
    method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(Object.assign({action: 'feedback_autosave', lesson_id: lessonId}, payload)),
  }).then(r => r.json()).then(d => {
    if (hint) { hint.textContent = d.ok ? 'Saved' : ''; if (d.ok) setTimeout(() => { if (hint.textContent === 'Saved') hint.textContent = ''; }, 1500); }
  });
}

function heroFbSelectRating(lessonId, qid, value, isNa, el) {
  document.querySelectorAll(`#fb-input-${lessonId}-${qid} .fb-rating-opt`).forEach(o => o.classList.remove('selected'));
  el.classList.add('selected');
  heroFbAutosave(lessonId, {question_id: qid, value_number: value, is_na: isNa ? 1 : 0}); // immediate
}
function heroFbSelectScale(lessonId, qid, value, el) {
  document.querySelectorAll(`#fb-input-${lessonId}-${qid} .fb-scale-opt`).forEach(o => o.classList.remove('selected'));
  el.classList.add('selected');
  heroFbAutosave(lessonId, {question_id: qid, value_number: value}); // immediate
}
function heroFbOnDateChange(lessonId, qid, el) {
  heroFbAutosave(lessonId, {question_id: qid, value_text: el.value}); // immediate
}
function heroFbOnTextInput(lessonId, qid, el) {
  // Cancel the previous pending save without firing it -- a genuine debounce, not a
  // flush-on-every-keystroke (heroFbFlushPending() is for Back/Next/blur only, below).
  const st = HERO_FB_STATE[lessonId];
  if (st.pending) clearTimeout(st.pending.timer);
  const value = el.value;
  const timer = setTimeout(() => { heroFbAutosave(lessonId, {question_id: qid, value_text: value}); st.pending = null; }, 600); // debounced
  st.pending = { timer, fn: () => heroFbAutosave(lessonId, {question_id: qid, value_text: el.value}) };
}

function heroFbNav(lessonId, dir) {
  heroFbFlushPending(lessonId);
  const st = HERO_FB_STATE[lessonId];
  if (dir > 0 && st.step === st.totalSteps - 1) { heroFbSubmit(lessonId); return; }
  const next = st.step + dir;
  if (next < 0 || next >= st.totalSteps) return;
  heroFbShowStep(lessonId, next);
  heroFbAutosave(lessonId, {current_step: next});
}

function heroFbSubmit(lessonId) {
  heroFbFlushPending(lessonId);
  const btn = document.getElementById(`fb-next-${lessonId}`);
  const wasDone = HERO_ALREADY_DONE.has(lessonId);
  if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
  fetch('api/uni_progress.php', {
    method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'feedback_submit', lesson_id: lessonId}),
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      document.querySelectorAll(`[id^="fb-step-${lessonId}-"]`).forEach(el => el.style.display = 'none');
      const nav = btn ? btn.closest('.fb-nav') : null;
      if (nav) nav.style.display = 'none';
      const area = document.getElementById('complete-area-' + lessonId);
      if (area) area.innerHTML = '<span class="done-badge">✓ Lesson Complete</span>';
      heroUpdateProgress(lessonId);
      if (d.cert) heroShowCert(d.cert);
      if (!wasDone) heroAdvanceAfterCompletion(lessonId);
    } else {
      alert(d.error || 'Could not submit');
      if (btn) { btn.disabled = false; btn.textContent = 'Submit'; }
    }
  });
}

// ── Lazy-load media as lessons approach the viewport, scoped to the card's
// own internal scroll container rather than the window ──────────────────
document.addEventListener('DOMContentLoaded', () => {
  const scrollRoot = document.getElementById('hero-lesson-body');
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el = entry.target;
      observer.unobserve(el);
      if (el.tagName === 'IFRAME' && el.dataset.src) { el.src = el.dataset.src; return; }
      const slot = el.querySelector('.lazy-media-slot');
      if (slot && el.dataset.src) {
        const iframe = document.createElement('iframe');
        iframe.src = el.dataset.src;
        iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;border:0';
        iframe.allowFullscreen = true;
        iframe.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture');
        slot.replaceWith(iframe);
        return;
      }
      const source = el.querySelector('video source[data-src]');
      if (source) { source.src = source.dataset.src; el.querySelector('video').load(); }
    });
  }, {root: scrollRoot, rootMargin: '200px 0px'});
  document.querySelectorAll('.lazy-media').forEach(el => observer.observe(el));
});
</script>
<?php else: ?>
<script>
(function(){
  var KEY = 'uni_collapsed_folders_<?= $courseId ?>';
  var headers = document.querySelectorAll('.folder-header');
  var raw = localStorage.getItem(KEY);
  // The server already rendered aria-expanded="true" on the folder holding
  // the agent's next incomplete lesson (or the first folder, if none). Only
  // a saved preference from a prior visit should override that — with no
  // saved preference yet, leave the server-rendered state alone so there's
  // no flash of the wrong folder opening/closing on first paint.
  var collapsed = null;
  if (raw !== null) {
    try { collapsed = new Set(JSON.parse(raw)); } catch(e) { collapsed = new Set(); }
  }
  function saveCollapsed() { localStorage.setItem(KEY, JSON.stringify([...collapsed])); }
  headers.forEach(function(hdr){
    var id = hdr.dataset.folder;
    if (collapsed) hdr.setAttribute('aria-expanded', String(!collapsed.has(id)));
    hdr.addEventListener('click', function(){
      if (!collapsed) {
        // First toggle with no saved preference yet — seed it from
        // whatever's currently rendered so untouched folders keep their
        // server-chosen open/closed state instead of all snapping shut.
        collapsed = new Set();
        headers.forEach(function(h){ if (h.getAttribute('aria-expanded') !== 'true') collapsed.add(h.dataset.folder); });
      }
      var isNowCollapsed = hdr.getAttribute('aria-expanded') === 'true';
      hdr.setAttribute('aria-expanded', String(!isNowCollapsed));
      if (isNowCollapsed) collapsed.add(id); else collapsed.delete(id);
      saveCollapsed();
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
