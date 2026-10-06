<?php
// Shared data-shaping helpers for the Feedback lesson type, used by both the
// Standard renderer (university_lesson.php) and the On-Demand renderer
// (university_course.php's render_hero_lesson_content()). Rendering itself
// is deliberately separate per renderer -- only this data prep is shared, so
// both always agree on step/progress counts derived from the same content.

// Groups a lesson's questions (already ordered by sort_ord,id) into the
// step list a learner walks through: at most one 'intro' step up front
// containing every is_intro_field question together, then one 'question'
// step per remaining question. Counts are always derived from this list --
// never hardcoded -- so total steps changes automatically as questions are
// added/removed/reflagged.
function feedback_build_steps(array $questions): array {
    $introQs = array_values(array_filter($questions, fn($q) => !empty($q['is_intro_field'])));
    $stepQs  = array_values(array_filter($questions, fn($q) => empty($q['is_intro_field'])));
    $steps = [];
    if ($introQs) { $steps[] = ['type' => 'intro', 'questions' => $introQs]; }
    foreach ($stepQs as $q) { $steps[] = ['type' => 'question', 'question' => $q]; }
    return $steps;
}

// Only ever pulls from the values Agent Edge already has reliably for the
// authenticated session -- never invents cohort/facilitator/market-center
// data. Returns '' for anything else, including an unrecognized prefill key.
function feedback_prefill_value(string $prefillKey, array $agent): string {
    if ($prefillKey === 'agent_name') return (string)($agent['name'] ?? '');
    if ($prefillKey === 'agent_email') return (string)($agent['email'] ?? '');
    return '';
}
