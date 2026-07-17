<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');

$body = read_json_body();
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($username === '' || $password === '') {
  json_error('กรุณากรอกชื่อผู้ใช้และรหัสผ่าน', 400);
}

$stmt = db()->prepare('SELECT id, username, password_hash, full_name, role, active, failed_attempts, locked_until FROM users WHERE username = ?');
$stmt->execute([$username]);
$u = $stmt->fetch();

if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
  log_event(null, 'login_failed', null, null, ['reason' => 'locked'], $username);
  json_error('บัญชีนี้ถูกล็อกชั่วคราวจากการกรอกรหัสผ่านผิดหลายครั้ง กรุณาลองใหม่ภายหลัง', 423);
}

if (!$u || !$u['active'] || !password_verify($password, $u['password_hash'])) {
  if ($u) {
    $attempts = (int)$u['failed_attempts'] + 1;
    $lockUntil = null;
    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
      $lockUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
    }
    $upd = db()->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
    $upd->execute([$attempts, $lockUntil, $u['id']]);
  }
  log_event($u['id'] ?? null, 'login_failed', null, null, null, $username);
  json_error('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
}

// สำเร็จ: เคลียร์ตัวนับผิด, สร้าง session ใหม่กัน session fixation
db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$u['id']]);
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$u['id'];

log_event((int)$u['id'], 'login', null, null, null, $u['username']);

$depts = [];
if ($u['role'] !== 'admin') {
  $dstmt = db()->prepare(
    'SELECT d.id, d.name, d.icon, d.color FROM departments d
     JOIN user_departments ud ON ud.department_id = d.id
     WHERE ud.user_id = ? ORDER BY d.name'
  );
  $dstmt->execute([$u['id']]);
  $depts = $dstmt->fetchAll();
}

json_response([
  'user' => [
    'id' => (int)$u['id'],
    'username' => $u['username'],
    'full_name' => $u['full_name'],
    'role' => $u['role'],
    'departments' => $depts,
  ],
  'csrf' => csrf_token(),
]);
