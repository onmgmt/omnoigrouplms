<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('GET');

$u = require_login();

if ($u['role'] === 'admin') {
  $rows = db()->query(
    'SELECT d.id, d.name, d.icon, d.color,
            (SELECT COUNT(*) FROM courses c WHERE c.department_id = d.id) AS course_count,
            (SELECT COUNT(*) FROM user_departments ud WHERE ud.department_id = d.id) AS user_count
     FROM departments d ORDER BY d.name'
  )->fetchAll();
} else {
  $stmt = db()->prepare(
    'SELECT d.id, d.name, d.icon, d.color FROM departments d
     JOIN user_departments ud ON ud.department_id = d.id
     WHERE ud.user_id = ? ORDER BY d.name'
  );
  $stmt->execute([$u['id']]);
  $rows = $stmt->fetchAll();
}

json_response(['departments' => $rows]);
