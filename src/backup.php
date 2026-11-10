<?php
/**
 * สำรองและกู้คืนข้อมูล: เก็บทุกตารางและรูปประกอบรายงานการสอนเป็นไฟล์เดียวใน data/backup (เปิดจากเว็บโดยตรงไม่ได้)
 * ไฟล์มีข้อมูลส่วนบุคคลและรหัสผ่านที่เข้ารหัสแล้ว จึงให้เฉพาะผู้ดูแลระบบเข้าถึง
 *
 * ไฟล์เป็น gzip ของ JSON ทีละบรรทัด เพื่ออ่านและเขียนรูปทีละรูปโดยไม่ต้องโหลดทั้งไฟล์ลงหน่วยความจำ
 *   บรรทัด 1: ข้อมูลไฟล์ {app, format:2, images, ...}
 *   บรรทัด 2: {tables}
 *   ถัดมา:    {image, data} รูปละบรรทัด
 *   สุดท้าย:   {end:true, images} ใช้ตรวจว่าไฟล์ไม่ขาดกลางทาง
 * ไฟล์รุ่นเก่ามีบรรทัดเดียว {app, tables, ...} ไม่มีรูป ยังกู้คืนได้
 */
const BACKUP_KINDS = ['auto' => 'อัตโนมัติ', 'manual' => 'สำรองเอง', 'prerestore' => 'ก่อนกู้คืน', 'upload' => 'อัปโหลด'];
const BACKUP_NAME = '/^tabhub-(\d{8})-(\d{6})-(auto|manual|prerestore|upload)-[a-f0-9]{6}\.json\.gz$/';
const BACKUP_FORMAT = 2;
const BACKUP_MAX_UPLOAD = 4 * 1024 * 1024 * 1024;
const BACKUP_MAX_LINE = 256 * 1024 * 1024;
const BACKUP_KEEP_DEFAULT = 14;
const BACKUP_BAD_FILE = 'ไฟล์นี้ไม่ใช่ไฟล์สำรองของระบบ หรือไฟล์เสียหาย';

function backup_dir(): string {
  $d = APP_ROOT . '/data/backup';
  if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
    throw new Exception('สร้างโฟลเดอร์ data/backup ไม่สำเร็จ ตรวจสิทธิ์เขียนของ PHP ในโฟลเดอร์ data');
  }
  return $d;
}

function backup_require_writable(): string {
  $d = backup_dir();
  clearstatcache(true, $d);
  if (!is_writable($d)) {
    throw new Exception('PHP ไม่มีสิทธิ์เขียนโฟลเดอร์ data/backup กรุณาตรวจ Permission และ Owner/Group บนโฮสติ้ง');
  }
  return $d;
}

/** เก็บสาเหตุจริงใน error log โดยไม่ส่งพาธเซิร์ฟเวอร์ให้หน้าเว็บ */
function backup_write_error(string $operation): Exception {
  $last = error_get_last();
  error_log('[backup ' . $operation . '] ' . ($last['message'] ?? 'filesystem operation failed'));
  return new Exception('บันทึกไฟล์ใน data/backup ไม่สำเร็จ กรุณาตรวจพื้นที่/โควตาโฮสติ้งและสิทธิ์เขียนของ PHP ดูสาเหตุเพิ่มเติมใน PHP error log');
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

/** รูปที่รายงานการสอนอ้างถึงในชุดข้อมูลนี้ */
function backup_image_names(array $reports): array {
  $names = [];
  foreach ($reports as $r) foreach (report_image_names($r) as $n) $names[$n] = true;
  return array_keys($names);
}

function backup_create(string $kind, string $by): string {
  @set_time_limit(0);
  backup_require_writable();
  $tables = [];
  foreach (array_keys(SCHEMA) as $t) $tables[$t] = readTable($t);
  $images = array_values(array_filter(backup_image_names(array_merge($tables['TeachReports'] ?? [], $tables['SupervisionVisits'] ?? [], $tables['SubDocs'] ?? [], $tables['SupervisionDocs'] ?? [])), fn($n) => is_file(report_image_dir() . '/' . $n)));

  $name = backup_new_name($kind);
  $tmp = backup_dir() . '/' . $name . '.tmp';
  // เขียนไฟล์ชั่วคราวก่อน เพื่อไม่ให้เหลือไฟล์ครึ่ง ๆ ถ้าระหว่างทางล้มเหลว
  $fail = 'เขียนไฟล์สำรองไม่สำเร็จ ตรวจสอบพื้นที่และสิทธิ์ของโฟลเดอร์ data/backup';
  $h = @gzopen($tmp, 'wb6');
  if (!$h) throw new Exception($fail);
  $put = function (array $o) use ($h, $fail) {
    $line = json_encode($o, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    if (gzwrite($h, $line) !== strlen($line)) throw new Exception($fail);
  };
  try {
    $put(['app' => 'tabhub', 'format' => BACKUP_FORMAT, 'version' => APP_VERSION, 'created_at' => date('c'), 'created_by' => $by, 'kind' => $kind, 'images' => count($images)]);
    $put(['tables' => $tables]);
    unset($tables);
    $n = 0;
    foreach ($images as $img) {
      $bytes = @file_get_contents(report_image_dir() . '/' . $img);
      if ($bytes === false) continue; // ถูกลบระหว่างสำรอง
      $put(['image' => $img, 'data' => base64_encode($bytes)]);
      $n++;
    }
    $put(['end' => true, 'images' => $n]);
    $closed = gzclose($h);
    $h = null;
    if (!$closed || !rename($tmp, backup_dir() . '/' . $name)) throw new Exception($fail);
  } catch (Throwable $e) {
    if ($h) @gzclose($h);
    @unlink($tmp);
    throw $e;
  }
  return $name;
}

/** อ่านไฟล์ gzip ทีละบรรทัด และจำกัดความยาวบรรทัดกันไฟล์ที่แตกแล้วใหญ่ผิดปกติ */
function backup_lines(string $path): Generator {
  $h = @gzopen($path, 'rb');
  if (!$h) throw new Exception(BACKUP_BAD_FILE);
  try {
    $buf = '';
    while (!gzeof($h)) {
      $chunk = gzread($h, 1 << 20);
      if ($chunk === false || $chunk === '') break;
      $buf .= $chunk;
      $from = 0;
      while (($p = strpos($buf, "\n", $from)) !== false) { yield substr($buf, $from, $p - $from); $from = $p + 1; }
      if ($from > 0) $buf = substr($buf, $from);
      if (strlen($buf) > BACKUP_MAX_LINE) throw new Exception(BACKUP_BAD_FILE);
    }
    if ($buf !== '') yield $buf;
  } finally {
    gzclose($h);
  }
}

/**
 * อ่านและตรวจไฟล์ทั้งไฟล์ว่าใช้กู้คืนได้จริง ถ้าระบุ $extractTo จะแตกรูปไปไว้ที่โฟลเดอร์นั้น
 * ชื่อรูปมาจากไฟล์ที่อัปโหลดได้ จึงต้องผ่านการตรวจชื่อและเนื้อไฟล์ก่อนเขียนลงดิสก์
 * คืน images เป็น null เมื่อเป็นไฟล์รุ่นเก่าที่ไม่มีรูป
 */
function backup_read(string $path, ?string $extractTo = null): array {
  $meta = null; $tables = null; $wanted = []; $count = 0; $ended = false; $legacy = false; $i = 0;
  foreach (backup_lines($path) as $line) {
    $o = json_decode($line, true);
    if (!is_array($o)) throw new Exception(BACKUP_BAD_FILE);
    if ($i === 0) {
      if (($o['app'] ?? '') !== 'tabhub') throw new Exception(BACKUP_BAD_FILE);
      $meta = $o;
      if (array_key_exists('tables', $o)) { $legacy = true; $tables = $o['tables']; break; }
    } elseif ($i === 1) {
      $tables = $o['tables'] ?? null;
      if (is_array($tables)) $wanted = array_flip(backup_image_names(array_merge(is_array($tables['TeachReports'] ?? null) ? $tables['TeachReports'] : [], is_array($tables['SupervisionVisits'] ?? null) ? $tables['SupervisionVisits'] : [], is_array($tables['SubDocs'] ?? null) ? $tables['SubDocs'] : [], is_array($tables['SupervisionDocs'] ?? null) ? $tables['SupervisionDocs'] : [])));
    } elseif (!empty($o['end'])) {
      if ((int)($o['images'] ?? -1) !== $count) throw new Exception(BACKUP_BAD_FILE);
      $ended = true;
      break;
    } else {
      $img = (string)($o['image'] ?? '');
      $bytes = base64_decode((string)($o['data'] ?? ''), true);
      if (!preg_match(REPORT_IMAGE_NAME, $img) || $bytes === false || strlen($bytes) > REPORT_MAX_IMAGE_BYTES || report_image_ext($bytes) !== pathinfo($img, PATHINFO_EXTENSION)) throw new Exception(BACKUP_BAD_FILE);
      if ($extractTo !== null && isset($wanted[$img]) && file_put_contents($extractTo . '/' . $img, $bytes) !== strlen($bytes)) throw new Exception('แตกรูปจากไฟล์สำรองไม่สำเร็จ ตรวจสอบพื้นที่ของเครื่อง');
      $count++;
    }
    $i++;
  }
  if (!is_array($tables) || (!$legacy && !$ended)) throw new Exception(BACKUP_BAD_FILE);
  $tables = array_intersect_key(normalize_user_table($tables), SCHEMA);
  foreach ($tables as $rows) if (!is_array($rows)) throw new Exception('ข้อมูลในไฟล์สำรองไม่ถูกต้อง');
  $admin = false;
  foreach ($tables['users'] ?? [] as $u) if (($u['role'] ?? '') === 'admin' && ($u['pass_hash'] ?? '') !== '' && (string)($u['active'] ?? '') !== 'false') $admin = true;
  if (!$admin) throw new Exception('กู้คืนไม่ได้ เพราะไฟล์นี้ไม่มีบัญชีผู้ดูแลระบบ จะทำให้เข้าระบบไม่ได้');
  if (empty($tables['Settings'])) throw new Exception('กู้คืนไม่ได้ เพราะไฟล์นี้ไม่มีค่าตั้งค่าของระบบ');
  return ['tables' => $tables, 'images' => $legacy ? null : $count, 'version' => (string)($meta['version'] ?? ''), 'created_at' => (string)($meta['created_at'] ?? '')];
}

/** แทนที่เฉพาะตารางที่มีในไฟล์ ตารางที่ไฟล์เก่ายังไม่มีจะคงข้อมูลเดิม */
function backup_apply(array $tables): int {
  $n = 0;
  foreach ($tables as $name => $rows) { writeTable($name, array_values($rows)); $n += count($rows); }
  return $n;
}

/** ย้ายรูปที่แตกแล้วเข้าที่ แล้วค่อยลบรูปที่ไม่อยู่ในไฟล์ ถ้าล้มเหลวกลางทางรูปเดิมจะยังอยู่ครบ */
function backup_swap_images(string $from): void {
  $dir = report_image_dir();
  $keep = [];
  foreach (glob($from . '/*') ?: [] as $p) {
    $n = basename($p);
    if (!rename($p, $dir . '/' . $n)) throw new Exception('กู้คืนรูปไม่สำเร็จ ตรวจสอบสิทธิ์ของโฟลเดอร์ data/teach_images');
    $keep[$n] = true;
  }
  foreach (glob($dir . '/*') ?: [] as $p) {
    $n = basename($p);
    if (preg_match(REPORT_IMAGE_NAME, $n) && !isset($keep[$n])) @unlink($p);
  }
}

function backup_files(): array {
  $out = [];
  foreach (glob(backup_dir() . '/tabhub-*.json.gz') ?: [] as $p) {
    $name = basename($p);
    if (!preg_match(BACKUP_NAME, $name, $m)) continue;
    $t = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]);
    $out[] = ['name' => $name, 'time' => $t ? $t->format('Y-m-d H:i:s') : '', 'kind' => $m[3], 'kind_label' => BACKUP_KINDS[$m[3]], 'size' => filesize($p), 'mtime' => filemtime($p)];
  }
  stable_usort($out, fn($a, $b) => strcmp($b['time'], $a['time']) ?: strcmp($b['name'], $a['name']));
  return $out;
}

/** จำนวนรูปจากบรรทัดแรกของไฟล์ null = ไฟล์รุ่นเก่าที่ไม่มีรูป */
function backup_file_images(string $path): ?int {
  try {
    foreach (backup_lines($path) as $line) {
      if (strlen($line) > 4096) return null; // บรรทัดแรกยาวแปลว่ามีตารางอยู่ด้วย คือไฟล์รุ่นเก่า
      $o = json_decode($line, true);
      return is_array($o) && isset($o['format'], $o['images']) ? (int)$o['images'] : null;
    }
  } catch (Throwable $e) {}
  return null;
}

function backup_prune(int $keep): void {
  $auto = array_values(array_filter(backup_files(), fn($f) => $f['kind'] === 'auto'));
  foreach (array_slice($auto, max(1, $keep)) as $f) @unlink(backup_dir() . '/' . $f['name']);
}

/** ลบไฟล์ค้างจากการสำรองหรืออัปโหลดที่ไม่จบ */
function backup_clean_temp(): void {
  foreach (array_merge(glob(backup_dir() . '/*.tmp') ?: [], glob(backup_dir() . '/upload-*.part') ?: []) as $p) {
    if (filemtime($p) < time() - 86400) @unlink($p);
  }
}

// ======================= สำรองอัตโนมัติ =======================
const BACKUP_STATUS_KEY = 'backup:auto_status';

/** ถึงรอบสำรองเมื่อไฟล์อัตโนมัติล่าสุดเก่ากว่า 1 วัน (นับจากเวลาในชื่อไฟล์ ไม่ใช้เวลาแก้ไขไฟล์ที่เปลี่ยนได้เมื่อคัดลอก) */
function backup_auto_due(): bool {
  clearstatcache();
  foreach (backup_files() as $f) {
    if ($f['kind'] !== 'auto') continue;
    $t = strtotime($f['time']);
    return $t === false || $t <= time() - 86400;
  }
  return true;
}

/** ผลการสำรองอัตโนมัติครั้งล่าสุด: state = running | ok | error เพื่อให้ผู้ดูแลเห็นเมื่อสำรองไม่สำเร็จ */
function backup_status(): array {
  try { $r = kvGet(BACKUP_STATUS_KEY); } catch (Throwable $_) { return []; }
  $o = $r ? json_decode($r, true) : null;
  return is_array($o) ? $o : [];
}
function backup_status_put(array $s): void {
  try { kvPut(BACKUP_STATUS_KEY, json_encode($s + ['at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)); } catch (Throwable $_) {}
}

/**
 * สำรองอัตโนมัติวันละครั้ง ทำงานเมื่อมีผู้ใช้เข้าระบบ ถ้าล้มเหลวต้องไม่กระทบคำขอของผู้ใช้
 * mode = page_load: สำรองระหว่างโหลดหน้าเว็บ ใช้ได้กับทุกโฮสติ้ง
 * mode = after_response: ส่งคำตอบก่อนแล้วค่อยสำรอง โฮสติ้งบางแห่งหยุดสคริปต์หลังส่งคำตอบ จึงเป็นทางสำรองเท่านั้น
 * ใช้ล็อกของฐานข้อมูลกันหลายคำขอสำรองพร้อมกัน เพราะ flock ใช้ไม่ได้บนดิสก์ของโฮสติ้งบางแบบ
 */
function backup_auto_run(string $mode): bool {
  $locked = false;
  try {
    if ((readSettings()['backup_auto'] ?? 'true') === 'false' || !backup_auto_due()) return false;
    $st = db()->prepare('SELECT GET_LOCK(?, 0) g');
    $st->execute(['tabhub_backup_' . db_name()]);
    $locked = (int)$st->fetch()['g'] === 1;
    $st->closeCursor();
    if (!$locked || !backup_auto_due()) return false;
    backup_status_put(['state' => 'running', 'mode' => $mode]);
    if ($mode === 'after_response') finish_response();
    $name = backup_create('auto', 'ระบบ');
    backup_prune((int)(readSettings()['backup_keep'] ?? BACKUP_KEEP_DEFAULT) ?: BACKUP_KEEP_DEFAULT);
    backup_clean_temp();
    backup_status_put(['state' => 'ok', 'mode' => $mode, 'file' => $name]);
    return true;
  } catch (Throwable $e) {
    error_log('[backup auto] ' . $e);
    $msg = $e->getMessage();
    backup_status_put(['state' => 'error', 'mode' => $mode, 'error' => preg_match('/[\x{0E00}-\x{0E7F}]/u', $msg) ? $msg : 'เกิดข้อผิดพลาดของเซิร์ฟเวอร์ ดูรายละเอียดใน PHP error log (ขึ้นต้นด้วย [backup auto])']);
    return false;
  } finally {
    if ($locked) { try { $st = db()->prepare('SELECT RELEASE_LOCK(?)'); $st->execute(['tabhub_backup_' . db_name()]); $st->closeCursor(); } catch (Throwable $_) {} }
  }
}

/** เรียกหลังส่งคำตอบของทุกคำขอ */
function backup_auto_tick(): void { backup_auto_run('after_response'); }

/** ข้อความเตือนผู้ดูแลเมื่อไม่มีไฟล์สำรองอัตโนมัติใน 2 วัน หรือครั้งล่าสุดล้มเหลว */
function backup_warning(): string {
  try {
    if ((readSettings()['backup_auto'] ?? 'true') === 'false') return '';
    $last = '';
    foreach (backup_files() as $f) if ($f['kind'] === 'auto') { $last = $f['time']; break; }
    $s = backup_status();
    if (($s['state'] ?? '') === 'error') return 'สำรองข้อมูลอัตโนมัติไม่สำเร็จ: ' . ($s['error'] ?? '') . ' — ดูที่เมนูสำรองข้อมูล';
    if ($last === '' || strtotime($last) < time() - 2 * 86400) return 'ไม่มีไฟล์สำรองอัตโนมัติใน 2 วันที่ผ่านมา' . ($last ? ' (ล่าสุด ' . $last . ')' : '') . ' — กรุณาสำรองข้อมูลที่เมนูสำรองข้อมูล';
  } catch (Throwable $e) { return 'ตรวจสถานะสำรองข้อมูลไม่สำเร็จ: ' . $e->getMessage(); }
  return '';
}

// ======================= API (เฉพาะผู้ดูแลระบบ) =======================
function api_backupList($token = '') {
  auth_user($token, 'admin');
  $s = readSettings();
  $files = array_map(fn($f) => $f + ['images' => backup_file_images(backup_dir() . '/' . $f['name'])], backup_files());
  $count = 0; $bytes = 0;
  foreach (backup_image_names(array_merge(readTable('TeachReports'), readTable('SupervisionVisits'), readTable('SubDocs'), readTable('SupervisionDocs'))) as $n) {
    $p = report_image_dir() . '/' . $n;
    if (is_file($p)) { $count++; $bytes += filesize($p); }
  }
  return ['files' => $files, 'auto' => ($s['backup_auto'] ?? 'true') !== 'false', 'keep' => (int)($s['backup_keep'] ?? BACKUP_KEEP_DEFAULT) ?: BACKUP_KEEP_DEFAULT, 'image_count' => $count, 'image_bytes' => $bytes,
    'auto_status' => backup_status(), 'warning' => backup_warning(), 'now' => date('Y-m-d H:i:s'), 'dirs' => data_dirs_writable()];
}

/** โฟลเดอร์ที่ระบบต้องเขียนได้ ใช้ตรวจ Permission บนโฮสติ้งจากหน้าสำรองข้อมูล */
function data_dirs_writable(): array {
  $out = [];
  foreach (['data' => 'ข้อมูลระบบ (schema.hash)', 'data/backup' => 'ไฟล์สำรองข้อมูล', 'data/teach_images' => 'รูปรายงานการสอน นิเทศ และเอกสาร', 'data/exports' => 'ไฟล์ Excel ที่ส่งออก'] as $rel => $use) {
    $p = APP_ROOT . '/' . $rel;
    if (!is_dir($p)) @mkdir($p, 0755, true);
    clearstatcache(true, $p);
    $exists = is_dir($p);
    $out[] = ['dir' => $rel, 'use' => $use, 'exists' => $exists, 'writable' => $exists && is_writable($p),
      'perms' => $exists ? substr(sprintf('%o', @fileperms($p)), -3) : '', 'owner' => $exists ? sys_user_name(@fileowner($p)) : '', 'group' => $exists ? sys_group_name(@filegroup($p)) : '', 'php_user' => sys_user_name(function_exists('posix_geteuid') ? posix_geteuid() : @getmyuid())];
  }
  return $out;
}

/** ชื่อบัญชีจาก uid/gid เพื่อเทียบ Owner ของโฟลเดอร์กับบัญชีที่ PHP ใช้ (ถ้าเครื่องไม่มี posix จะแสดงเป็นตัวเลข) */
function sys_user_name($uid): string {
  if ($uid === false || $uid === null) return '';
  $u = function_exists('posix_getpwuid') ? @posix_getpwuid((int)$uid) : false;
  return $u && isset($u['name']) ? $u['name'] . ' (' . $uid . ')' : (string)$uid;
}
function sys_group_name($gid): string {
  if ($gid === false || $gid === null) return '';
  $g = function_exists('posix_getgrgid') ? @posix_getgrgid((int)$gid) : false;
  return $g && isset($g['name']) ? $g['name'] . ' (' . $gid . ')' : (string)$gid;
}

function api_backupCreate($token = '') {
  $me = auth_user($token, 'admin');
  $name = backup_create('manual', $me['name']);
  log_action($me, 'สำรองข้อมูล', $name);
  return $name;
}

function api_backupDownload($token = '', $name = '') {
  $me = auth_user($token, 'admin');
  @set_time_limit(0);
  $p = backup_path(str($name));
  log_action($me, 'ดาวน์โหลดไฟล์สำรอง', basename($p));
  return save_download_file($p, basename($p));
}

function api_backupDelete($token = '', $name = '') {
  $me = auth_user($token, 'admin');
  $p = backup_path(str($name));
  if (!@unlink($p)) throw new Exception('ลบไฟล์สำรองไม่สำเร็จ');
  log_action($me, 'ลบไฟล์สำรอง', basename($p));
  return true;
}

/** อัปโหลดทีละส่วน เพราะไฟล์ที่มีรูปอาจใหญ่เกินขนาดคำขอที่เซิร์ฟเวอร์รับได้ ส่วนสุดท้ายจะตรวจไฟล์ทั้งไฟล์ก่อนเก็บ */
function api_backupUploadChunk($token = '', $id = '', $offset = 0, $base64 = '', $last = false) {
  $me = auth_user($token, 'admin');
  @set_time_limit(0);
  $dir = backup_require_writable();
  $id = str($id);
  if ($id === '') { backup_clean_temp(); $id = bin2hex(random_bytes(12)); }
  elseif (!preg_match('/^[a-f0-9]{24}$/', $id)) throw new Exception('อ่านไฟล์ไม่สำเร็จ');
  $part = $dir . '/upload-' . $id . '.part';
  clearstatcache(true, $part);
  $size = is_file($part) ? filesize($part) : 0;
  if ((int)$offset !== $size) throw new Exception('ส่งไฟล์ไม่ต่อเนื่อง กรุณาอัปโหลดใหม่');
  $bytes = base64_decode(str($base64), true);
  if ($bytes === false) throw new Exception('อ่านไฟล์ไม่สำเร็จ');
  if ($size + strlen($bytes) > BACKUP_MAX_UPLOAD) { @unlink($part); throw new Exception('ไฟล์ใหญ่เกินไป'); }
  error_clear_last();
  if (@file_put_contents($part, $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) {
    $error = backup_write_error('upload chunk');
    @unlink($part);
    throw $error;
  }
  if (!$last) return ['id' => $id];
  try {
    backup_read($part); // ไฟล์ที่ใช้กู้คืนไม่ได้จะไม่ถูกบันทึก
    $name = backup_new_name('upload');
    error_clear_last();
    if (!@rename($part, $dir . '/' . $name)) throw backup_write_error('finish upload');
  } catch (Throwable $e) { @unlink($part); throw $e; }
  log_action($me, 'อัปโหลดไฟล์สำรอง', $name);
  return ['name' => $name];
}

function api_backupRestore($token = '', $name = '', $confirm = '') {
  $me = auth_user($token, 'admin');
  if (str($confirm) !== 'กู้คืน') throw new Exception('กรุณาพิมพ์คำว่า กู้คืน เพื่อยืนยัน');
  @set_time_limit(0);
  $tmp = backup_dir() . '/.restore-' . bin2hex(random_bytes(6));
  if (!@mkdir($tmp)) throw new Exception('เตรียมการกู้คืนไม่สำเร็จ ตรวจสอบสิทธิ์ของโฟลเดอร์ data/backup');
  try {
    // ตรวจและแตกรูปให้ครบก่อนแตะข้อมูลจริง ถ้าไฟล์เสียจะไม่มีอะไรเปลี่ยน
    $data = backup_read(backup_path(str($name)), $tmp);
    return with_lock(function () use ($me, $name, $data, $tmp) {
      // เก็บสถานะปัจจุบันไว้ก่อนเสมอ เพื่อย้อนกลับได้ถ้ากู้ผิดไฟล์
      $before = backup_create('prerestore', $me['name']);
      $rows = backup_apply($data['tables']);
      // ไฟล์รุ่นเก่าไม่มีรูป จึงคงรูปในเครื่องไว้ตามเดิม
      if ($data['images'] !== null) backup_swap_images($tmp);
      log_action($me, 'กู้คืนข้อมูล', str($name) . ' (สำรองก่อนกู้: ' . $before . ')');
      return ['rows' => $rows, 'images' => $data['images'], 'before' => $before];
    });
  } finally {
    foreach (glob($tmp . '/*') ?: [] as $p) @unlink($p);
    @rmdir($tmp);
  }
}
