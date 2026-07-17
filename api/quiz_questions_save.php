<?php
/**
 * บันทึกชุดคำถามแบบทดสอบท้ายคอร์สทั้งหมด (แทนที่ชุดเดิมทั้งชุด) — สำหรับหน้าแอดมิน "จัดการแบบทดสอบ"
 * ไม่กระทบ quiz_attempts/course_completions เดิม (อ้างอิงด้วย course_id ไม่ใช่ question_id) จึงบันทึกทับได้อย่างปลอดภัย
 */
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
require_admin();
require_csrf();

$body = read_json_body();
$courseId = (int)($body['course_id'] ?? 0);
$questionsIn = is_array($body['questions'] ?? null) ? $body['questions'] : [];
if (!$courseId) json_error('ไม่พบคอร์ส', 400);

$cstmt = db()->prepare('SELECT id FROM courses WHERE id = ?');
$cstmt->execute([$courseId]);
if (!$cstmt->fetch()) json_error('ไม่พบคอร์ส', 404);

$questions = [];
foreach ($questionsIn as $q) {
  $qt = trim((string)($q['question'] ?? ''));
  if ($qt === '') continue; // แถวคำถามว่างเปล่า -> ข้ามไปเงียบ ๆ (ไม่ถือเป็น error)

  // ไม่กรองตัวเลือกที่ว่างทิ้งแบบเงียบ ๆ เพราะจะทำให้ correct_index ที่ผู้ใช้เลือกไว้ (อิงตำแหน่งเดิม) เพี้ยนไปคนละข้อ
  // -> ถ้ามีช่องตัวเลือกว่างหลงเหลืออยู่ ให้ตีเป็น error ทันทีแทน
  $optsRaw = is_array($q['options'] ?? null) ? $q['options'] : [];
  $opts = array_map(fn($o) => trim((string)$o), $optsRaw);
  $correct = (int)($q['correct_index'] ?? -1);

  if (count($opts) < 2 || in_array('', $opts, true) || $correct < 0 || $correct >= count($opts)) {
    json_error('ข้อมูลแบบทดสอบไม่ถูกต้อง: แต่ละคำถามต้องมีตัวเลือกอย่างน้อย 2 ข้อ กรอกครบทุกช่อง และเลือกคำตอบที่ถูกต้อง', 400);
  }
  $questions[] = ['question' => $qt, 'options' => $opts, 'correct_index' => $correct];
}
if (!$questions) json_error('กรุณาเพิ่มคำถามอย่างน้อย 1 ข้อ', 400);

$pdo = db();
$pdo->beginTransaction();
try {
  $pdo->prepare('DELETE FROM quiz_questions WHERE course_id = ?')->execute([$courseId]);
  $qi = $pdo->prepare('INSERT INTO quiz_questions (course_id, order_index, question, options, correct_index) VALUES (?, ?, ?, ?, ?)');
  foreach ($questions as $i => $q) {
    $qi->execute([$courseId, $i, $q['question'], json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['correct_index']]);
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  json_error('บันทึกแบบทดสอบไม่สำเร็จ' . (DEBUG_MODE ? (': ' . $e->getMessage()) : ''), 500);
}

json_response(['ok' => true, 'count' => count($questions)]);
