<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');
require_admin();

$userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
$eventType = isset($_GET['event_type']) && $_GET['event_type'] !== '' ? (string)$_GET['event_type'] : null;
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? (string)$_GET['date_from'] : null;
$dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? (string)$_GET['date_to'] : null;
$limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));

$sql = 'SELECT al.id, al.user_id, al.username_snapshot, al.event_type, al.course_id, al.lesson_id, al.meta, al.ip_address, al.created_at,
               u.full_name, c.title AS course_title, l.title AS lesson_title
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        LEFT JOIN courses c ON c.id = al.course_id
        LEFT JOIN lessons l ON l.id = al.lesson_id
        WHERE 1=1';
$params = [];
if ($userId) { $sql .= ' AND al.user_id = ?'; $params[] = $userId; }
if ($eventType) { $sql .= ' AND al.event_type = ?'; $params[] = $eventType; }
if ($dateFrom) { $sql .= ' AND al.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $sql .= ' AND al.created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
$sql .= ' ORDER BY al.created_at DESC LIMIT ' . $limit;

$stmt = db()->prepare($sql);
$stmt->execute($params);

$rows = array_map(function ($r) {
  return [
    'id' => (int)$r['id'],
    'user_id' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
    'user_name' => $r['full_name'] ?? $r['username_snapshot'],
    'event_type' => $r['event_type'],
    'course_title' => $r['course_title'],
    'lesson_title' => $r['lesson_title'],
    'meta' => $r['meta'] ? json_decode($r['meta'], true) : null,
    'ip_address' => $r['ip_address'],
    'created_at' => $r['created_at'],
  ];
}, $stmt->fetchAll());

json_response(['logs' => $rows]);
