<?php
/**
 * เตรียมฐานข้อมูล: สร้างตาราง ค่าตั้งต้น และผู้ดูแลระบบคนแรก (ถ้ายังไม่มี)
 *   php scripts/install.php
 */
require dirname(__DIR__) . '/src/app.php';
db_migrate();
$s = readSettings();
foreach (DEFAULT_SETTINGS as $k => $v) if (!array_key_exists($k, $s)) appendRows('Settings', [['key' => $k, 'value' => $v]]);
$users = readTable('users');
$hasAdmin = false; $hasName = false;
foreach ($users as $u) {
  if ($u['role'] === 'admin' && $u['pass_hash'] !== '' && $u['active'] !== 'false') $hasAdmin = true;
  if (strtolower($u['username']) === 'admin') $hasName = true;
}
if (!$hasAdmin && !$hasName) {
  $first = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
  appendRows('users', [['id' => new_id(), 'username' => 'admin', 'name' => 'ผู้ดูแลระบบ', 'role' => 'admin', 'department_id' => '', 'teacher_id' => '', 'salt' => uuid(), 'pass_hash' => hash_password($first), 'active' => true]]);
  echo "สร้างผู้ใช้ admin แล้ว รหัสผ่านชั่วคราว: {$first}\nเข้าระบบแล้วเปลี่ยนรหัสผ่านทันที\n";
}
echo "เตรียมฐานข้อมูล " . db_name() . " เรียบร้อย\n";
