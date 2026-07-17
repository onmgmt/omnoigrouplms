<?php
/**
 * ============================================================
 * Omnoi Academy LMS — Backend bootstrap
 * ทุกไฟล์ใน api/*.php ต้อง require_once '_bootstrap.php' เป็นบรรทัดแรกเสมอ
 * รวม: ต่อฐานข้อมูล (PDO), จัดการ session/CSRF, helper ตอบ JSON, helper เช็คสิทธิ์, helper เขียน log
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

error_reporting(DEBUG_MODE ? E_ALL : 0);
ini_set('display_errors', DEBUG_MODE ? '1' : '0');

// ---------- Session ----------
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('omnoi_lms_sess');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure' => $__https,
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---------- Database (PDO + prepared statements เท่านั้น) ----------
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    try {
      $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES => false,
        ]
      );
    } catch (PDOException $e) {
      json_error('เชื่อมต่อฐานข้อมูลไม่สำเร็จ' . (DEBUG_MODE ? (': ' . $e->getMessage()) : ''), 500);
    }
  }
  return $pdo;
}

// ---------- JSON response helpers ----------
function json_response($data, int $status = 200): never {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function json_error(string $message, int $status = 400, array $extra = []): never {
  json_response(array_merge(['error' => $message], $extra), $status);
}
function require_method(string $method): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
    json_error('Method not allowed', 405);
  }
}
function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
function client_ip(): string {
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

// ---------- Auth ----------
function current_user(): ?array {
  static $user = false; // false = ยังไม่เช็ค, null = ไม่ล็อกอิน
  if ($user !== false) return $user;
  $user = null;
  if (empty($_SESSION['user_id'])) return null;
  $stmt = db()->prepare('SELECT id, username, full_name, role, active FROM users WHERE id = ?');
  $stmt->execute([$_SESSION['user_id']]);
  $row = $stmt->fetch();
  if (!$row || !$row['active']) {
    // ผู้ใช้ถูกลบ/ปิดใช้งานไปแล้ว แต่ session เก่ายังอยู่ -> เคลียร์ทิ้ง
    session_unset();
    session_destroy();
    return null;
  }
  $user = $row;
  return $user;
}
function require_login(): array {
  $u = current_user();
  if (!$u) json_error('กรุณาเข้าสู่ระบบ', 401);
  return $u;
}
function require_admin(): array {
  $u = require_login();
  if ($u['role'] !== 'admin') json_error('ต้องเป็นผู้ดูแลระบบเท่านั้น', 403);
  return $u;
}

// ---------- CSRF (สำหรับ endpoint ที่แก้ข้อมูล / POST) ----------
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}
function require_csrf(): void {
  $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $expected = $_SESSION['csrf'] ?? '';
  if (!$expected || !$sent || !hash_equals($expected, $sent)) {
    json_error('CSRF token ไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง', 403);
  }
}

// ---------- Level-lock: คอร์ส level > 1 ต้องผ่าน (course_completions) คอร์ส level-1 ในแผนกเดียวกันก่อน ----------
// ใช้ตรงกันทั้งฝั่งอ่าน (course_detail) และฝั่งเขียน (mark watched / submit quiz) กันลัดขั้นตอนผ่าน API ตรง ๆ
function course_is_locked_for_user(int $userId, int $departmentId, int $level): bool {
  if ($level <= 1) return false;
  $stmt = db()->prepare(
    'SELECT 1 FROM course_completions cc JOIN courses c2 ON c2.id = cc.course_id
     WHERE cc.user_id = ? AND c2.department_id = ? AND c2.level = ?'
  );
  $stmt->execute([$userId, $departmentId, $level - 1]);
  return !$stmt->fetch();
}

// ผู้ใช้ (พนักงาน) สังกัดแผนกนี้จริงหรือไม่
function user_in_department(int $userId, int $departmentId): bool {
  $stmt = db()->prepare('SELECT 1 FROM user_departments WHERE user_id = ? AND department_id = ?');
  $stmt->execute([$userId, $departmentId]);
  return (bool)$stmt->fetch();
}

// ---------- Activity log ----------
function log_event(?int $userId, string $eventType, ?int $courseId = null, ?int $lessonId = null, ?array $meta = null, ?string $usernameSnapshot = null): void {
  try {
    $stmt = db()->prepare(
      'INSERT INTO activity_logs (user_id, username_snapshot, event_type, course_id, lesson_id, meta, ip_address)
       VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
      $userId,
      $usernameSnapshot,
      $eventType,
      $courseId,
      $lessonId,
      $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
      client_ip(),
    ]);
  } catch (Throwable $e) {
    // การ log ล้มเหลวไม่ควรทำให้ request หลักพัง — ปล่อยผ่านเงียบ ๆ (ถ้า DEBUG_MODE เปิดค่อยโผล่ error ปกติ)
    if (DEBUG_MODE) throw $e;
  }
}
