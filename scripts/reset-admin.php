<?php
/**
 * ตั้งรหัสผ่านผู้ดูแลระบบใหม่ (กรณีลืมรหัส)
 *   php scripts/reset-admin.php รหัสผ่านใหม่             (บัญชี admin)
 *   php scripts/reset-admin.php รหัสผ่านใหม่ ชื่อผู้ใช้    (บัญชีอื่น)
 */
require dirname(__DIR__) . '/src/app.php';

$pw = $argv[1] ?? '';
$uname = strtolower($argv[2] ?? 'admin');
if (mb_strlen($pw) < 6) { fwrite(STDERR, "ระบุรหัสผ่านใหม่อย่างน้อย 6 ตัวอักษร\n"); exit(1); }
$salt = uuid(); $hash = hash_password($pw);
$st = db()->prepare('UPDATE `Users` SET salt = ?, pass_hash = ?, active = ? WHERE LOWER(username) = ?');
$st->execute([$salt, $hash, 'true', $uname]);
if ($st->rowCount()) { echo "ตั้งรหัสผ่านใหม่ให้ {$uname} แล้ว\n"; exit(0); }
db()->prepare('INSERT INTO `Users` (id, username, name, role, department_id, teacher_id, email, salt, pass_hash, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
  ->execute([new_id(), $uname, 'ผู้ดูแลระบบ', 'admin', '', '', '', $salt, $hash, 'true']);
echo "สร้างบัญชีผู้ดูแล {$uname} ใหม่แล้ว\n";
