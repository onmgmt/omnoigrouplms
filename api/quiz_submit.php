<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
$u = require_login();
require_csrf();

$body = read_json_body();
$courseId = (int)($body['course_id'] ?? 0);
$answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
if (!$courseId) json_error('ไม่พบคอร์ส', 400);

$cstmt = db()->prepare('SELECT department_id, level FROM courses WHERE id = ?');
$cstmt->execute([$courseId]);
$course = $cstmt->fetch();
if (!$course) json_error('ไม่พบคอร์ส', 404);

if ($u['role'] !== 'admin') {
  if (!user_in_department($u['id'], (int)$course['department_id'])) json_error('ไม่มีสิทธิ์เข้าถึงคอร์สนี้', 403);
  if (course_is_locked_for_user($u['id'], (int)$course['department_id'], (int)$course['level'])) {
    json_error('คอร์สนี้ยังไม่ปลดล็อก', 423);
  }
}

$qstmt = db()->prepare('SELECT id, correct_index FROM quiz_questions WHERE course_id = ?');
$qstmt->execute([$courseId]);
$questions = $qstmt->fetchAll();
if (!$questions) json_error('คอร์สนี้ยังไม่มีแบบทดสอบ', 400);

$correctCount = 0;
$details = [];
foreach ($questions as $q) {
  $given = $answers[(string)$q['id']] ?? $answers[$q['id']] ?? null;
  $given = $given !== null ? (int)$given : null;
  $isCorrect = $given !== null && $given === (int)$q['correct_index'];
  if ($isCorrect) $correctCount++;
  // ส่งเฉลยกลับหลัง "ส่งคำตอบแล้ว" เท่านั้น (ไม่ส่งใน quiz_get.php ก่อนทำข้อสอบ) เพื่อให้ frontend ไฮไลต์ถูก/ผิดได้เหมือนเดิม
  $details[] = ['question_id' => (int)$q['id'], 'given' => $given, 'correct_index' => (int)$q['correct_index']];
}
$total = count($questions);
$score = (int)round($correctCount / $total * 100);

$attStmt = db()->prepare('SELECT COUNT(*) AS n FROM quiz_attempts WHERE user_id = ? AND course_id = ?');
$attStmt->execute([$u['id'], $courseId]);
$attemptNo = (int)$attStmt->fetch()['n'] + 1;

db()->prepare(
  'INSERT INTO quiz_attempts (user_id, course_id, score, correct_count, total_count, attempt_no) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$u['id'], $courseId, $score, $correctCount, $total, $attemptNo]);

log_event($u['id'], 'quiz_submit', $courseId, null, ['score' => $score, 'correct' => $correctCount, 'total' => $total, 'attempt_no' => $attemptNo], $u['username']);

$passed = $score >= PASS_MARK;
if ($passed) {
  $wasCompleted = db()->prepare('SELECT best_score FROM course_completions WHERE user_id = ? AND course_id = ?');
  $wasCompleted->execute([$u['id'], $courseId]);
  $existing = $wasCompleted->fetch();

  db()->prepare(
    'INSERT INTO course_completions (user_id, course_id, best_score) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE best_score = GREATEST(best_score, VALUES(best_score))'
  )->execute([$u['id'], $courseId, $score]);

  if (!$existing) {
    log_event($u['id'], 'course_complete', $courseId, null, ['score' => $score], $u['username']);
  }
}

json_response([
  'score' => $score,
  'correct' => $correctCount,
  'total' => $total,
  'passed' => $passed,
  'attempt_no' => $attemptNo,
  'details' => $details,
]);
