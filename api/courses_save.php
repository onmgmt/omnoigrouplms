<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
require_admin();
require_csrf();

$body = read_json_body();
$departmentId = (int)($body['department_id'] ?? 0);
$title = trim((string)($body['title'] ?? ''));
$description = trim((string)($body['description'] ?? ''));
$icon = trim((string)($body['icon'] ?? '')) ?: '🎓';
$color = trim((string)($body['color'] ?? '')) ?: '#003149';
$level = isset($body['level']) && (int)$body['level'] > 0 ? (int)$body['level'] : null;
$lessonsIn = is_array($body['lessons'] ?? null) ? $body['lessons'] : [];
$quizIn = is_array($body['quiz'] ?? null) ? $body['quiz'] : [];

if (!$departmentId) json_error('กรุณาเลือกแผนก', 400);
if ($title === '') json_error('กรุณากรอกชื่อหลักสูตร', 400);

$dchk = db()->prepare('SELECT 1 FROM departments WHERE id = ?');
$dchk->execute([$departmentId]);
if (!$dchk->fetch()) json_error('ไม่พบแผนกที่เลือก', 400);

$lessons = [];
foreach ($lessonsIn as $i => $l) {
  $lt = trim((string)($l['title'] ?? ''));
  if ($lt === '') continue;
  $lessons[] = [
    'title' => $lt,
    'drive_video_id' => extract_drive_id((string)($l['driveId'] ?? '')),
    'drive_doc_id' => extract_drive_id((string)($l['docId'] ?? '')),
    'dur' => 20 + count($lessons) * 3,
  ];
}
if (!$lessons) json_error('กรุณาเพิ่มบทเรียนอย่างน้อย 1 บท', 400);

$quiz = [];
foreach ($quizIn as $q) {
  $qt = trim((string)($q['question'] ?? ''));
  $opts = is_array($q['options'] ?? null) ? array_values(array_filter(array_map('trim', $q['options']), fn($o) => $o !== '')) : [];
  $correct = (int)($q['correct_index'] ?? -1);
  if ($qt === '' || count($opts) < 2 || $correct < 0 || $correct >= count($opts)) continue;
  $quiz[] = ['question' => $qt, 'options' => $opts, 'correct_index' => $correct];
}
if (!$quiz) {
  // ไม่มีข้อสอบส่งมา -> สร้างตัวอย่าง 2 ข้อให้แก้ไขภายหลัง (พฤติกรรมเดิมของระบบ)
  $quiz = [
    ['question' => 'ตัวอย่างคำถามที่ 1 ของหลักสูตรนี้ (แก้ไขภายหลังผ่านฐานข้อมูล)', 'options' => ['ตัวเลือก ก', 'ตัวเลือก ข (ถูก)', 'ตัวเลือก ค', 'ตัวเลือก ง'], 'correct_index' => 1],
    ['question' => 'ตัวอย่างคำถามที่ 2 ของหลักสูตรนี้', 'options' => ['ตัวเลือก ก (ถูก)', 'ตัวเลือก ข', 'ตัวเลือก ค', 'ตัวเลือก ง'], 'correct_index' => 0],
  ];
}

function extract_drive_id(string $input): string {
  $input = trim($input);
  if ($input === '') return '';
  if (preg_match('#/d/([a-zA-Z0-9_-]{10,})#', $input, $m)) return $m[1];
  if (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,})#', $input, $m)) return $m[1];
  return $input;
}

$pdo = db();
$pdo->beginTransaction();
try {
  if ($level === null) {
    $lv = $pdo->prepare('SELECT COALESCE(MAX(level), 0) + 1 AS next_level FROM courses WHERE department_id = ?');
    $lv->execute([$departmentId]);
    $level = (int)$lv->fetch()['next_level'];
  }

  $ins = $pdo->prepare('INSERT INTO courses (department_id, title, description, icon, color, level) VALUES (?, ?, ?, ?, ?, ?)');
  $ins->execute([$departmentId, $title, $description, $icon, $color, $level]);
  $courseId = (int)$pdo->lastInsertId();

  $li = $pdo->prepare('INSERT INTO lessons (course_id, order_index, title, drive_video_id, drive_doc_id, dur) VALUES (?, ?, ?, ?, ?, ?)');
  foreach ($lessons as $i => $l) {
    $li->execute([$courseId, $i, $l['title'], $l['drive_video_id'], $l['drive_doc_id'], $l['dur']]);
  }

  $qi = $pdo->prepare('INSERT INTO quiz_questions (course_id, order_index, question, options, correct_index) VALUES (?, ?, ?, ?, ?)');
  foreach ($quiz as $i => $q) {
    $qi->execute([$courseId, $i, $q['question'], json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['correct_index']]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  json_error('บันทึกคอร์สไม่สำเร็จ' . (DEBUG_MODE ? (': ' . $e->getMessage()) : ''), 500);
}

json_response(['course_id' => $courseId, 'level' => $level]);
