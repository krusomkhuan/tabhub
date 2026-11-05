<?php
/**
 * ระบบจัดตารางเรียน–ตารางสอน และค่าสอนเกินภาระงาน วิทยาลัยการอาชีพโพนทอง (PHP + MariaDB)
 * ทุกคำขอเข้าที่ไฟล์นี้ (ดู .htaccess) เปิดที่ http://localhost/tabhub/
 */
ini_set('display_errors', '0'); // ข้อความ error ต้องไม่ปนใน JSON ที่ส่งให้หน้าเว็บ
ini_set('log_errors', '1');
set_time_limit(300);
require __DIR__ . '/src/app.php';
handle_request();
