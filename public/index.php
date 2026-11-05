<?php
/**
 * ระบบจัดตารางเรียน–ตารางสอน และค่าสอนเกินภาระงาน วิทยาลัยการอาชีพโพนทอง (PHP + MariaDB)
 * ทุกคำขอเข้าที่ไฟล์นี้ ส่วนโค้ดและข้อมูลอยู่นอกโฟลเดอร์ public จึงเปิดจากเว็บโดยตรงไม่ได้
 */
if (PHP_SAPI === 'cli-server') {
  $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if (is_file($file) && basename($file) !== 'index.php') return false;
}
ini_set('display_errors', '0'); // ข้อความ error ต้องไม่ปนใน JSON ที่ส่งให้หน้าเว็บ
ini_set('log_errors', '1');
set_time_limit(300);
require dirname(__DIR__) . '/src/app.php';
handle_request();
