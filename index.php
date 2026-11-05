<?php
/**
 * หน้าเข้าระบบจาก http://localhost/tabhub/ : พาไปยังตัวระบบที่พอร์ต 8080
 * ตัวระบบจริงอยู่ที่ public/index.php
 */
$host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
if (!preg_match('/^(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.-]+)$/', $host)) $host = 'localhost';
header('Location: http://' . $host . ':8080/', true, 302);
header('Cache-Control: no-store');
