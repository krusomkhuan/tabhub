<?php
/**
 * ให้ระบบทำงานบน PHP 7.4 ได้เหมือน PHP 8 ต้องโหลดไฟล์นี้ก่อนไฟล์อื่น
 * ฟังก์ชันด้านล่างมีเฉพาะใน PHP 8 จึงประกาศเมื่อยังไม่มีเท่านั้น
 */
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}
if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    return $needle === '' || substr_compare($haystack, $needle, -strlen($needle)) === 0;
  }
}
if (!function_exists('array_is_list')) {
  function array_is_list(array $array): bool {
    $i = 0;
    foreach ($array as $k => $_) { if ($k !== $i++) return false; }
    return true;
  }
}

/**
 * usort ที่คงลำดับเดิมเมื่อค่าเท่ากัน PHP 8 ทำให้อยู่แล้ว แต่ PHP 7.4 ไม่รับประกัน
 * ผลการจัดตารางอัตโนมัติและการตัดชั่วโมงเบิกจึงต้องใช้ตัวนี้ เพื่อให้ได้ผลเหมือนกันทุกเวอร์ชัน
 */
function stable_usort(array &$array, callable $compare): void {
  $i = 0;
  foreach ($array as $k => $v) $array[$k] = [$v, $i++];
  usort($array, function ($a, $b) use ($compare) { return $compare($a[0], $b[0]) ?: $a[1] <=> $b[1]; });
  foreach ($array as $k => $v) $array[$k] = $v[0];
}
