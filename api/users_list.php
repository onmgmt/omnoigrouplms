<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');
require_admin();

$rows = db()->query(
  'SELECT id, username, full_name, role, active, created_at FROM users ORDER BY role DESC, full_name'
)->fetchAll();

$dstmt = db()->query(
  'SELECT ud.user_id, d.id, d.name, d.icon FROM user_departments ud JOIN departments d ON d.id = ud.department_id'
);
$deptsByUser = [];
foreach ($dstmt->fetchAll() as $r) {
  $deptsByUser[(int)$r['user_id']][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'icon' => $r['icon']];
}

$out = array_map(function ($u) use ($deptsByUser) {
  return [
    'id' => (int)$u['id'],
    'username' => $u['username'],
    'full_name' => $u['full_name'],
    'role' => $u['role'],
    'active' => (bool)$u['active'],
    'created_at' => $u['created_at'],
    'departments' => $deptsByUser[(int)$u['id']] ?? [],
  ];
}, $rows);

json_response(['users' => $out]);
