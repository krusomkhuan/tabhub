<?php
/**
 * นำเข้าข้อมูลลง MariaDB (แทนที่ข้อมูลเดิมของตารางที่พบในไฟล์)
 *   php scripts/import.php "ไฟล์.xlsx"
 *   php scripts/import.php data/tab-data.json
 */
require dirname(__DIR__) . '/src/app.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) { fwrite(STDERR, "ระบุไฟล์ .xlsx หรือ .json ที่จะนำเข้า\n"); exit(1); }

$norm = function ($v) {
  if ($v === null) return '';
  if (is_bool($v)) return $v ? 'true' : 'false';
  if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
  $s = (string)$v;
  return ($s === 'TRUE' || $s === 'FALSE') ? strtolower($s) : $s;
};
$by = [];
if (preg_match('/\.json$/i', $file)) {
  $d = json_decode((string)file_get_contents($file), true);
  foreach ((array)$d as $n => $rows) if (isset(SCHEMA[$n]) && is_array($rows)) $by[$n] = $rows;
} else {
  foreach (read_xlsx((string)file_get_contents($file)) as $n => $aoa) {
    if (!isset(SCHEMA[$n])) continue;
    $head = array_map(fn($h) => trim((string)$h), array_shift($aoa) ?? []);
    $by[$n] = [];
    foreach ($aoa as $r) {
      if (implode('', $r) === '') continue;
      $o = [];
      foreach ($head as $i => $h) if ($h !== '') $o[$h] = $r[$i] ?? '';
      $by[$n][] = $o;
    }
  }
}
if (!$by) { fwrite(STDERR, "ไม่พบแผ่นงาน/ตารางที่ชื่อตรงกับระบบ (Settings, Teachers, Plans, ...)\n"); exit(1); }
foreach ($by as $n => $rows) {
  $by[$n] = array_map(function ($o) use ($n, $norm) {
    $r = [];
    foreach (SCHEMA[$n] as $h) $r[$h] = $norm($o[$h] ?? null);
    return $r;
  }, $rows);
}
db_migrate();
replaceAllTables($by);
echo "นำเข้าเรียบร้อย:\n";
foreach ($by as $n => $rows) echo '  ' . str_pad($n, 14) . count($rows) . " แถว\n";
$hasAdmin = false;
foreach ($by['Users'] ?? [] as $u) if ($u['role'] === 'admin' && $u['pass_hash'] !== '') $hasAdmin = true;
if (!$hasAdmin) echo "\nไม่มีบัญชีผู้ดูแลในไฟล์ ให้รัน php scripts/install.php เพื่อสร้างบัญชี admin\n";
