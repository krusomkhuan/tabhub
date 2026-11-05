<?php
/**
 * แกนระบบ: ค่าตั้งค่า, ฐานข้อมูล MariaDB, การอ่าน/เขียนตาราง, ตัวช่วยทั่วไป
 * ทุกคอลัมน์เก็บเป็นข้อความ และทุกตารางมี _seq ไว้เรียงลำดับแถว
 */
date_default_timezone_set('Asia/Bangkok');
define('APP_ROOT', dirname(__DIR__));
// เวอร์ชันของเว็บ: เปลี่ยนทุกครั้งที่ปล่อยงานใหม่ มือถือจะล้างไฟล์ที่เก็บไว้ตามเลขนี้
const APP_VERSION = '1.0.0';
const APP_DEVELOPERS = 'ครูสมควร สากำ และครูปฎิภาน พานทอง ครูประจำแผนกวิชาเทคโนโลยีธุรกิจดิจิทัล';

const SCHEMA = [
  'Settings'     => ['key', 'value'],
  'Semesters'    => ['id', 'year', 'term', 'label', 'is_default'],
  'Departments'  => ['id', 'name', 'sort'],
  'Majors'       => ['id', 'department_id', 'name', 'level', 'sort'],
  'Teachers'     => ['id', 'code', 'name', 'department_id', 'major_id', 'position', 'employment', 'is_dept_head', 'is_unit_head', 'service_start',
                     'award_bonus', 'award_note', 'ot_enabled', 'ot_scheme_ids', 'education', 'special_duty', 'max_per_day', 'unavailable', 'email', 'tab_id', 'is_dept_academic'],
  'Buildings'    => ['id', 'name'],
  'Rooms'        => ['id', 'building_id', 'name', 'type', 'capacity', 'shareable', 'tab_id'],
  'Courses'      => ['id', 'code', 'name', 'theory', 'practice', 'credits', 'level', 'course_type', 'kind', 'subject_group', 'color'],
  'Groups'       => ['id', 'code', 'name', 'level', 'year_level', 'major_id', 'department_id', 'students', 'room_id', 'advisor_id', 'student_code', 'tab_id'],
  'Plans'        => ['id', 'semester_id', 'group_id', 'course_id', 'teacher_ids', 'room_id', 'periods', 'split', 'joint_key', 'workplace', 'note', 'hour_type'],
  'Sessions'     => ['id', 'semester_id', 'plan_id', 'group_id', 'course_id', 'teacher_ids', 'room_id', 'day', 'start', 'length', 'locked', 'updated_by', 'updated_at'],
  'LockedSlots'  => ['id', 'day', 'period', 'label'],
  'Windows'      => ['id', 'semester_id', 'department_id', 'start_date', 'end_date', 'sort'],
  // คาบ PLC: ไม่ระบุ teacher_id = ทั้งแผนก, ระบุ teacher_id = เฉพาะครูคนนั้น และใช้แทนคาบของแผนก
  'PlcSlots'     => ['id', 'department_id', 'teacher_id', 'day', 'start', 'length'],
  'Users'        => ['id', 'username', 'name', 'role', 'department_id', 'teacher_id', 'email', 'salt', 'pass_hash', 'active'],
  'ColorGroups'  => ['id', 'category', 'name', 'color', 'items', 'sort'],
  'IssueReports' => ['id', 'title', 'category', 'detail', 'status', 'reply', 'replied_by', 'reporter_id', 'reporter_name', 'created_at', 'updated_at'],
  'Log'          => ['time', 'user', 'action', 'detail'],
  'OtSchemes'    => ['id', 'name', 'group', 'total_cap', 'cap_method', 'sort',
                     'vc_enabled', 'vc_load', 'vc_min', 'vc_max', 'vc_rate', 'vs_enabled', 'vs_load', 'vs_min', 'vs_max', 'vs_rate',
                     'bd_enabled', 'bd_load', 'bd_min', 'bd_max', 'bd_rate', 'note'],
  'OtPerHead'    => ['id', 'track', 'course_kind', 'subject_group', 'below_students', 'rate_per_head'],
  'OtRounds'     => ['id', 'semester_id', 'name', 'start_date', 'end_date'],
  'OtBlocked'    => ['id', 'name', 'round_id', 'day', 'start_date', 'end_date', 'period_from', 'period_to'],
  'OtAbsences'   => ['id', 'round_id', 'teacher_id', 'date', 'period_from', 'period_to', 'type', 'note', 'created_by'],
  'OtSubs'       => ['id', 'round_id', 'type', 'reason', 'original_teacher_id', 'teacher_id', 'session_id', 'group_id', 'course_id', 'original_date',
                     'date', 'start', 'length', 'report_due', 'report_done', 'report_link', 'status', 'note', 'created_by', 'created_at'],
  'OtClaims'     => ['id', 'round_id', 'teacher_id', 'scheme_id', 'weeks', 'status', 'hours', 'amount', 'summary', 'updated_by', 'updated_at', 'locked_by', 'locked_at'],
  'OtSubmissions'=> ['id', 'round_id', 'department_id', 'status', 'note', 'snapshot', 'created_by', 'created_at'],
  // ---- รายงานการสอนออนไลน์ ----
  'TeachReports' => ['id', 'semester_id', 'teacher_id', 'session_id', 'group_id', 'course_id', 'date', 'start', 'length', 'kind', 'topic', 'activity',
                     'students_present', 'students_absent', 'problems', 'attachment_link', 'status', 'created_at', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
                     'group_ids', 'media', 'evaluation', 'assignment', 'attendance', 'images', 'memo_no', 'memo_date', 'memo_reason'],
  // ---- นิเทศออนไลน์: วิชาที่สอนในสถานประกอบการ (unit_key = J:รหัสเรียนร่วม หรือ P:รหัสแผน) ----
  'SupervisionVisits' => ['id', 'semester_id', 'unit_key', 'company_name', 'visit_date', 'visit_type', 'meeting_link', 'topic', 'findings', 'suggestions', 'score', 'evidence_link',
                     'next_visit_date', 'status', 'created_by', 'created_at'],
];

// ตารางที่แก้ไขผ่านหน้าจัดการข้อมูลได้ และสิทธิ์ที่ต้องมี
const EDITABLE = [
  'Semesters' => 'admin', 'Departments' => 'admin', 'Majors' => 'admin', 'Teachers' => 'admin',
  'Buildings' => 'admin', 'Rooms' => 'admin', 'Courses' => 'admin', 'Groups' => 'admin',
  'Plans' => 'dept', 'LockedSlots' => 'admin', 'Windows' => 'admin', 'PlcSlots' => 'dept', 'ColorGroups' => 'admin',
  'OtSchemes' => 'admin', 'OtPerHead' => 'admin', 'OtRounds' => 'admin', 'OtBlocked' => 'admin', 'OtAbsences' => 'dept',
];

const DEFAULT_SETTINGS = [
  'college_name' => 'วิทยาลัยการอาชีพโพนทอง',
  'college_name_en' => 'Phon Thong Industrial and Community Education College',
  'periods_per_day' => '12', 'period_start' => '08:00', 'period_minutes' => '60', 'lunch_period' => '5',
  'days' => '0,1,2,3,4', // 0=จันทร์ ... 6=อาทิตย์
  'signer_curriculum' => '', 'signer_deputy' => '', 'signer_director' => '', 'default_semester_id' => '',
  'signer_finance' => '', 'signer_admin_deputy' => '', 'ot_max_cap' => '12',
  'ot_contract_caps' => '0-3:6,4-6:7,7-9:8,10-99:8', 'ot_makeup_report_days' => '7', 'public_enabled' => 'true',
  'color_default' => '#cfe2f3', 'color_workplace' => '#fb8ca6',
];

const DAY_NAMES = ['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'];

// ======================= ค่าตั้งค่า (.env) =======================
function env_file(bool $reload = false): array {
  static $vars = null;
  if ($vars !== null && !$reload) return $vars;
  $vars = [];
  $file = APP_ROOT . '/.env';
  if (!is_file($file)) return $vars;
  foreach (preg_split('/\r?\n/', (string)file_get_contents($file)) as $line) {
    if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) continue;
    $v = $m[2];
    if (preg_match('/^".*"$/', $v) || preg_match("/^'.*'$/", $v)) $v = substr($v, 1, -1);
    $vars[$m[1]] = $v;
  }
  return $vars;
}

/** ค่าจากตัวแปรระบบก่อน แล้วค่อยจากไฟล์ .env */
function cfg(string $k, string $default = ''): string {
  $v = getenv($k);
  if ($v !== false && $v !== '') return $v;
  $f = env_file();
  return (isset($f[$k]) && $f[$k] !== '') ? $f[$k] : $default;
}

// ======================= ฐานข้อมูล =======================
function db_dsn(bool $withName = true): string {
  $dsn = 'mysql:host=' . cfg('DB_HOST', '127.0.0.1') . ';port=' . cfg('DB_PORT', '3306') . ';charset=utf8mb4';
  return $withName ? $dsn . ';dbname=' . db_name() : $dsn;
}
function db_name(): string {
  $n = cfg('DB_NAME', 'tabhub');
  if (!preg_match('/^[A-Za-z0-9_]+$/', $n)) throw new Exception('DB_NAME ใช้ได้เฉพาะ A-Z a-z 0-9 _');
  return $n;
}
function db_connect(bool $withName = true): PDO {
  $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => true];
  return new PDO(db_dsn($withName), cfg('DB_USER', 'root'), cfg('DB_PASSWORD', ''), $opt);
}
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = db_connect();
    // อ่านค่าล่าสุดที่บันทึกแล้วเสมอ หลังได้ล็อกการเขียน
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
  }
  return $pdo;
}
function q(string $n): string { return '`' . str_replace('`', '', $n) . '`'; }

function col_type(string $c): string { return preg_match('/^(id|key|[a-z_]*_id)$/', $c) ? 'VARCHAR(64) NULL' : 'TEXT NULL'; }
function pk_of(array $cols): ?string { return in_array('id', $cols, true) ? 'id' : (in_array('key', $cols, true) ? 'key' : null); }

function create_table_sql(string $name, array $cols): string {
  $lines = ['  `_seq` BIGINT NOT NULL AUTO_INCREMENT'];
  foreach ($cols as $c) $lines[] = '  ' . q($c) . ' ' . col_type($c);
  $lines[] = '  PRIMARY KEY (`_seq`)';
  foreach (array_intersect($cols, ['id', 'semester_id', 'plan_id', 'group_id', 'round_id', 'teacher_id', 'department_id', 'key']) as $c) {
    $lines[] = '  KEY ' . q('ix_' . $c) . ' (' . q($c) . ')';
  }
  return 'CREATE TABLE IF NOT EXISTS ' . q($name) . " (\n" . implode(",\n", $lines) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
}
const KV_SQL = "CREATE TABLE IF NOT EXISTS `_kv` (\n  `k` VARCHAR(191) NOT NULL,\n  `v` MEDIUMTEXT NULL,\n  `exp` BIGINT NULL,\n  PRIMARY KEY (`k`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

function schema_sql(): string {
  $parts = [];
  foreach (SCHEMA as $n => $cols) $parts[] = create_table_sql($n, $cols);
  $parts[] = KV_SQL;
  return implode("\n\n", $parts);
}

/** สร้างฐานข้อมูลและตาราง / เพิ่มคอลัมน์ที่ยังไม่มี */
function db_migrate(): void {
  try { db_connect(false)->exec('CREATE DATABASE IF NOT EXISTS ' . q(db_name()) . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); }
  catch (PDOException $e) { /* ผู้ใช้บนโฮสติ้งมักสร้างฐานข้อมูลเองไม่ได้ ข้ามได้ถ้ามีอยู่แล้ว */ }
  $pdo = db();
  foreach (SCHEMA as $name => $cols) {
    $pdo->exec(create_table_sql($name, $cols));
    $have = array_column($pdo->query('SHOW COLUMNS FROM ' . q($name))->fetchAll(), 'Field');
    foreach ($cols as $c) if (!in_array($c, $have, true)) $pdo->exec('ALTER TABLE ' . q($name) . ' ADD COLUMN ' . q($c) . ' ' . col_type($c));
  }
  $pdo->exec(KV_SQL);
}

/* อัปเดตตารางอัตโนมัติเมื่อโครงสร้างในโค้ดเปลี่ยน โดยไม่ต้องรันคำสั่ง และไม่ลบคอลัมน์หรือข้อมูลเดิม */
function schema_hash_file(): string { return APP_ROOT . '/data/schema.hash'; }
function schema_mark_synced(): void {
  if (!is_dir(APP_ROOT . '/data')) @mkdir(APP_ROOT . '/data', 0775, true);
  @file_put_contents(schema_hash_file(), md5(json_encode(SCHEMA)));
}
function schema_sync(): void {
  if (@file_get_contents(schema_hash_file()) === md5(json_encode(SCHEMA))) return;
  try { db_migrate(); schema_mark_synced(); }
  catch (Throwable $e) { error_log('[schema sync] ' . $e->getMessage()); } // ลองใหม่ในคำขอถัดไป
}

// ======================= อ่าน/เขียนตาราง =======================
final class Tbl { public static array $cache = []; public static bool $locked = false; }

function assert_table(string $name): array {
  if (!isset(SCHEMA[$name])) throw new Exception('ไม่พบตาราง ' . $name);
  return SCHEMA[$name];
}

function readTable(string $name): array {
  if (isset(Tbl::$cache[$name])) return Tbl::$cache[$name];
  $cols = assert_table($name);
  $rows = db()->query('SELECT ' . implode(',', array_map('q', $cols)) . ' FROM ' . q($name) . ' ORDER BY `_seq`')->fetchAll();
  foreach ($rows as &$r) {
    foreach ($r as $k => $v) {
      if ($v === null) $r[$k] = '';
      elseif ($v === 'TRUE' || $v === 'FALSE') $r[$k] = strtolower($v);
      else $r[$k] = (string)$v;
    }
  }
  unset($r);
  return Tbl::$cache[$name] = $rows;
}

function cell($v): string {
  if ($v === null) return '';
  if ($v === true) return 'true';
  if ($v === false) return 'false';
  if ($v instanceof DateTimeInterface) return $v->format('Y-m-d H:i');
  if (is_array($v) || is_object($v)) return (string)json_encode($v, JSON_UNESCAPED_UNICODE);
  return (string)$v;
}

function norm_rows(string $name, array $rows): array {
  $cols = assert_table($name);
  $out = [];
  foreach ($rows as $o) {
    $o = (array)$o;
    $r = [];
    foreach ($cols as $c) $r[$c] = cell($o[$c] ?? null);
    $out[] = $r;
  }
  return $out;
}

function insert_rows(string $name, array $rows): void {
  if (!$rows) return;
  $cols = SCHEMA[$name];
  $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
  foreach (array_chunk($rows, 400) as $chunk) {
    $vals = [];
    foreach ($chunk as $r) foreach ($cols as $c) $vals[] = $r[$c];
    db()->prepare('INSERT INTO ' . q($name) . ' (' . implode(',', array_map('q', $cols)) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $ph)))->execute($vals);
  }
}

/** บันทึกทั้งตาราง โดยเขียนเฉพาะแถวที่เปลี่ยนจริง */
function writeTable(string $name, array $rows): void {
  $cols = assert_table($name);
  $before = readTable($name);
  $after = norm_rows($name, $rows);
  $pk = pk_of($cols);
  $uniq = function (array $list) use ($pk): bool {
    $seen = [];
    foreach ($list as $r) { $k = $r[$pk]; if ($k === '' || isset($seen[$k])) return false; $seen[$k] = 1; }
    return true;
  };
  if ($pk === null || !$uniq($before) || !$uniq($after)) {
    db()->exec('DELETE FROM ' . q($name));
    insert_rows($name, $after);
    unset(Tbl::$cache[$name]);
    return;
  }
  $old = [];
  foreach ($before as $r) $old[(string)$r[$pk]] = $r;
  $now = [];
  foreach ($after as $r) $now[(string)$r[$pk]] = 1;
  $del = [];
  foreach ($old as $k => $_) if (!isset($now[(string)$k])) $del[] = (string)$k;
  foreach (array_chunk($del, 500) as $part) {
    db()->prepare('DELETE FROM ' . q($name) . ' WHERE ' . q($pk) . ' IN (' . implode(',', array_fill(0, count($part), '?')) . ')')->execute($part);
  }
  $ins = [];
  $set = array_values(array_filter($cols, fn($c) => $c !== $pk));
  $upd = null;
  foreach ($after as $r) {
    $k = (string)$r[$pk];
    if (!isset($old[$k])) { $ins[] = $r; continue; }
    if ($old[$k] != $r) {
      $upd ??= db()->prepare('UPDATE ' . q($name) . ' SET ' . implode(', ', array_map(fn($c) => q($c) . ' = ?', $set)) . ' WHERE ' . q($pk) . ' = ?');
      $vals = [];
      foreach ($set as $c) $vals[] = $r[$c];
      $vals[] = $k;
      $upd->execute($vals);
    }
  }
  insert_rows($name, $ins);
  unset(Tbl::$cache[$name]);
}

function appendRows(string $name, array $rows): void {
  if (!$rows) return;
  insert_rows($name, norm_rows($name, $rows));
  unset(Tbl::$cache[$name]);
}

/** แทนที่ข้อมูลทั้งตาราง (ใช้ตอนนำเข้า) */
function replaceAllTables(array $by): void {
  db()->beginTransaction();
  try {
    foreach ($by as $name => $rows) {
      db()->exec('DELETE FROM ' . q($name));
      insert_rows($name, norm_rows($name, $rows));
    }
    db()->commit();
  } catch (Throwable $e) { db()->rollBack(); throw $e; }
  Tbl::$cache = [];
}

function readSettings(): array {
  $o = [];
  foreach (readTable('Settings') as $r) $o[$r['key']] = (string)$r['value'];
  return $o;
}

/** เลขคาบที่แสดงให้ผู้ใช้เห็น: คาบพักไม่นับเลข ฐานข้อมูลยังเก็บลำดับช่องเดิม เช่น ช่อง 6 คือคาบ 5 */
function period_no($p): int {
  static $lunch = null;
  if ($lunch === null) $lunch = array_map('intval', array_filter(array_map('trim', explode(',', readSettings()['lunch_period'] ?? ''))));
  $n = 0;
  for ($i = 1; $i <= (int)$p; $i++) if (!in_array($i, $lunch, true)) $n++;
  return $n;
}

function saveSetting(string $k, $v): void {
  $rows = readTable('Settings');
  $hit = false;
  foreach ($rows as $i => $x) if ($x['key'] === $k && !$hit) { $rows[$i]['value'] = $v; $hit = true; }
  if (!$hit) $rows[] = ['key' => $k, 'value' => $v];
  writeTable('Settings', $rows);
}

// ======================= ที่เก็บค่าชั่วคราว (session, ตัวนับ) =======================
function ms_now(): int { return (int)floor(microtime(true) * 1000); }

function kvGet(string $k): ?string {
  $st = db()->prepare('SELECT v, exp FROM `_kv` WHERE k = ?');
  $st->execute([$k]);
  $r = $st->fetch();
  if (!$r) return null;
  if ($r['exp'] && (int)$r['exp'] < ms_now()) return null;
  return $r['v'];
}
function kvPut(string $k, string $v, int $ttlSec = 0): void {
  db()->prepare('REPLACE INTO `_kv` (k, v, exp) VALUES (?, ?, ?)')->execute([$k, $v, $ttlSec ? ms_now() + $ttlSec * 1000 : null]);
}
function kvDel(string $k): void { db()->prepare('DELETE FROM `_kv` WHERE k = ?')->execute([$k]); }
function kvPurgeExpired(): void { db()->prepare('DELETE FROM `_kv` WHERE exp IS NOT NULL AND exp > 0 AND exp < ?')->execute([ms_now()]); }

function getProperty(string $k): ?string {
  $v = cfg($k);
  return $v !== '' ? $v : kvGet('prop:' . $k);
}

// ======================= ล็อกการเขียน =======================
/** ถือล็อกไว้จนจบคำขอ เพื่อให้ปล่อยหลังบันทึก transaction แล้ว */
function with_lock(callable $fn) {
  if (!Tbl::$locked) {
    $st = db()->prepare('SELECT GET_LOCK(?, 20) g');
    $st->execute(['tabhub_write_' . db_name()]);
    if ((int)$st->fetch()['g'] !== 1) throw new Exception('ระบบกำลังบันทึกข้อมูลของผู้ใช้อื่น กรุณาลองใหม่อีกครั้ง');
    Tbl::$locked = true;
    Tbl::$cache = []; // อ่านข้อมูลล่าสุดหลังได้ล็อก
  }
  return $fn();
}
function release_lock(): void {
  if (!Tbl::$locked) return;
  Tbl::$locked = false;
  $st = db()->prepare('SELECT RELEASE_LOCK(?)');
  $st->execute(['tabhub_write_' . db_name()]);
  $st->closeCursor();
}

// ======================= ตัวช่วยทั่วไป =======================
function new_id(): string { return 'i' . substr(bin2hex(random_bytes(8)), 0, 11); }
function uuid(): string {
  $h = bin2hex(random_bytes(16));
  return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
}
function now_dt(): DateTimeImmutable { return new DateTimeImmutable('now'); }

/** แปลงเป็นตัวเลขแบบเดียวกับ Number() ของ JavaScript (ค่าที่ไม่ใช่ตัวเลข = 0) */
function num($v) {
  if ($v === true) return 1;
  if ($v === null || $v === false || is_array($v) || is_object($v)) return 0;
  if (is_string($v)) { $v = trim($v); if ($v === '' || !is_numeric($v)) return 0; }
  $f = (float)$v;
  return ($f == floor($f) && abs($f) < 9e15) ? (int)$f : $f;
}

/** ค่าที่ถือว่า "มีค่า" แบบเดียวกับ JavaScript */
function js_true($v): bool {
  return !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);
}

function str($v): string { return is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? '' : (string)$v); }

function log_action($user, string $action, $detail): void {
  try {
    $name = is_array($user) ? ($user['name'] ?? '-') : '-';
    appendRows('Log', [['time' => now_dt(), 'user' => $name, 'action' => $action, 'detail' => is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)]]);
  } catch (Throwable $e) { /* การบันทึกประวัติล้มเหลวไม่ควรทำให้งานหลักล้ม */ }
}

function idx_table(string $name): array {
  $m = [];
  foreach (readTable($name) as $r) $m[$r['id']] = $r;
  return $m;
}

function find_row(array $rows, $id, string $col = 'id'): ?int {
  foreach ($rows as $i => $r) if ((string)$r[$col] === (string)$id) return $i;
  return null;
}
