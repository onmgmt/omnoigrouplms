<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');

$u = require_login();
$courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$courseId) json_error('ไม่พบคอร์ส', 404);

$stmt = db()->prepare(
  'SELECT c.*, d.name AS department_name FROM courses c JOIN departments d ON d.id = c.department_id WHERE c.id = ?'
);
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course) json_error('ไม่พบคอร์ส', 404);

$locked = false;
if ($u['role'] !== 'admin') {
  if (!user_in_department($u['id'], (int)$course['department_id'])) {
    json_error('คุณไม่ได้สังกัดแผนกของคอร์สนี้', 403);
  }
  $locked = course_is_locked_for_user($u['id'], (int)$course['department_id'], (int)$course['level']);
}

$lstmt = db()->prepare(
  'SELECT l.id, l.order_index, l.title, l.drive_video_id, l.drive_doc_id, l.dur,
          EXISTS(SELECT 1 FROM lesson_progress lp WHERE lp.user_id = ? AND lp.lesson_id = l.id) AS watched
   FROM lessons l WHERE l.course_id = ? ORDER BY l.order_index'
);
$lstmt->execute([$u['id'], $courseId]);
$lessons = array_map(function ($l) {
  return [
    'id' => (int)$l['id'],
    'title' => $l['title'],
    'drive_video_id' => $l['drive_video_id'],
    'drive_doc_id' => $l['drive_doc_id'],
    'dur' => (int)$l['dur'],
    'watched' => (bool)$l['watched'],
  ];
}, $lstmt->fetchAll());

$qCountStmt = db()->prepare('SELECT COUNT(*) AS n FROM quiz_questions WHERE course_id = ?');
$qCountStmt->execute([$courseId]);
$quizCount = (int)$qCountStmt->fetch()['n'];

$attStmt = db()->prepare('SELECT score, submitted_at FROM quiz_attempts WHERE user_id = ? AND course_id = ? ORDER BY submitted_at DESC');
$attStmt->execute([$u['id'], $courseId]);
$attempts = $attStmt->fetchAll();
$bestScore = null;
foreach ($attempts as $a) $bestScore = max($bestScore ?? -1, (int)$a['score']);

json_response([
  'course' => [
    'id' => (int)$course['id'],
    'department_id' => (int)$course['department_id'],
    'department_name' => $course['department_name'],
    'title' => $course['title'],
    'description' => $course['description'],
    'icon' => $course['icon'],
    'color' => $course['color'],
    'level' => (int)$course['level'],
    'locked' => $locked,
  ],
  'lessons' => $lessons,
  'quiz_count' => $quizCount,
  'best_score' => $bestScore,
  'attempts' => count($attempts),
  'last_date' => $attempts ? substr($attempts[0]['submitted_at'], 0, 10) : null,
]);
