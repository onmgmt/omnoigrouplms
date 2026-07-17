<?php
/**
 * สลับสถานะ "เผยแพร่ / ซ่อน" ของคอร์ส แบบเร็ว ๆ จากรายการหลักสูตรในหน้า "จัดการคอร์ส"
 * แยกจาก courses_save.php โดยตั้งใจ — endpoint นี้แก้เฉพาะคอลัมน์ published เท่านั้น
 * ไม่ยุ่งกับบทเรียน จึงกดสลับได้อย่างปลอดภัยโดยไม่เสี่ยงข้อมูลบทเรียน/ประวัติการเรียนของพนักงานหาย
 */
require_once __DIR__ . '/_bootstrap.php';
require_method('POST');
require_admin();
require_csrf();

$body = read_json_body();
$courseId = (int)($body['id'] ?? 0);
if (!$courseId) json_error('ไม่พบคอร์ส', 400);
$published = isset($body['published']) ? (int)(bool)$body['published'] : 1;

$chk = db()->prepare('SELECT id FROM courses WHERE id = ?');
$chk->execute([$courseId]);
if (!$chk->fetch()) json_error('ไม่พบคอร์ส', 404);

db()->prepare('UPDATE courses SET published = ? WHERE id = ?')->execute([$published, $courseId]);

json_response(['ok' => true, 'id' => $courseId, 'published' => (bool)$published]);
