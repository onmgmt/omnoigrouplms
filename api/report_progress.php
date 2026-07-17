<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');
require_admin();

$deptParam = isset($_GET['dept']) ? (int)$_GET['dept'] : null;

$sql = 'SELECT DISTINCT u.id AS user_id, u.full_name, u.username
        FROM users u JOIN user_departments ud ON ud.user_id = u.id
        WHERE u.role = "emp"';
$params = [];
if ($deptParam) { $sql .= ' AND ud.department_id = ?'; $params[] = $deptParam; }
$sql .= ' ORDER BY u.full_name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

if (!$employees) json_response(['rows' => []]);

$userIds = array_column($employees, 'user_id');
$uin = implode(',', array_fill(0, count($userIds), '?'));

// (user, course) ที่ต้องแสดง = คอร์สทุกอันในแผนกที่ user สังกัด (กรองด้วย dept ถ้ามี)
$pairSql = "SELECT ud.user_id, c.id AS course_id, c.title, c.level, d.id AS department_id, d.name AS department_name
            FROM user_departments ud
            JOIN courses c ON c.department_id = ud.department_id
            JOIN departments d ON d.id = c.department_id
            WHERE ud.user_id IN ($uin)";
$pairParams = $userIds;
if ($deptParam) { $pairSql .= ' AND ud.department_id = ?'; $pairParams[] = $deptParam; }
$pairSql .= ' ORDER BY ud.user_id, d.name, c.level';
$stmt = db()->prepare($pairSql);
$stmt->execute($pairParams);
$pairs = $stmt->fetchAll();

if (!$pairs) json_response(['rows' => []]);

$courseIds = array_values(array_unique(array_map(fn($p) => (int)$p['course_id'], $pairs)));
$cin = implode(',', array_fill(0, count($courseIds), '?'));

// ความคืบหน้าวิดีโอ ต่อ (user, course)
$watched = []; // "uid:cid" => count
$stmt = db()->prepare(
  "SELECT lp.user_id, l.course_id, COUNT(*) AS n FROM lesson_progress lp
   JOIN lessons l ON l.id = lp.lesson_id
   WHERE lp.user_id IN ($uin) AND l.course_id IN ($cin) GROUP BY lp.user_id, l.course_id"
);
$stmt->execute(array_merge($userIds, $courseIds));
foreach ($stmt->fetchAll() as $r) $watched[$r['user_id'] . ':' . $r['course_id']] = (int)$r['n'];

$lessonTotal = [];
$stmt = db()->prepare("SELECT course_id, COUNT(*) AS n FROM lessons WHERE course_id IN ($cin) GROUP BY course_id");
$stmt->execute($courseIds);
foreach ($stmt->fetchAll() as $r) $lessonTotal[(int)$r['course_id']] = (int)$r['n'];

// คะแนน/จำนวนครั้งสอบ
$scoreBest = []; $attempts = []; $lastDate = [];
$stmt = db()->prepare("SELECT user_id, course_id, score, submitted_at FROM quiz_attempts WHERE user_id IN ($uin) AND course_id IN ($cin)");
$stmt->execute(array_merge($userIds, $courseIds));
foreach ($stmt->fetchAll() as $r) {
  $k = $r['user_id'] . ':' . $r['course_id'];
  $attempts[$k] = ($attempts[$k] ?? 0) + 1;
  $scoreBest[$k] = max($scoreBest[$k] ?? -1, (int)$r['score']);
  if (!isset($lastDate[$k]) || $r['submitted_at'] > $lastDate[$k]) $lastDate[$k] = $r['submitted_at'];
}

$completedSet = [];
$stmt = db()->prepare("SELECT user_id, course_id FROM course_completions WHERE user_id IN ($uin) AND course_id IN ($cin)");
$stmt->execute(array_merge($userIds, $courseIds));
foreach ($stmt->fetchAll() as $r) $completedSet[$r['user_id'] . ':' . $r['course_id']] = true;

$empByUid = [];
foreach ($employees as $e) $empByUid[(int)$e['user_id']] = $e;

$rows = [];
foreach ($pairs as $p) {
  $uid = (int)$p['user_id']; $cid = (int)$p['course_id'];
  $k = $uid . ':' . $cid;
  $total = $lessonTotal[$cid] ?? 0;
  $w = $watched[$k] ?? 0;
  $rows[] = [
    'user_id' => $uid,
    'full_name' => $empByUid[$uid]['full_name'] ?? '',
    'username' => $empByUid[$uid]['username'] ?? '',
    'department_id' => (int)$p['department_id'],
    'department_name' => $p['department_name'],
    'course_id' => $cid,
    'course_title' => $p['title'],
    'level' => (int)$p['level'],
    'progress_pct' => $total ? (int)round($w / $total * 100) : 0,
    'best_score' => $scoreBest[$k] ?? null,
    'attempts' => $attempts[$k] ?? 0,
    'completed' => isset($completedSet[$k]),
    'last_date' => isset($lastDate[$k]) ? substr($lastDate[$k], 0, 10) : null,
  ];
}

json_response(['rows' => $rows]);
