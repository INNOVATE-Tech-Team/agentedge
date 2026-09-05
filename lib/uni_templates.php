<?php
// On-Demand Course Templates — instantiate/snapshot/apply-update logic shared
// by api/uni_action.php (course-side actions) and api/uni_template_action.php
// (template-side actions). See local_db.php's "On-Demand Course Templates"
// schema block for the table shapes this operates on.

// Settings columns copied verbatim from a template onto a newly instantiated
// course, and re-synced (unconditionally overwritten) by apply_template_update().
// overview_* is deliberately excluded from both lists below — those are seeded
// once at instantiation and are thereafter course content, edited like
// title/description, not resynced on template update.
const UNI_TEMPLATE_SETTINGS_COLUMNS = [
    'sequencing_mode', 'quiz_pass_score', 'quiz_retake_policy', 'quiz_max_attempts',
    'quiz_block_on_fail', 'cert_enabled', 'cert_expiry_months', 'cert_design', 'layout_style',
];
const UNI_TEMPLATE_OVERVIEW_COLUMNS = ['overview_audience', 'overview_outcome', 'overview_estimated_minutes'];

// Copies a template's folders/lessons/questions + settings into a brand-new
// course. A genuine frozen copy: template_id is provenance/audit metadata
// only, never dereferenced by any learner-facing or rendering code path.
function instantiate_course_from_template(PDO $db, int $templateId, string $title, ?int $categoryId, string $createdBy): int {
    $tStmt = $db->prepare("SELECT * FROM uni_templates WHERE id=?");
    $tStmt->execute([$templateId]);
    $tpl = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) throw new \InvalidArgumentException('template not found');

    $cols = array_merge(UNI_TEMPLATE_SETTINGS_COLUMNS, UNI_TEMPLATE_OVERVIEW_COLUMNS);
    $vals = array_map(fn($c) => $tpl[$c], $cols);

    $db->prepare(
        "INSERT INTO uni_courses (category_id,title,description,is_required,sort_ord,published,created_by,invite_only,state_filter,role_filter,
                                   course_type,template_id," . implode(',', $cols) . ")
         VALUES (?,?,?,0,0,0,?,0,'[]','[]','on_demand',?," . implode(',', array_fill(0, count($cols), '?')) . ")"
    )->execute(array_merge([$categoryId, $title, $tpl['description'], $createdBy, $templateId], $vals));
    $courseId = (int)$db->lastInsertId();

    $folderIdMap = []; // template_folders.id => new uni_folders.id
    $folders = $db->prepare("SELECT * FROM uni_template_folders WHERE template_id=? ORDER BY sort_ord,id");
    $folders->execute([$templateId]);
    foreach ($folders->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $db->prepare("INSERT INTO uni_folders (course_id,title,code,description,sort_ord,template_item_id) VALUES (?,?,?,?,?,?)")
           ->execute([$courseId, $f['title'], $f['code'], $f['description'], $f['sort_ord'], $f['id']]);
        $folderIdMap[$f['id']] = (int)$db->lastInsertId();
    }

    $lessonIdMap = []; // template_lessons.id => new uni_lessons.id
    $lessons = $db->prepare("SELECT * FROM uni_template_lessons WHERE template_id=? ORDER BY sort_ord,id");
    $lessons->execute([$templateId]);
    foreach ($lessons->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $folderId = $l['folder_id'] !== null ? ($folderIdMap[$l['folder_id']] ?? null) : null;
        $db->prepare(
            "INSERT INTO uni_lessons (course_id,folder_id,title,sort_ord,type,section_kind,embed_url,content_html,duration_sec,tags,learning_objective,difficulty,template_item_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([$courseId, $folderId, $l['title'], $l['sort_ord'], $l['type'], $l['section_kind'], $l['embed_url'], $l['content_html'], $l['duration_sec'], $l['tags'], $l['learning_objective'], $l['difficulty'], $l['id']]);
        $lessonIdMap[$l['id']] = (int)$db->lastInsertId();
    }

    $questions = $db->prepare(
        "SELECT q.* FROM uni_template_questions q JOIN uni_template_lessons l ON l.id=q.template_lesson_id WHERE l.template_id=? ORDER BY q.sort_ord,q.id"
    );
    $questions->execute([$templateId]);
    foreach ($questions->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $lessonId = $lessonIdMap[$q['template_lesson_id']] ?? null;
        if (!$lessonId) continue; // orphaned template question — shouldn't happen, but don't attach to nothing
        $db->prepare(
            "INSERT INTO uni_questions (lesson_id,question,options,correct_index,correct_indexes,qtype,sort_ord,template_item_id)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$lessonId, $q['question'], $q['options'], $q['correct_index'], $q['correct_indexes'], $q['qtype'], $q['sort_ord'], $q['id']]);
    }

    return $courseId;
}

// Read-only diff for the Apply Template Update confirmation modal — nothing
// here writes. Returns ['settings' => [{field, old, new}, ...], 'folders_to_add' => n, 'lessons_to_add' => n, 'questions_to_add' => n].
function preview_template_update(PDO $db, int $courseId): array {
    $course = fetch_course_for_template_update($db, $courseId);
    $tpl    = fetch_template_for_course($db, $course);

    $settingsDiff = [];
    foreach (UNI_TEMPLATE_SETTINGS_COLUMNS as $c) {
        if ((string)$course[$c] !== (string)$tpl[$c]) {
            $settingsDiff[] = ['field' => $c, 'old' => $course[$c], 'new' => $tpl[$c]];
        }
    }

    [$foldersToAdd, $lessonsToAdd, $questionsToAdd] = diff_template_structure($db, $courseId, (int)$course['template_id']);

    return [
        'settings' => $settingsDiff,
        'folders_to_add' => count($foldersToAdd),
        'lessons_to_add' => count($lessonsToAdd),
        'questions_to_add' => count($questionsToAdd),
    ];
}

// The actual write — only ever called after the admin confirms the preview.
function apply_template_update(PDO $db, int $courseId): array {
    $course = fetch_course_for_template_update($db, $courseId);
    $tpl    = fetch_template_for_course($db, $course);

    // Settings sync: unconditional overwrite of exactly the confirmed fields.
    $set = implode(',', array_map(fn($c) => "$c=?", UNI_TEMPLATE_SETTINGS_COLUMNS));
    $vals = array_map(fn($c) => $tpl[$c], UNI_TEMPLATE_SETTINGS_COLUMNS);
    $db->prepare("UPDATE uni_courses SET $set WHERE id=?")->execute([...$vals, $courseId]);

    [$foldersToAdd, $lessonsToAdd, $questionsToAdd] = diff_template_structure($db, $courseId, (int)$course['template_id']);

    $folderIdMap = [];
    // Folders already in the course (including ones from this template) map by template_item_id.
    $existing = $db->prepare("SELECT id, template_item_id FROM uni_folders WHERE course_id=? AND template_item_id IS NOT NULL");
    $existing->execute([$courseId]);
    foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $r) { $folderIdMap[$r['template_item_id']] = (int)$r['id']; }

    foreach ($foldersToAdd as $f) {
        $db->prepare("INSERT INTO uni_folders (course_id,title,code,description,sort_ord,template_item_id) VALUES (?,?,?,?,?,?)")
           ->execute([$courseId, $f['title'], $f['code'], $f['description'], $f['sort_ord'], $f['id']]);
        $folderIdMap[$f['id']] = (int)$db->lastInsertId();
    }

    $lessonIdMap = [];
    $existingLessons = $db->prepare("SELECT id, template_item_id FROM uni_lessons WHERE course_id=? AND template_item_id IS NOT NULL");
    $existingLessons->execute([$courseId]);
    foreach ($existingLessons->fetchAll(PDO::FETCH_ASSOC) as $r) { $lessonIdMap[$r['template_item_id']] = (int)$r['id']; }

    foreach ($lessonsToAdd as $l) {
        $folderId = $l['folder_id'] !== null ? ($folderIdMap[$l['folder_id']] ?? null) : null;
        $db->prepare(
            "INSERT INTO uni_lessons (course_id,folder_id,title,sort_ord,type,section_kind,embed_url,content_html,duration_sec,tags,learning_objective,difficulty,template_item_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([$courseId, $folderId, $l['title'], $l['sort_ord'], $l['type'], $l['section_kind'], $l['embed_url'], $l['content_html'], $l['duration_sec'], $l['tags'], $l['learning_objective'], $l['difficulty'], $l['id']]);
        $lessonIdMap[$l['id']] = (int)$db->lastInsertId();
    }

    foreach ($questionsToAdd as $q) {
        $lessonId = $lessonIdMap[$q['template_lesson_id']] ?? null;
        if (!$lessonId) continue;
        $db->prepare(
            "INSERT INTO uni_questions (lesson_id,question,options,correct_index,correct_indexes,qtype,sort_ord,template_item_id)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$lessonId, $q['question'], $q['options'], $q['correct_index'], $q['correct_indexes'], $q['qtype'], $q['sort_ord'], $q['id']]);
    }

    return [
        'folders_added' => count($foldersToAdd),
        'lessons_added' => count($lessonsToAdd),
        'questions_added' => count($questionsToAdd),
    ];
}

// Walks an existing course's folders/lessons/questions into new uni_template_*
// rows, and creates the uni_templates row from the course's current settings.
// This is how a template is bootstrapped from a manually-built course (see
// build-order step 4) rather than authored from scratch.
function snapshot_course_as_template(PDO $db, int $courseId, string $name, string $description, string $createdBy): int {
    $course = $db->prepare("SELECT * FROM uni_courses WHERE id=?");
    $course->execute([$courseId]);
    $course = $course->fetch(PDO::FETCH_ASSOC);
    if (!$course) throw new \InvalidArgumentException('course not found');

    // Feedback/template round-trip isn't built yet: the lesson row below would
    // copy fine (type='feedback' is just a column value like any other), but
    // nothing here knows to copy uni_feedback_questions the way the block
    // further down copies uni_questions for quiz lessons -- the template
    // would silently end up with an empty Feedback lesson. Block outright
    // instead of shipping that gap quietly; lift this once Feedback has real
    // template support.
    $fbCheck = $db->prepare("SELECT COUNT(*) FROM uni_lessons WHERE course_id=? AND type='feedback'");
    $fbCheck->execute([$courseId]);
    if ((int)$fbCheck->fetchColumn() > 0) {
        throw new \InvalidArgumentException('This course has one or more Feedback lessons, which templates don\'t support yet — remove them before saving as a template, or save a copy without them.');
    }

    $cols = array_merge(UNI_TEMPLATE_SETTINGS_COLUMNS, UNI_TEMPLATE_OVERVIEW_COLUMNS);
    $vals = array_map(fn($c) => $course[$c], $cols);
    $db->prepare("INSERT INTO uni_templates (name,description,created_by," . implode(',', $cols) . ") VALUES (?,?,?," . implode(',', array_fill(0, count($cols), '?')) . ")")
       ->execute(array_merge([$name, $description, $createdBy], $vals));
    $templateId = (int)$db->lastInsertId();

    $folderIdMap = [];
    $folders = $db->prepare("SELECT * FROM uni_folders WHERE course_id=? ORDER BY sort_ord,id");
    $folders->execute([$courseId]);
    foreach ($folders->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $db->prepare("INSERT INTO uni_template_folders (template_id,title,code,description,sort_ord) VALUES (?,?,?,?,?)")
           ->execute([$templateId, $f['title'], $f['code'], $f['description'], $f['sort_ord']]);
        $folderIdMap[$f['id']] = (int)$db->lastInsertId();
    }

    $lessonIdMap = [];
    $lessons = $db->prepare("SELECT * FROM uni_lessons WHERE course_id=? ORDER BY sort_ord,id");
    $lessons->execute([$courseId]);
    foreach ($lessons->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $folderId = $l['folder_id'] !== null ? ($folderIdMap[$l['folder_id']] ?? null) : null;
        $db->prepare(
            "INSERT INTO uni_template_lessons (template_id,folder_id,title,sort_ord,type,section_kind,embed_url,content_html,duration_sec,tags,learning_objective,difficulty)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([$templateId, $folderId, $l['title'], $l['sort_ord'], $l['type'], $l['section_kind'], $l['embed_url'], $l['content_html'], $l['duration_sec'], $l['tags'], $l['learning_objective'], $l['difficulty']]);
        $lessonIdMap[$l['id']] = (int)$db->lastInsertId();
    }

    $questions = $db->prepare("SELECT q.* FROM uni_questions q WHERE q.lesson_id IN (SELECT id FROM uni_lessons WHERE course_id=?) ORDER BY q.sort_ord,q.id");
    $questions->execute([$courseId]);
    foreach ($questions->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $templateLessonId = $lessonIdMap[$q['lesson_id']] ?? null;
        if (!$templateLessonId) continue;
        $db->prepare(
            "INSERT INTO uni_template_questions (template_lesson_id,question,options,correct_index,correct_indexes,qtype,sort_ord)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$templateLessonId, $q['question'], $q['options'], $q['correct_index'], $q['correct_indexes'], $q['qtype'], $q['sort_ord']]);
    }

    return $templateId;
}

// ── internal helpers ─────────────────────────────────────────────────────

function fetch_course_for_template_update(PDO $db, int $courseId): array {
    $stmt = $db->prepare("SELECT * FROM uni_courses WHERE id=?");
    $stmt->execute([$courseId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) throw new \InvalidArgumentException('course not found');
    if (empty($course['template_id'])) throw new \InvalidArgumentException('course was not created from a template');
    return $course;
}

function fetch_template_for_course(PDO $db, array $course): array {
    $stmt = $db->prepare("SELECT * FROM uni_templates WHERE id=?");
    $stmt->execute([$course['template_id']]);
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) throw new \InvalidArgumentException('source template no longer exists');
    return $tpl;
}

// Returns [foldersToAdd, lessonsToAdd, questionsToAdd] — template rows that
// don't yet exist in the course (by template_item_id) and aren't tombstoned
// as deliberately removed. Read-only; used by both preview and apply.
function diff_template_structure(PDO $db, int $courseId, int $templateId): array {
    $tombstoned = ['folder' => [], 'lesson' => [], 'question' => []];
    $ts = $db->prepare("SELECT template_item_id, item_type FROM uni_template_removed_items WHERE course_id=?");
    $ts->execute([$courseId]);
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $r) { $tombstoned[$r['item_type']][] = (int)$r['template_item_id']; }

    $existingFolderTplIds = $db->prepare("SELECT template_item_id FROM uni_folders WHERE course_id=? AND template_item_id IS NOT NULL");
    $existingFolderTplIds->execute([$courseId]);
    $existingFolderTplIds = array_map('intval', $existingFolderTplIds->fetchAll(PDO::FETCH_COLUMN));

    $existingLessonTplIds = $db->prepare("SELECT template_item_id FROM uni_lessons WHERE course_id=? AND template_item_id IS NOT NULL");
    $existingLessonTplIds->execute([$courseId]);
    $existingLessonTplIds = array_map('intval', $existingLessonTplIds->fetchAll(PDO::FETCH_COLUMN));

    $existingQuestionTplIds = $db->prepare(
        "SELECT template_item_id FROM uni_questions WHERE template_item_id IS NOT NULL AND lesson_id IN (SELECT id FROM uni_lessons WHERE course_id=?)"
    );
    $existingQuestionTplIds->execute([$courseId]);
    $existingQuestionTplIds = array_map('intval', $existingQuestionTplIds->fetchAll(PDO::FETCH_COLUMN));

    $folders = $db->prepare("SELECT * FROM uni_template_folders WHERE template_id=? ORDER BY sort_ord,id");
    $folders->execute([$templateId]);
    $foldersToAdd = array_values(array_filter($folders->fetchAll(PDO::FETCH_ASSOC), fn($f) =>
        !in_array($f['id'], $existingFolderTplIds, true) && !in_array($f['id'], $tombstoned['folder'], true)
    ));

    $lessons = $db->prepare("SELECT * FROM uni_template_lessons WHERE template_id=? ORDER BY sort_ord,id");
    $lessons->execute([$templateId]);
    $lessonsToAdd = array_values(array_filter($lessons->fetchAll(PDO::FETCH_ASSOC), fn($l) =>
        !in_array($l['id'], $existingLessonTplIds, true) && !in_array($l['id'], $tombstoned['lesson'], true)
    ));

    $questions = $db->prepare(
        "SELECT q.* FROM uni_template_questions q JOIN uni_template_lessons l ON l.id=q.template_lesson_id WHERE l.template_id=? ORDER BY q.sort_ord,q.id"
    );
    $questions->execute([$templateId]);
    $questionsToAdd = array_values(array_filter($questions->fetchAll(PDO::FETCH_ASSOC), fn($q) =>
        !in_array($q['id'], $existingQuestionTplIds, true) && !in_array($q['id'], $tombstoned['question'], true)
    ));

    return [$foldersToAdd, $lessonsToAdd, $questionsToAdd];
}
