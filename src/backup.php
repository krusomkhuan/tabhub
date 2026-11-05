<?php
/**
 * สำรองและกู้คืนข้อมูล: เก็บทุกตารางเป็น JSON บีบอัดใน data/backup (เปิดจากเว็บโดยตรงไม่ได้)
 * ไฟล์มีข้อมูลส่วนบุคคลและรหัสผ่านที่เข้ารหัสแล้ว จึงให้เฉพาะผู้ดูแลระบบเข้าถึง
 */
const BACKUP_KINDS = ['auto' => 'อัตโนมัติ', 'manual' => 'สำรองเอง', 'prerestore' => 'ก่อนกู้คืน', 'upload' => 'อัปโหลด'];
const BACKUP_NAME = '/^tabhub-(\d{8})-(\d{6})-(auto|manual|prerestore|upload)-[a-f0-9]{6}\.json\.gz$/';
const BACKUP_MAX_UPLOAD = 64 * 1024 * 1024;
const BACKUP_MAX_JSON = 512 * 1024 * 1024;
const BACKUP_KEEP_DEFAULT = 14;

function backup_dir(): string {
  $d = APP_ROOT . '/data/backup';
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}

function backup_path(string $name): string {
  if (!preg_match(BACKUP_NAME, $name)) throw new Exception('ชื่อไฟล์สำรองไม่ถูกต้อง');
  $p = backup_dir() . '/' . $name;
  if (!is_file($p)) throw new Exception('ไม่พบไฟล์สำรอง');
  return $p;
}

function backup_new_name(string $kind): string {
  return 'tabhub-' . date('Ymd-His') . '-' . $kind . '-' . bin2hex(random_bytes(3)) . '.json.gz';
}

function backup_write(string $name, string $bytes): void {
  $p = backup_dir() . '/' . $name;
  // เขียนไฟล์ชั่วคราวก่อน เพื่อไม่ให้เหลือไฟล์ครึ่ง ๆ ถ้าระหว่างทางล้มเหลว
  if (file_put_contents($p . '.tmp', $bytes) !== strlen($bytes) || !rename($p . '.tmp', $p)) { @unlink($p . '.tmp'); throw new Exception('เขียนไฟล์สำรองไม่สำเร็จ ตรวจสอบพื้นที่และสิทธิ์ของโฟลเดอร์ data/backup'); }
}

function backup_create(string $kind, string $by): string {
  $tables = [];
  foreach (array_keys(SCHEMA) as $t) $tables[$t] = readTable($t);
  $json = json_encode(['app' => 'tabhub', 'version' => APP_VERSION, 'created_at' => date('c'), 'created_by' => $by, 'kind' => $kind, 'tables' => $tables], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  $name = backup_new_name($kind);
  backup_write($name, gzencode($json, 6));
  return $name;
}

/** แตกไฟล์และตรวจว่าใช้กู้คืนได้จริง คืนข้อมูลตารางที่รู้จัก */
function backup_decode(string $gz): array {
  $json = @gzdecode($gz, BACKUP_MAX_JSON);
  if ($json === false) throw new Exception('ไฟล์นี้ไม่ใช่ไฟล์สำรองของระบบ หรือไฟล์เสียหาย');
  $o = json_decode($json, true);
  if (!is_array($o) || ($o['app'] ?? '') !== 'tabhub' || !is_array($o['tables'] ?? null)) throw new Exception('ไฟล์นี้ไม่ใช่ไฟล์สำรองของระบบ หรือไฟล์เสียหาย');
  $tables = array_intersect_key($o['tables'], SCHEMA);
  foreach ($tables as $rows) if (!is_array($rows)) throw new Exception('ข้อมูลในไฟล์สำรองไม่ถูกต้อง');
  $admin = false;
  foreach ($tables['Users'] ?? [] as $u) if (($u['role'] ?? '') === 'admin' && ($u['pass_hash'] ?? '') !== '' && (string)($u['active'] ?? '') !== 'false') $admin = true;
  if (!$admin) throw new Exception('กู้คืนไม่ได้ เพราะไฟล์นี้ไม่มีบัญชีผู้ดูแลระบบ จะทำให้เข้าระบบไม่ได้');
  if (empty($tables['Settings'])) throw new Exception('กู้คืนไม่ได้ เพราะไฟล์นี้ไม่มีค่าตั้งค่าของระบบ');
  return ['tables' => $tables, 'version' => (string)($o['version'] ?? ''), 'created_at' => (string)($o['created_at'] ?? '')];
}

/** แทนที่เฉพาะตารางที่มีในไฟล์ ตารางที่ไฟล์เก่ายังไม่มีจะคงข้อมูลเดิม */
function backup_apply(array $tables): int {
  $n = 0;
  foreach ($tables as $name => $rows) { writeTable($name, array_values($rows)); $n += count($rows); }
  return $n;
}

function backup_files(): array {
  $out = [];
  foreach (glob(backup_dir() . '/tabhub-*.json.gz') ?: [] as $p) {
    $name = basename($p);
    if (!preg_match(BACKUP_NAME, $name, $m)) continue;
    $t = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]);
    $out[] = ['name' => $name, 'time' => $t ? $t->format('Y-m-d H:i:s') : '', 'kind' => $m[3], 'kind_label' => BACKUP_KINDS[$m[3]], 'size' => filesize($p), 'mtime' => filemtime($p)];
  }
  usort($out, fn($a, $b) => strcmp($b['time'], $a['time']) ?: strcmp($b['name'], $a['name']));
  return $out;
}

function backup_prune(int $keep): void {
  $auto = array_values(array_filter(backup_files(), fn($f) => $f['kind'] === 'auto'));
  foreach (array_slice($auto, max(1, $keep)) as $f) @unlink(backup_dir() . '/' . $f['name']);
}

/** สำรองอัตโนมัติวันละครั้ง ทำงานเมื่อมีผู้ใช้เข้าระบบ ถ้าล้มเหลวต้องไม่กระทบคำขอของผู้ใช้ */
function backup_auto_tick(): void {
  try {
    $s = readSettings();
    if (($s['backup_auto'] ?? 'true') === 'false') return;
    foreach (backup_files() as $f) if ($f['kind'] === 'auto') { if ($f['mtime'] > time() - 86400) return; break; }
    backup_create('auto', 'ระบบ');
    backup_prune((int)($s['backup_keep'] ?? BACKUP_KEEP_DEFAULT) ?: BACKUP_KEEP_DEFAULT);
  } catch (Throwable $e) { error_log('[backup auto] ' . $e); }
}

// ======================= API (เฉพาะผู้ดูแลระบบ) =======================
function api_backupList($token = '') {
  auth_user($token, 'admin');
  $s = readSettings();
  return ['files' => backup_files(), 'auto' => ($s['backup_auto'] ?? 'true') !== 'false', 'keep' => (int)($s['backup_keep'] ?? BACKUP_KEEP_DEFAULT) ?: BACKUP_KEEP_DEFAULT];
}

function api_backupCreate($token = '') {
  $me = auth_user($token, 'admin');
  $name = backup_create('manual', $me['name']);
  log_action($me, 'สำรองข้อมูล', $name);
  return $name;
}

function api_backupDownload($token = '', $name = '') {
  $me = auth_user($token, 'admin');
  $p = backup_path(str($name));
  log_action($me, 'ดาวน์โหลดไฟล์สำรอง', basename($p));
  return save_download((string)file_get_contents($p), basename($p));
}

function api_backupDelete($token = '', $name = '') {
  $me = auth_user($token, 'admin');
  $p = backup_path(str($name));
  if (!@unlink($p)) throw new Exception('ลบไฟล์สำรองไม่สำเร็จ');
  log_action($me, 'ลบไฟล์สำรอง', basename($p));
  return true;
}

function api_backupUpload($token = '', $base64 = '') {
  $me = auth_user($token, 'admin');
  $gz = base64_decode(str($base64), true);
  if ($gz === false || $gz === '') throw new Exception('อ่านไฟล์ไม่สำเร็จ');
  if (strlen($gz) > BACKUP_MAX_UPLOAD) throw new Exception('ไฟล์ใหญ่เกินไป');
  backup_decode($gz); // ตรวจก่อนเก็บ ไฟล์ที่ใช้กู้คืนไม่ได้จะไม่ถูกบันทึก
  $name = backup_new_name('upload');
  backup_write($name, $gz);
  log_action($me, 'อัปโหลดไฟล์สำรอง', $name);
  return $name;
}

function api_backupRestore($token = '', $name = '', $confirm = '') {
  $me = auth_user($token, 'admin');
  if (str($confirm) !== 'กู้คืน') throw new Exception('กรุณาพิมพ์คำว่า กู้คืน เพื่อยืนยัน');
  $data = backup_decode((string)file_get_contents(backup_path(str($name))));
  return with_lock(function () use ($me, $name, $data) {
    // เก็บสถานะปัจจุบันไว้ก่อนเสมอ เพื่อย้อนกลับได้ถ้ากู้ผิดไฟล์
    $before = backup_create('prerestore', $me['name']);
    $rows = backup_apply($data['tables']);
    log_action($me, 'กู้คืนข้อมูล', str($name) . ' (สำรองก่อนกู้: ' . $before . ')');
    return ['rows' => $rows, 'before' => $before];
  });
}
