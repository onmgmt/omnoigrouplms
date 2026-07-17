<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');

$u = require_login();
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if (!$courseId) json_error('ไม่พบคอร์ส', 404);

$cstmt = db()->prepare('SELECT department_id, level, title, published FROM courses WHERE id = ?');
$cstmt->execute([$courseId]);
$course = $cstmt->fetch();
if (!$course) json_error('ไม่พบคอร์ส', 404);

if ($u['role'] !== 'admin') {
  if (!(int)$course['published']) json_error('ไม่พบคอร์ส', 404);
  if (!user_in_department($u['id'], (int)$course['department_id'])) json_error('ไม่มีสิทธิ์เข้าถึงคอร์สนี้', 403);
  if (course_is_locked_for_user($u['id'], (int)$course['department_id'], (int)$course['level'])) {
    json_error('คอร์สนี้ยังไม่ปลดล็อก', 423);
  }
  $lstmt = db()->prepare(
    'SELECT COUNT(*) AS total, SUM(EXISTS(SELECT 1 FROM lesson_progress lp WHERE lp.user_id=? AND lp.lesson_id=l.id)) AS done
     FROM lessons l WHERE l.course_id = ?'
  );
  $lstmt->execute([$u['id'], $courseId]);
  $lp = $lstmt->fetch();
  if ((int)$lp['total'] > 0 && (int)$lp['done'] < (int)$lp['total']) {
    json_error('ต้องดูวิดีโอให้ครบทุกบทก่อนทำแบบทดสอบ', 423);
  }
}

$qstmt = db()->prepare('SELECT id, order_index, question, options FROM quiz_questions WHERE course_id = ? ORDER BY order_index');
$qstmt->execute([$courseId]);
$questions = array_map(function ($q) {
  return [
    'id' => (int)$q['id'],
    'question' => $q['question'],
    'options' => json_decode($q['options'], true) ?: [],
  ];
}, $qstmt->fetchAll());

json_response(['course_title' => $course['title'], 'questions' => $questions, 'pass_mark' => PASS_MARK]);
