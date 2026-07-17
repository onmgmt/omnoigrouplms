<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
$admin = require_admin();
require_csrf();

$body = read_json_body();
$id = isset($body['id']) ? (int)$body['id'] : null;
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');
$fullName = trim((string)($body['full_name'] ?? ''));
$role = ($body['role'] ?? 'emp') === 'admin' ? 'admin' : 'emp';
$active = isset($body['active']) ? (bool)$body['active'] : true;
$deptIds = is_array($body['department_ids'] ?? null) ? array_values(array_unique(array_map('intval', $body['department_ids']))) : [];

if ($username === '') json_error('กรุณากรอกชื่อผู้ใช้', 400);
if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) json_error('ชื่อผู้ใช้ใช้ได้เฉพาะ a-z 0-9 . _ - ความยาว 3-50 ตัวอักษร', 400);
if ($fullName === '') json_error('กรุณากรอกชื่อ-นามสกุล', 400);
if (!$id && strlen($password) < 4) json_error('รหัสผ่านสั้นเกินไป (อย่างน้อย 4 ตัวอักษร)', 400);
if ($id && $password !== '' && strlen($password) < 4) json_error('รหัสผ่านสั้นเกินไป (อย่างน้อย 4 ตัวอักษร)', 400);

$pdo = db();

// username ซ้ำ?
$dupStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
$dupStmt->execute([$username, $id ?? 0]);
if ($dupStmt->fetch()) json_error('มีชื่อผู้ใช้นี้อยู่แล้ว', 409);

$pdo->beginTransaction();
try {
  if ($id) {
    $sql = 'UPDATE users SET username = ?, full_name = ?, role = ?, active = ? WHERE id = ?';
    $params = [$username, $fullName, $role, $active ? 1 : 0, $id];
    if ($password !== '') {
      $sql = 'UPDATE users SET username = ?, full_name = ?, role = ?, active = ?, password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?';
      $params = [$username, $fullName, $role, $active ? 1 : 0, password_hash($password, PASSWORD_DEFAULT), $id];
    }
    $pdo->prepare($sql)->execute($params);
  } else {
    $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $active ? 1 : 0]);
    $id = (int)$pdo->lastInsertId();
  }

  $pdo->prepare('DELETE FROM user_departments WHERE user_id = ?')->execute([$id]);
  if ($role === 'emp' && $deptIds) {
    $di = $pdo->prepare('INSERT IGNORE INTO user_departments (user_id, department_id) VALUES (?, ?)');
    foreach ($deptIds as $did) $di->execute([$id, $did]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  json_error('บันทึกผู้ใช้ไม่สำเร็จ' . (DEBUG_MODE ? (': ' . $e->getMessage()) : ''), 500);
}

log_event($admin['id'], 'user_save', null, null, ['target_user_id' => $id, 'username' => $username], $admin['username']);

json_response([
  'user' => ['id' => $id, 'username' => $username, 'full_name' => $fullName, 'role' => $role, 'active' => $active],
  // ส่งรหัสผ่าน plaintext กลับ "ครั้งเดียว" ตอนสร้าง/รีเซ็ต เพื่อให้แอดมิน copy ไปแจ้งพนักงาน (ระบบไม่มีอีเมลส่งอัตโนมัติ)
  'plain_password' => $password !== '' ? $password : null,
]);
