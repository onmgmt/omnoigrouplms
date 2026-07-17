<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');

$u = require_login();
$deptParam = isset($_GET['dept']) ? (int)$_GET['dept'] : null;

if ($u['role'] === 'admin') {
  $sql = 'SELECT c.id, c.department_id, c.title, c.description, c.icon, c.color, c.level, c.published,
                 d.name AS department_name,
                 (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                 (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.drive_video_id <> \'\') AS video_count,
                 (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.drive_doc_id <> \'\') AS doc_count,
                 (SELECT COUNT(*) FROM quiz_questions q WHERE q.course_id = c.id) AS quiz_count
          FROM courses c JOIN departments d ON d.id = c.department_id';
  $params = [];
  if ($deptParam) { $sql .= ' WHERE c.department_id = ?'; $params[] = $deptParam; }
  $sql .= ' ORDER BY d.name, c.level';
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  // published มาจาก MySQL เป็น string "0"/"1" ซึ่งเป็น truthy ทั้งคู่ใน JS -> แปลงเป็น bool จริงก่อนส่งออก
  foreach ($rows as &$r) { $r['published'] = (bool)$r['published']; }
  unset($r);
  json_response(['courses' => $rows]);
}

// ---------- พนักงาน: ต้องอยู่ในแผนกนั้น + คำนวณสถานะปลดล็อกตาม level ----------
$myDeptStmt = db()->prepare('SELECT department_id FROM user_departments WHERE user_id = ?');
$myDeptStmt->execute([$u['id']]);
$myDeptIds = array_map('intval', array_column($myDeptStmt->fetchAll(), 'department_id'));

if ($deptParam !== null) {
  if (!in_array($deptParam, $myDeptIds, true)) json_error('คุณไม่ได้สังกัดแผนกนี้', 403);
  $targetDeptIds = [$deptParam];
} else {
  $targetDeptIds = $myDeptIds;
}
if (!$targetDeptIds) json_response(['courses' => []]);

$in = implode(',', array_fill(0, count($targetDeptIds), '?'));
$stmt = db()->prepare(
  "SELECT c.id, c.department_id, c.title, c.description, c.icon, c.color, c.level, d.name AS department_name
   FROM courses c JOIN departments d ON d.id = c.department_id
   WHERE c.department_id IN ($in) AND c.published = 1 ORDER BY c.department_id, c.level"
);
$stmt->execute($targetDeptIds);
$courses = $stmt->fetchAll();
if (!$courses) json_response(['courses' => []]);

$courseIds = array_column($courses, 'id');
$cin = implode(',', array_fill(0, count($courseIds), '?'));

// จำนวนบทเรียนทั้งหมด + ที่ดูจบแล้วต่อคอร์ส
$lessonTotals = [];
$stmt = db()->prepare("SELECT course_id, COUNT(*) AS n FROM lessons WHERE course_id IN ($cin) GROUP BY course_id");
$stmt->execute($courseIds);
foreach ($stmt->fetchAll() as $r) $lessonTotals[(int)$r['course_id']] = (int)$r['n'];

$watchedCounts = [];
$stmt = db()->prepare(
  "SELECT l.course_id, COUNT(*) AS n FROM lesson_progress lp
   JOIN lessons l ON l.id = lp.lesson_id
   WHERE lp.user_id = ? AND l.course_id IN ($cin) GROUP BY l.course_id"
);
$stmt->execute(array_merge([$u['id']], $courseIds));
foreach ($stmt->fetchAll() as $r) $watchedCounts[(int)$r['course_id']] = (int)$r['n'];

// คะแนนสอบล่าสุด/สูงสุด + จำนวนครั้งที่สอบ ต่อคอร์ส
$scoreBest = []; $attemptsCount = []; $lastDate = [];
$stmt = db()->prepare("SELECT course_id, score, submitted_at FROM quiz_attempts WHERE user_id = ? AND course_id IN ($cin)");
$stmt->execute(array_merge([$u['id']], $courseIds));
foreach ($stmt->fetchAll() as $r) {
  $cid = (int)$r['course_id'];
  $attemptsCount[$cid] = ($attemptsCount[$cid] ?? 0) + 1;
  $scoreBest[$cid] = max($scoreBest[$cid] ?? -1, (int)$r['score']);
  if (!isset($lastDate[$cid]) || $r['submitted_at'] > $lastDate[$cid]) $lastDate[$cid] = $r['submitted_at'];
}

// คอร์สที่ผ่านแล้ว (สำหรับคำนวณ lock ตาม level)
$completed = []; // department_id => set of levels passed
$stmt = db()->prepare(
  "SELECT c.department_id, c.level FROM course_completions cc
   JOIN courses c ON c.id = cc.course_id WHERE cc.user_id = ?"
);
$stmt->execute([$u['id']]);
foreach ($stmt->fetchAll() as $r) $completed[(int)$r['department_id']][] = (int)$r['level'];

$out = [];
foreach ($courses as $c) {
  $cid = (int)$c['id']; $dept = (int)$c['department_id']; $level = (int)$c['level'];
  $total = $lessonTotals[$cid] ?? 0;
  $watched = $watchedCounts[$cid] ?? 0;
  $locked = $level > 1 && !in_array($level - 1, $completed[$dept] ?? [], true);
  $out[] = [
    'id' => $cid,
    'department_id' => $dept,
    'department_name' => $c['department_name'],
    'title' => $c['title'],
    'description' => $c['description'],
    'icon' => $c['icon'],
    'color' => $c['color'],
    'level' => $level,
    'locked' => $locked,
    'lesson_total' => $total,
    'lesson_watched' => $watched,
    'progress_pct' => $total ? (int)round($watched / $total * 100) : 0,
    'best_score' => $scoreBest[$cid] ?? null,
    'attempts' => $attemptsCount[$cid] ?? 0,
    'last_date' => isset($lastDate[$cid]) ? substr($lastDate[$cid], 0, 10) : null,
    'completed' => in_array($level, $completed[$dept] ?? [], true),
  ];
}

json_response(['courses' => $out]);
