<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
require_admin();
require_csrf();

$body = read_json_body();
$courseId = isset($body['id']) ? (int)$body['id'] : 0; // 0 = สร้างคอร์สใหม่, > 0 = แก้ไขคอร์สเดิม
$departmentId = (int)($body['department_id'] ?? 0);
$title = trim((string)($body['title'] ?? ''));
$description = trim((string)($body['description'] ?? ''));
$icon = trim((string)($body['icon'] ?? '')) ?: '🎓';
$color = trim((string)($body['color'] ?? '')) ?: '#003149';
$level = isset($body['level']) && (int)$body['level'] > 0 ? (int)$body['level'] : null;
$published = isset($body['published']) ? (int)(bool)$body['published'] : 1;
$lessonsIn = is_array($body['lessons'] ?? null) ? $body['lessons'] : [];

if (!$departmentId) json_error('กรุณาเลือกแผนก', 400);
if ($title === '') json_error('กรุณากรอกชื่อหลักสูตร', 400);

$dchk = db()->prepare('SELECT 1 FROM departments WHERE id = ?');
$dchk->execute([$departmentId]);
if (!$dchk->fetch()) json_error('ไม่พบแผนกที่เลือก', 400);

$existingCourse = null;
if ($courseId) {
  $cstmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
  $cstmt->execute([$courseId]);
  $existingCourse = $cstmt->fetch();
  if (!$existingCourse) json_error('ไม่พบคอร์สที่ต้องการแก้ไข', 404);
}

function extract_drive_id(string $input): string {
  $input = trim($input);
  if ($input === '') return '';
  if (preg_match('#/d/([a-zA-Z0-9_-]{10,})#', $input, $m)) return $m[1];
  if (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,})#', $input, $m)) return $m[1];
  return $input;
}

$lessons = [];
foreach ($lessonsIn as $l) {
  $lt = trim((string)($l['title'] ?? ''));
  if ($lt === '') continue;
  $lessons[] = [
    // id ของบทเรียนเดิม (ถ้าเป็นการแก้ไขและบทนี้มีอยู่แล้ว) — ใช้กันไม่ให้ progress ของพนักงานที่ดูจบไปแล้วหายเวลาแก้ไขคอร์ส
    'id' => isset($l['id']) && (int)$l['id'] > 0 ? (int)$l['id'] : null,
    'title' => $lt,
    'drive_video_id' => extract_drive_id((string)($l['driveId'] ?? '')),
    'drive_doc_id' => extract_drive_id((string)($l['docId'] ?? '')),
  ];
}
if (!$lessons) json_error('กรุณาเพิ่มบทเรียนอย่างน้อย 1 บท', 400);

$pdo = db();
$pdo->beginTransaction();
try {
  if ($existingCourse) {
    // ---------- แก้ไขคอร์สเดิม ----------
    if ($level === null) $level = (int)$existingCourse['level']; // ไม่ได้ระบุ level ใหม่ -> คงค่าเดิมไว้

    $upd = $pdo->prepare(
      'UPDATE courses SET department_id = ?, title = ?, description = ?, icon = ?, color = ?, level = ?, published = ? WHERE id = ?'
    );
    $upd->execute([$departmentId, $title, $description, $icon, $color, $level, $published, $courseId]);

    // ปรับรายการบทเรียนแบบ reconcile ตาม id ที่ส่งมา แทนการลบ-สร้างใหม่ทั้งหมด
    // เพื่อไม่ให้ lesson_progress (ประวัติ "ดูจบแล้ว") ของพนักงานที่มีอยู่แล้วหายไปสำหรับบทที่ยังอยู่เหมือนเดิม
    $existIdsStmt = $pdo->prepare('SELECT id FROM lessons WHERE course_id = ?');
    $existIdsStmt->execute([$courseId]);
    $existingLessonIds = array_map('intval', array_column($existIdsStmt->fetchAll(), 'id'));
    $submittedIds = array_values(array_filter(array_map(fn($l) => $l['id'], $lessons)));

    $toDelete = array_values(array_diff($existingLessonIds, $submittedIds));
    if ($toDelete) {
      $ph = implode(',', array_fill(0, count($toDelete), '?'));
      // ON DELETE CASCADE ในตาราง lesson_progress จะลบ progress ของบทที่ถูกลบไปด้วยโดยอัตโนมัติ (ตั้งใจ — บทนี้ไม่มีอยู่แล้ว)
      $pdo->prepare("DELETE FROM lessons WHERE course_id = ? AND id IN ($ph)")->execute(array_merge([$courseId], $toDelete));
    }

    $updL = $pdo->prepare('UPDATE lessons SET order_index = ?, title = ?, drive_video_id = ?, drive_doc_id = ? WHERE id = ? AND course_id = ?');
    $insL = $pdo->prepare('INSERT INTO lessons (course_id, order_index, title, drive_video_id, drive_doc_id, dur) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($lessons as $i => $l) {
      if ($l['id'] && in_array($l['id'], $existingLessonIds, true)) {
        $updL->execute([$i, $l['title'], $l['drive_video_id'], $l['drive_doc_id'], $l['id'], $courseId]);
      } else {
        $insL->execute([$courseId, $i, $l['title'], $l['drive_video_id'], $l['drive_doc_id'], 20 + $i * 3]);
      }
    }
  } else {
    // ---------- สร้างคอร์สใหม่ ----------
    if ($level === null) {
      $lv = $pdo->prepare('SELECT COALESCE(MAX(level), 0) + 1 AS next_level FROM courses WHERE department_id = ?');
      $lv->execute([$departmentId]);
      $level = (int)$lv->fetch()['next_level'];
    }

    $ins = $pdo->prepare('INSERT INTO courses (department_id, title, description, icon, color, level, published) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$departmentId, $title, $description, $icon, $color, $level, $published]);
    $courseId = (int)$pdo->lastInsertId();

    $li = $pdo->prepare('INSERT INTO lessons (course_id, order_index, title, drive_video_id, drive_doc_id, dur) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($lessons as $i => $l) {
      $li->execute([$courseId, $i, $l['title'], $l['drive_video_id'], $l['drive_doc_id'], 20 + $i * 3]);
    }

    // คอร์สใหม่ยังไม่มีข้อสอบ -> สร้างตัวอย่าง 2 ข้อให้ไปแก้ไขต่อที่เมนู "จัดการแบบทดสอบ" ของคอร์สนี้
    $quiz = [
      ['question' => 'ตัวอย่างคำถามที่ 1 ของหลักสูตรนี้ (แก้ไขได้ที่เมนู "จัดการแบบทดสอบ")', 'options' => ['ตัวเลือก ก', 'ตัวเลือก ข (ถูก)', 'ตัวเลือก ค', 'ตัวเลือก ง'], 'correct_index' => 1],
      ['question' => 'ตัวอย่างคำถามที่ 2 ของหลักสูตรนี้', 'options' => ['ตัวเลือก ก (ถูก)', 'ตัวเลือก ข', 'ตัวเลือก ค', 'ตัวเลือก ง'], 'correct_index' => 0],
    ];
    $qi = $pdo->prepare('INSERT INTO quiz_questions (course_id, order_index, question, options, correct_index) VALUES (?, ?, ?, ?, ?)');
    foreach ($quiz as $i => $q) {
      $qi->execute([$courseId, $i, $q['question'], json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['correct_index']]);
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  json_error('บันทึกคอร์สไม่สำเร็จ' . (DEBUG_MODE ? (': ' . $e->getMessage()) : ''), 500);
}

json_response(['course_id' => $courseId, 'level' => $level, 'published' => (bool)$published]);
