<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');

$u = current_user();
if (!$u) {
  json_response(['user' => null]);
}

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
