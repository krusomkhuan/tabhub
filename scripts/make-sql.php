<?php
/**
 * สร้างไฟล์โครงสร้างฐานข้อมูลสำหรับนำเข้าผ่าน phpMyAdmin
 *   php scripts/make-sql.php
 */
require dirname(__DIR__) . '/src/core.php';
$head = "-- ระบบจัดตารางเรียน–ตารางสอน : โครงสร้างฐานข้อมูล MariaDB\n-- นำเข้าใน phpMyAdmin หลังเลือกฐานข้อมูลแล้ว หรือรัน php scripts/install.php\n\nSET NAMES utf8mb4;\n\n";
file_put_contents(APP_ROOT . '/sql/schema.sql', $head . schema_sql() . "\n");
echo "เขียน sql/schema.sql แล้ว\n";
