<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
$u = require_login();
require_csrf();

$body = read_json_body();
$lessonId = (int)($body['lesson_id'] ?? 0);
if (!$lessonId) json_error('ไม่พบบทเรียน', 400);

$stmt = db()->prepare(
  'SELECT l.id, l.course_id, l.drive_doc_id, c.department_id,
          EXISTS(SELECT 1 FROM lesson_progress lp WHERE lp.user_id = ? AND lp.lesson_id = l.id) AS watched
   FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ?'
);
$stmt->execute([$u['id'], $lessonId]);
$lesson = $stmt->fetch();
if (!$lesson) json_error('ไม่พบบทเรียน', 404);
if (!$lesson['drive_doc_id']) json_error('บทนี้ไม่มีเอกสารประกอบ', 400);

if ($u['role'] !== 'admin') {
  if (!user_in_department($u['id'], (int)$lesson['department_id'])) json_error('ไม่มีสิทธิ์เข้าถึงคอร์สนี้', 403);
  if (!$lesson['watched']) json_error('ต้องดูวิดีโอของบทนี้ให้จบก่อนจึงจะดาวน์โหลดเอกสารได้', 423);
}

log_event($u['id'], 'doc_download', (int)$lesson['course_id'], $lessonId, null, $u['username']);

json_response(['ok' => true]);
