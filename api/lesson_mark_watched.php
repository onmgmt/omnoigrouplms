<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
$u = require_login();
require_csrf();

$body = read_json_body();
$lessonId = (int)($body['lesson_id'] ?? 0);
if (!$lessonId) json_error('ไม่พบบทเรียน', 400);

$stmt = db()->prepare(
  'SELECT l.id, l.course_id, c.department_id, c.level FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ?'
);
$stmt->execute([$lessonId]);
$lesson = $stmt->fetch();
if (!$lesson) json_error('ไม่พบบทเรียน', 404);

if ($u['role'] !== 'admin') {
  if (!user_in_department($u['id'], (int)$lesson['department_id'])) json_error('ไม่มีสิทธิ์เข้าถึงคอร์สนี้', 403);
  if (course_is_locked_for_user($u['id'], (int)$lesson['department_id'], (int)$lesson['level'])) {
    json_error('คอร์สนี้ยังไม่ปลดล็อก ต้องผ่านคอร์สก่อนหน้าก่อน', 423);
  }
}

$ins = db()->prepare('INSERT IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)');
$ins->execute([$u['id'], $lessonId]);
$alreadyExisted = $ins->rowCount() === 0;

if (!$alreadyExisted) {
  log_event($u['id'], 'video_open', (int)$lesson['course_id'], $lessonId, null, $u['username']);
}

json_response(['ok' => true, 'already' => $alreadyExisted]);
