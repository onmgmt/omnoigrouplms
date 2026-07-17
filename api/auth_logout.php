<?php
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');

$u = current_user();
if ($u) {
  log_event((int)$u['id'], 'logout', null, null, null, $u['username']);
}
$_SESSION = [];
session_unset();
session_destroy();

json_response(['ok' => true]);
