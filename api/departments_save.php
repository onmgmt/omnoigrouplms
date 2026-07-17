<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
require_admin();
require_csrf();

$body = read_json_body();
$id = isset($body['id']) ? (int)$body['id'] : null;
$name = trim((string)($body['name'] ?? ''));
$icon = trim((string)($body['icon'] ?? '')) ?: '📋';
$color = trim((string)($body['color'] ?? '')) ?: '#003149';

if ($name === '') json_error('กรุณากรอกชื่อแผนก', 400);
if (mb_strlen($name) > 150) json_error('ชื่อแผนกยาวเกินไป', 400);

if ($id) {
  $stmt = db()->prepare('UPDATE departments SET name = ?, icon = ?, color = ? WHERE id = ?');
  $stmt->execute([$name, $icon, $color, $id]);
} else {
  $stmt = db()->prepare('INSERT INTO departments (name, icon, color) VALUES (?, ?, ?)');
  $stmt->execute([$name, $icon, $color]);
  $id = (int)db()->lastInsertId();
}

json_response(['department' => ['id' => $id, 'name' => $name, 'icon' => $icon, 'color' => $color]]);
