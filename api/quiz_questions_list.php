<?php
/**
 * รายการคำถามแบบทดสอบท้ายคอร์สแบบเต็ม (รวมเฉลย) — สำหรับหน้าแอดมิน "จัดการแบบทดสอบ" เท่านั้น
 * ต่างจาก quiz_get.php ที่ใช้ตอนพนักงานทำข้อสอบ (ไม่ส่งเฉลยออกไปก่อนส่งคำตอบ)
 */
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');
require_admin();

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if (!$courseId) json_error('ไม่พบคอร์ส', 404);

$cstmt = db()->prepare('SELECT id, title FROM courses WHERE id = ?');
$cstmt->execute([$courseId]);
$course = $cstmt->fetch();
if (!$course) json_error('ไม่พบคอร์ส', 404);

$qstmt = db()->prepare('SELECT id, order_index, question, options, correct_index FROM quiz_questions WHERE course_id = ? ORDER BY order_index');
$qstmt->execute([$courseId]);
$questions = array_map(function ($q) {
  return [
    'id' => (int)$q['id'],
    'question' => $q['question'],
    'options' => json_decode($q['options'], true) ?: [],
    'correct_index' => (int)$q['correct_index'],
  ];
}, $qstmt->fetchAll());

json_response(['course_title' => $course['title'], 'questions' => $questions]);
