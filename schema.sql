-- ============================================================
-- Omnoi Academy LMS — MySQL schema (สำหรับ HostNeverDie / DirectAdmin)
-- Import ไฟล์นี้ผ่าน phpMyAdmin หลังสร้างฐานข้อมูลว่างแล้ว
-- ตัวอักษร: utf8mb4 (รองรับภาษาไทย + emoji ไอคอนคอร์ส/แผนก)
--
-- หมายเหตุความเข้ากันได้: คอลัมน์ที่เก็บ JSON (options, meta) ใช้ชนิด TEXT
-- แทน JSON type ล้วน ๆ เพื่อให้ import ได้แม้บน MySQL รุ่นเก่าของโฮสต์บางเจ้า
-- ฝั่ง PHP จะ json_encode/json_decode เองอยู่แล้ว
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- ผู้ใช้ ----------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  role ENUM('emp','admin') NOT NULL DEFAULT 'emp',
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- แผนก (แทน TRACKS เดิมที่ hardcode ในโค้ด) ----------
CREATE TABLE IF NOT EXISTS departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  icon VARCHAR(10) NOT NULL DEFAULT '📋',
  color VARCHAR(20) NOT NULL DEFAULT '#003149',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- พนักงาน 1 คน สังกัดได้หลายแผนก ----------
CREATE TABLE IF NOT EXISTS user_departments (
  user_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, department_id),
  CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ud_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- คอร์ส (อยู่ภายในแผนก, มี level สำหรับล็อกตามลำดับ) ----------
CREATE TABLE IF NOT EXISTS courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  icon VARCHAR(10) NOT NULL DEFAULT '🎓',
  color VARCHAR(20) NOT NULL DEFAULT '#003149',
  level INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_course_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  INDEX idx_courses_dept_level (department_id, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- บทเรียนในคอร์ส (วิดีโอ + เอกสาร จาก Google Drive) ----------
CREATE TABLE IF NOT EXISTS lessons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  order_index INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(200) NOT NULL,
  drive_video_id VARCHAR(120) NOT NULL DEFAULT '',
  drive_doc_id VARCHAR(120) NOT NULL DEFAULT '',
  dur INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_lesson_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  INDEX idx_lessons_course (course_id, order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- ข้อสอบท้ายคอร์ส ----------
CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  order_index INT UNSIGNED NOT NULL DEFAULT 0,
  question TEXT NOT NULL,
  options TEXT NOT NULL COMMENT 'JSON array of strings',
  correct_index TINYINT UNSIGNED NOT NULL,
  CONSTRAINT fk_quiz_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  INDEX idx_quiz_course (course_id, order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- บันทึกว่าใครดูบทไหนจบแล้ว (แทน p.watched[] เดิม) ----------
CREATE TABLE IF NOT EXISTS lesson_progress (
  user_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  watched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, lesson_id),
  CONSTRAINT fk_lp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_lp_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- ประวัติการทำแบบทดสอบ ----------
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  score INT UNSIGNED NOT NULL,
  correct_count INT UNSIGNED NOT NULL,
  total_count INT UNSIGNED NOT NULL,
  attempt_no INT UNSIGNED NOT NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_qa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_qa_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  INDEX idx_qa_user_course (user_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- สรุปว่าใครเรียนจบคอร์สไหนไปแล้วบ้าง (คะแนนผ่านเกณฑ์) ----------
CREATE TABLE IF NOT EXISTS course_completions (
  user_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  best_score INT UNSIGNED NOT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, course_id),
  CONSTRAINT fk_cc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Activity log: login, เปิดวิดีโอ, โหลดเอกสาร, ส่งข้อสอบ, เรียนจบคอร์ส ----------
CREATE TABLE IF NOT EXISTS activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username_snapshot VARCHAR(50) NULL COMMENT 'กันไว้เผื่อ user ถูกลบภายหลัง หรือ login ไม่สำเร็จ',
  event_type VARCHAR(30) NOT NULL COMMENT 'login|login_failed|logout|video_open|doc_download|quiz_submit|course_complete',
  course_id INT UNSIGNED NULL,
  lesson_id INT UNSIGNED NULL,
  meta TEXT NULL COMMENT 'JSON เพิ่มเติม เช่น {"score":85}',
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_logs_user_time (user_id, created_at),
  INDEX idx_logs_event (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Seed data — ชุดเดียวกับดีโมเดิม เพื่อทดสอบและต่อยอดได้ทันที
-- ⚠️ เปลี่ยนรหัสผ่านทุกบัญชี (โดยเฉพาะ admin) ทันทีหลังขึ้นระบบจริง
-- รหัสผ่านตัวอย่าง: admin/admin, somchai/1234, nattaya/1234, weera/1234, ploy/1234
-- ============================================================

INSERT INTO departments (id, name, icon, color) VALUES
  (1, 'ฝ่ายขาย', '🤝', '#ed1c24'),
  (2, 'อะไหล่', '⚙️', '#6a9dbd'),
  (3, 'ช่าง', '🔧', '#003149'),
  (4, 'อื่นๆ', '📋', '#7a1315');

-- password_hash() bcrypt ของ 'admin' และ '1234' (PASSWORD_DEFAULT)
INSERT INTO users (id, username, password_hash, full_name, role) VALUES
  (1, 'admin', '$2y$12$.OD75sdWrY6hNd1EgbVCyuyByRwT6GNjU4Gbnf.ZTR9YhMbEUQvwu', 'ผู้ดูแลระบบ', 'admin'),
  (2, 'somchai', '$2y$12$1ITleui2.joiBgRMQ8Tpyum2nherbBNLt3f8gjqaclZ81x7uP27u2', 'สมชาย ใจดี', 'emp'),
  (3, 'nattaya', '$2y$12$1ITleui2.joiBgRMQ8Tpyum2nherbBNLt3f8gjqaclZ81x7uP27u2', 'ณัฐญา ศรีสุข', 'emp'),
  (4, 'weera', '$2y$12$1ITleui2.joiBgRMQ8Tpyum2nherbBNLt3f8gjqaclZ81x7uP27u2', 'วีระ มั่นคง', 'emp'),
  (5, 'ploy', '$2y$12$1ITleui2.joiBgRMQ8Tpyum2nherbBNLt3f8gjqaclZ81x7uP27u2', 'พลอย พงษ์ไพร', 'emp');

INSERT INTO user_departments (user_id, department_id) VALUES
  (2, 1), (3, 2), (4, 3), (5, 4);

-- แผนกฝ่ายขาย: 2 คอร์ส level 1 -> 2 (ต้องผ่าน level 1 ก่อน)
INSERT INTO courses (id, department_id, title, description, icon, color, level) VALUES
  (1, 1, 'เทคนิคการปิดการขายขั้นเทพ', 'ทักษะการนำเสนอ จับสัญญาณซื้อ และปิดการขายอย่างมืออาชีพ', '💰', '#ed1c24', 1),
  (2, 1, 'การบริการลูกค้าและงานหลังการขาย', 'สร้างความประทับใจ จัดการข้อร้องเรียน และรักษาฐานลูกค้า', '⭐', '#7a1315', 2),
  (3, 2, 'ระบบจัดการคลังอะไหล่ (WMS)', 'การรับ-จ่าย จัดเก็บ และตรวจนับสต็อกอะไหล่อย่างเป็นระบบ', '📦', '#6a9dbd', 1),
  (4, 2, 'ความรู้พื้นฐานอะไหล่ยานยนต์', 'รู้จักประเภทอะไหล่ รหัสสินค้า และการเทียบรุ่น', '🔩', '#003149', 2),
  (5, 3, 'มาตรฐานการซ่อมบำรุงและความปลอดภัย', 'ขั้นตอนการซ่อมตามมาตรฐาน การใช้เครื่องมือ และความปลอดภัยในงานช่าง', '🛠️', '#003149', 1),
  (6, 4, 'วัฒนธรรมองค์กรและจรรยาบรรณ Omnoi', 'ค่านิยม จริยธรรม และแนวปฏิบัติของพนักงาน Omnoi Group', '🏛️', '#7a1315', 1);

INSERT INTO lessons (course_id, order_index, title, drive_video_id, drive_doc_id, dur) VALUES
  (1, 0, 'บทที่ 1: เข้าใจความต้องการลูกค้า', '', '', 25),
  (1, 1, 'บทที่ 2: การนำเสนอสินค้าให้โดนใจ', '', '', 30),
  (1, 2, 'บทที่ 3: เทคนิคปิดการขาย', '', '', 28),
  (2, 0, 'บทที่ 1: หัวใจของงานบริการ', '', '', 22),
  (2, 1, 'บทที่ 2: รับมือลูกค้าโกรธอย่างมืออาชีพ', '', '', 26),
  (3, 0, 'บทที่ 1: หลักการจัดเก็บ FIFO/FEFO', '', '', 24),
  (3, 1, 'บทที่ 2: การรับเข้าและตรวจสอบอะไหล่', '', '', 27),
  (3, 2, 'บทที่ 3: การตรวจนับและกระทบยอด', '', '', 23),
  (4, 0, 'บทที่ 1: ประเภทอะไหล่หลัก', '', '', 20),
  (4, 1, 'บทที่ 2: การอ่านรหัสและเทียบรุ่น', '', '', 25),
  (5, 0, 'บทที่ 1: ความปลอดภัยในโรงซ่อม (Safety First)', '', '', 26),
  (5, 1, 'บทที่ 2: การใช้เครื่องมือวัดอย่างถูกต้อง', '', '', 30),
  (5, 2, 'บทที่ 3: ขั้นตอนการซ่อมตามมาตรฐาน', '', '', 28),
  (6, 0, 'บทที่ 1: ค่านิยมและวิสัยทัศน์องค์กร', '', '', 20),
  (6, 1, 'บทที่ 2: จรรยาบรรณและการต่อต้านทุจริต', '', '', 24);

INSERT INTO quiz_questions (course_id, order_index, question, options, correct_index) VALUES
  (1, 0, 'ขั้นตอนแรกของการขายที่ดีคืออะไร?', '["เสนอราคาทันที","เข้าใจความต้องการลูกค้า","ลดราคาให้มากที่สุด","พูดให้เยอะที่สุด"]', 1),
  (1, 1, '"สัญญาณซื้อ" (Buying Signal) หมายถึงอะไร?', '["ลูกค้าเดินออกจากร้าน","ลูกค้าเริ่มถามรายละเอียด เงื่อนไข การผ่อน","ลูกค้าเงียบ","พนักงานเสนอโปรโมชั่น"]', 1),
  (1, 2, 'เมื่อลูกค้าลังเล ควรทำอย่างไร?', '["กดดันให้รีบตัดสินใจ","รับฟังข้อกังวลและให้ข้อมูลเพิ่ม","เปลี่ยนไปคุยลูกค้าคนอื่น","ยกเลิกการขาย"]', 1),
  (1, 3, 'การปิดการขายที่ดีควรจบด้วยอะไร?', '["ปล่อยให้ลูกค้าคิดเอง","สรุปข้อตกลงและขั้นตอนถัดไปอย่างชัดเจน","ไม่ต้องติดตามผล","ขึ้นราคา"]', 1),
  (2, 0, 'เมื่อลูกค้าโกรธ สิ่งแรกที่ควรทำคือ?', '["เถียงกลับ","ตั้งใจฟังและแสดงความเข้าใจ","โอนสายให้คนอื่น","วางสาย"]', 1),
  (2, 1, 'งานหลังการขายสำคัญอย่างไร?', '["ไม่สำคัญ","สร้างลูกค้าประจำและการบอกต่อ","เสียเวลา","เพิ่มต้นทุน"]', 1),
  (2, 2, 'การติดตามผลลูกค้าควรทำเมื่อใด?', '["ไม่ต้องทำ","หลังการขายตามรอบที่เหมาะสม","เฉพาะตอนมีโปร","หลังลูกค้าร้องเรียน"]', 1),
  (3, 0, 'หลักการ FIFO หมายถึงอะไร?', '["ของใหม่จ่ายก่อน","ของเข้าก่อนจ่ายก่อน","จ่ายแบบสุ่ม","ของแพงจ่ายก่อน"]', 1),
  (3, 1, 'เมื่อรับอะไหล่เข้าคลัง ต้องตรวจสอบอะไรเป็นอันดับแรก?', '["สีของกล่อง","จำนวนและรหัสตรงกับใบสั่งซื้อ","น้ำหนักรวม","ยี่ห้อรถขนส่ง"]', 1),
  (3, 2, 'การตรวจนับสต็อก (Stock Count) มีไว้เพื่ออะไร?', '["เพิ่มงานให้พนักงาน","กระทบยอดของจริงกับระบบ","ลดจำนวนสินค้า","ตกแต่งคลัง"]', 1),
  (4, 0, 'อะไหล่สิ้นเปลือง (Consumable) คือข้อใด?', '["เครื่องยนต์","น้ำมันเครื่อง/ไส้กรอง","ตัวถัง","เกียร์"]', 1),
  (4, 1, 'รหัส Part Number ใช้เพื่ออะไร?', '["ตกแต่ง","ระบุชิ้นส่วนได้ถูกต้องแม่นยำ","คิดราคาเล่นๆ","ไม่มีประโยชน์"]', 1),
  (4, 2, 'การเทียบรุ่น (Cross-reference) ช่วยเรื่องใด?', '["หาอะไหล่ทดแทนที่ใช้ร่วมกันได้","เพิ่มราคา","ลดคุณภาพ","ทำให้ลูกค้าสับสน"]', 0),
  (5, 0, 'อุปกรณ์ป้องกันส่วนบุคคล (PPE) ที่ช่างต้องใส่เสมอคือ?', '["ไม่จำเป็น","แว่นตา ถุงมือ รองเท้านิรภัย","เสื้อสูท","หมวกแฟชั่น"]', 1),
  (5, 1, 'ก่อนซ่อมระบบไฟฟ้าควรทำอะไรก่อน?', '["เปิดไฟไว้","ตัด/ปลดแหล่งจ่ายไฟ","ราดน้ำ","ใส่ถุงมือผ้า"]', 1),
  (5, 2, 'เครื่องมือวัดควรได้รับการ?', '["สอบเทียบ (Calibrate) ตามรอบ","ทิ้งไว้เฉยๆ","ใช้จนพัง","ดัดแปลงเอง"]', 0),
  (5, 3, 'เมื่อพบความผิดปกติที่อาจอันตราย ควร?', '["เพิกเฉย","หยุดงานและรายงานหัวหน้าทันที","ซ่อมลวกๆ","ปิดบัง"]', 1),
  (6, 0, 'หากได้รับของกำนัลมูลค่าสูงจากคู่ค้า ควรทำอย่างไร?', '["รับไว้เงียบๆ","แจ้งหัวหน้า/ปฏิบัติตามนโยบาย","ขอเพิ่ม","ขายต่อ"]', 1),
  (6, 1, 'ค่านิยมหลักของ Omnoi เน้นเรื่องใด?', '["ความน่าเชื่อถือและการเติบโตร่วมกัน","ทำงานคนเดียว","แข่งกันเอง","ปกปิดข้อมูล"]', 0),
  (6, 2, 'การรักษาความลับของบริษัทสำคัญอย่างไร?', '["ไม่สำคัญ","ปกป้องผลประโยชน์องค์กรและลูกค้า","เอาไว้คุยเล่น","โพสต์โซเชียลได้"]', 1);
