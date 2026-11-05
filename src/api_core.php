<?php
/** API ผู้ใช้ สิทธิ์ และข้อมูลพื้นฐาน */

// ======================= รหัสผ่าน =======================
/** รหัสแบบเดิมจากรุ่น Google Sheets คือ SHA-256 ของ salt|รหัส ระบบเปลี่ยนเป็นแบบใหม่ให้ตอนล็อกอินสำเร็จ */
function hash_password(string $pw): string { return password_hash($pw, PASSWORD_DEFAULT); }

function verify_password(string $pw, $salt, $stored): array {
  static $dummy = null;
  $stored = (string)$stored;
  if (preg_match('/^[0-9a-f]{64}$/', $stored)) {
    $dummy ??= password_hash('timing-pad', PASSWORD_DEFAULT);
    password_verify($pw, $dummy); // ใช้เวลาเท่ากัน เดาไม่ได้ว่ามีผู้ใช้นี้จริงหรือไม่
    $ok = hash_equals($stored, hash('sha256', (string)$salt . '|' . $pw));
    return ['ok' => $ok, 'legacy' => $ok];
  }
  if ($stored === '' || !str_starts_with($stored, '$')) {
    $dummy ??= password_hash('timing-pad', PASSWORD_DEFAULT);
    password_verify($pw, $dummy);
    return ['ok' => false, 'legacy' => false];
  }
  return ['ok' => password_verify($pw, $stored), 'legacy' => false];
}

function pw_text($pw): string { return ($pw === null || is_array($pw)) ? '' : (is_bool($pw) ? ($pw ? 'true' : 'false') : (string)$pw); }

// ======================= ผู้ใช้และสิทธิ์ =======================
function auth_user($token, ?string $need = null): array {
  $raw = (is_string($token) && $token !== '') ? kvGet('cache:tok_' . $token) : null;
  if (!$raw) throw new Exception('SESSION_EXPIRED');
  $u = json_decode($raw, true);
  kvPut('cache:tok_' . $token, $raw, 21600); // ต่ออายุ
  $rank = ['teacher' => 1, 'dept' => 2, 'admin' => 3];
  if ($need && ($rank[$u['role'] ?? ''] ?? 0) < ($rank[$need] ?? 99)) throw new Exception('ไม่มีสิทธิ์ทำรายการนี้');
  return $u;
}

function api_login($username = '', $password = '') {
  $username = strtolower(trim(str($username ?? '')));
  $users = readTable('Users');
  $idx = null;
  foreach ($users as $i => $x) {
    if (strtolower((string)$x['username']) === $username && (string)$x['active'] !== 'false') { $idx = $i; break; }
  }
  $u = $idx !== null ? $users[$idx] : null;
  $r = verify_password(pw_text($password), $u['salt'] ?? '', $u['pass_hash'] ?? '');
  if (!$u || !$r['ok']) throw new Exception('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
  if ($r['legacy']) { // เปลี่ยนรหัสแบบเดิมเป็นแบบใหม่
    $users[$idx]['salt'] = uuid();
    $users[$idx]['pass_hash'] = hash_password(pw_text($password));
    writeTable('Users', $users);
  }
  $token = uuid();
  $info = ['id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'department_id' => $u['department_id'], 'teacher_id' => $u['teacher_id']];
  kvPut('cache:tok_' . $token, json_encode($info, JSON_UNESCAPED_UNICODE), 21600);
  log_action($info, 'login', '');
  return ['token' => $token, 'user' => $info];
}

function api_logout($token = '') { if (is_string($token)) kvDel('cache:tok_' . $token); return true; }

function api_getCollegeName() { return ['college_name' => readSettings()['college_name'] ?? '']; }
function api_getWebAppUrl() { return $GLOBALS['REQ_URL'] ?? ''; }

function api_changePassword($token = '', $oldPw = '', $newPw = '') {
  $me = auth_user($token);
  if (mb_strlen(pw_text($newPw)) < 6) throw new Exception('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
  return with_lock(function () use ($me, $oldPw, $newPw) {
    $users = readTable('Users');
    $i = find_row($users, $me['id']);
    if ($i === null || !verify_password(pw_text($oldPw), $users[$i]['salt'], $users[$i]['pass_hash'])['ok']) throw new Exception('รหัสผ่านเดิมไม่ถูกต้อง');
    $users[$i]['salt'] = uuid(); $users[$i]['pass_hash'] = hash_password(pw_text($newPw));
    writeTable('Users', $users);
    return true;
  });
}

/** รูปโปรไฟล์เก็บในแถวผู้ใช้ จึงต้องเล็กกว่าที่คอลัมน์ TEXT รับได้ (64 KB หลังแปลงเป็น base64) */
const AVATAR_MAX_BYTES = 45000;

function user_avatar($id): string {
  $users = readTable('Users');
  $i = find_row($users, str($id));
  $v = $i === null ? '' : (string)($users[$i]['avatar'] ?? '');
  // ไฟล์สำรองที่อัปโหลดอาจมีค่าแปลกปลอม จึงส่งออกเฉพาะรูปตามรูปแบบที่ระบบสร้าง
  return preg_match('#^data:image/(jpeg|png);base64,[A-Za-z0-9+/=]+$#', $v) ? $v : '';
}

/** โปรไฟล์ของผู้ใช้เอง ชื่อผู้ใช้และสิทธิ์ให้ผู้ดูแลระบบเป็นผู้เปลี่ยน */
function api_profileGet($token = '') {
  $me = auth_user($token);
  $users = readTable('Users');
  $i = find_row($users, $me['id']);
  if ($i === null) throw new Exception('ไม่พบบัญชีผู้ใช้');
  return ['username' => $users[$i]['username'], 'name' => $users[$i]['name'], 'email' => $users[$i]['email'], 'avatar' => user_avatar($me['id'])];
}

/** $avatar: null = คงรูปเดิม, '' = ลบรูป, นอกนั้นเป็น base64 ของไฟล์รูป */
function api_profileSave($token = '', $name = '', $email = '', $avatar = null) {
  $me = auth_user($token);
  $name = trim(str($name)); $email = trim(str($email));
  if ($name === '') throw new Exception('กรุณากรอกชื่อ-นามสกุล');
  if (mb_strlen($name) > 120) throw new Exception('ชื่อยาวเกินไป');
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
  $uri = null;
  if ($avatar !== null) {
    $uri = '';
    if (str($avatar) !== '') {
      $bytes = base64_decode(str($avatar), true);
      if ($bytes === false) throw new Exception('อ่านไฟล์รูปไม่สำเร็จ');
      if (strlen($bytes) > AVATAR_MAX_BYTES) throw new Exception('รูปโปรไฟล์มีขนาดใหญ่เกินไป');
      $ext = report_image_ext($bytes);
      if ($ext === '') throw new Exception('รองรับเฉพาะรูป JPG และ PNG');
      $uri = 'data:image/' . ($ext === 'jpg' ? 'jpeg' : 'png') . ';base64,' . base64_encode($bytes);
    }
  }
  return with_lock(function () use ($me, $token, $name, $email, $uri) {
    $users = readTable('Users');
    $i = find_row($users, $me['id']);
    if ($i === null) throw new Exception('ไม่พบบัญชีผู้ใช้');
    $users[$i]['name'] = $name; $users[$i]['email'] = $email;
    if ($uri !== null) $users[$i]['avatar'] = $uri;
    writeTable('Users', $users);
    // ชื่อบนหน้าเว็บและในประวัติการแก้ไขอ่านจากข้อมูลการเข้าระบบ จึงต้องอัปเดตด้วย
    $me['name'] = $name;
    kvPut('cache:tok_' . $token, json_encode($me, JSON_UNESCAPED_UNICODE), 21600);
    log_action($me, 'แก้ไขโปรไฟล์', '');
    return $me + ['avatar' => user_avatar($me['id'])];
  });
}

function api_listUsers($token = '') {
  auth_user($token, 'admin');
  return array_map(fn($u) => [
    'id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'department_id' => $u['department_id'],
    'teacher_id' => $u['teacher_id'], 'email' => $u['email'], 'active' => $u['active'], 'has_password' => $u['pass_hash'] !== '',
  ], readTable('Users'));
}

function api_saveUser($token = '', $data = []) {
  $me = auth_user($token, 'admin');
  $data = (array)$data;
  return with_lock(function () use ($me, $data) {
    $users = readTable('Users');
    $uname = strtolower(trim(str($data['username'] ?? '')));
    if ($uname === '') throw new Exception('กรุณาระบุชื่อผู้ใช้');
    $id = $data['id'] ?? '';
    foreach ($users as $x) if (strtolower((string)$x['username']) === $uname && $x['id'] !== $id) throw new Exception('ชื่อผู้ใช้นี้มีอยู่แล้ว');
    $i = js_true($id) ? find_row($users, $id) : null;
    if ($i === null) {
      if (!js_true($data['password'] ?? '')) throw new Exception('กรุณาตั้งรหัสผ่าน');
      $users[] = ['id' => new_id()];
      $i = count($users) - 1;
    }
    $u = $users[$i];
    $u['username'] = $uname; $u['name'] = $data['name'] ?? '';
    $u['role'] = js_true($data['role'] ?? '') ? $data['role'] : 'teacher';
    $u['department_id'] = $data['department_id'] ?? ''; $u['teacher_id'] = $data['teacher_id'] ?? '';
    $u['email'] = js_true($data['email'] ?? '') ? $data['email'] : ($u['email'] ?? '');
    $active = $data['active'] ?? true;
    $u['active'] = !($active === false || $active === 'false');
    if (js_true($data['password'] ?? '')) {
      if (mb_strlen(pw_text($data['password'])) < 6) throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
      $u['salt'] = uuid(); $u['pass_hash'] = hash_password(pw_text($data['password']));
    }
    $users[$i] = $u;
    writeTable('Users', $users);
    log_action($me, 'saveUser', $uname);
    return true;
  });
}

function api_deleteUser($token = '', $id = '') {
  $me = auth_user($token, 'admin');
  if ($id === $me['id']) throw new Exception('ลบบัญชีตัวเองไม่ได้');
  return with_lock(function () use ($me, $id) {
    writeTable('Users', array_values(array_filter(readTable('Users'), fn($x) => $x['id'] !== $id)));
    log_action($me, 'deleteUser', $id);
    return true;
  });
}

/** สร้างบัญชีครูทุกคนอัตโนมัติ (ชื่อผู้ใช้ = รหัสครูหรืออีเมล) */
function api_bulkCreateTeacherUsers($token = '', $defaultPassword = '') {
  $me = auth_user($token, 'admin');
  if (mb_strlen(pw_text($defaultPassword)) < 6) throw new Exception('รหัสผ่านเริ่มต้นต้องมีอย่างน้อย 6 ตัวอักษร');
  return with_lock(function () use ($me, $defaultPassword) {
    $users = readTable('Users');
    $linked = []; $taken = [];
    foreach ($users as $u) { if ($u['teacher_id'] !== '') $linked[$u['teacher_id']] = 1; $taken[strtolower((string)$u['username'])] = 1; }
    $n = 0;
    foreach (readTable('Teachers') as $t) {
      if (isset($linked[$t['id']])) continue;
      $uname = strtolower($t['email'] !== '' ? explode('@', $t['email'])[0] : ($t['code'] !== '' ? $t['code'] : 't' . $t['id']));
      if (isset($taken[$uname])) $uname .= '_' . substr((string)$t['id'], 0, 4);
      $users[] = ['id' => new_id(), 'username' => $uname, 'name' => $t['name'], 'role' => 'teacher', 'department_id' => $t['department_id'], 'teacher_id' => $t['id'], 'salt' => uuid(), 'pass_hash' => hash_password(pw_text($defaultPassword)), 'active' => true];
      $taken[$uname] = 1; $n++;
    }
    writeTable('Users', $users);
    log_action($me, 'bulkCreateTeacherUsers', $n . ' บัญชี');
    return $n;
  });
}

// ======================= สิทธิ์อ่านข้อมูล =======================
const TEACHER_PRIVATE = ['email', 'education', 'special_duty', 'award_note'];
const TEACHER_OT_FIELDS = ['employment', 'service_start', 'award_bonus', 'ot_scheme_ids'];

/** ตัดข้อมูลส่วนตัวของครูตามสิทธิ์ผู้ดู: แถวของตัวเองเห็นครบ, แผนกเห็นข้อมูลการเบิกเฉพาะครูในแผนก */
function teachers_for(array $me, array $rows): array {
  if ($me['role'] === 'admin') return $rows;
  return array_map(function ($t) use ($me) {
    if ((string)$t['id'] === (string)($me['teacher_id'] ?? '')) return $t;
    foreach (TEACHER_PRIVATE as $f) $t[$f] = '';
    if (!($me['role'] === 'dept' && (string)$t['department_id'] === (string)($me['department_id'] ?? ''))) {
      foreach (TEACHER_OT_FIELDS as $f) $t[$f] = '';
      if ($me['role'] === 'teacher') $t['ot_enabled'] = '';
    }
    return $t;
  }, $rows);
}

/** แถวที่ผู้ใช้มีสิทธิ์เห็น สำหรับตารางข้อมูลการเบิกและประวัติการแก้ไข */
function scope_rows(array $me, string $name, array $rows): array {
  if ($me['role'] === 'admin') return $rows;
  if ($name === 'Log') { if ($me['role'] !== 'dept') throw new Exception('ไม่อนุญาต'); return $rows; }
  if ($name === 'OtSubmissions') {
    if ($me['role'] !== 'dept') throw new Exception('ไม่อนุญาต');
    return array_values(array_filter($rows, fn($r) => (string)$r['department_id'] === (string)$me['department_id']));
  }
  if (!in_array($name, ['OtClaims', 'OtAbsences', 'OtSubs'], true)) return $rows;
  $teachers = idx_table('Teachers');
  $mine = function ($id) use ($me, $teachers) {
    if ($me['role'] === 'dept') return isset($teachers[$id]) && (string)$teachers[$id]['department_id'] === (string)$me['department_id'];
    return (string)$id === (string)($me['teacher_id'] ?? '');
  };
  return array_values(array_filter($rows, fn($r) => ($r['teacher_id'] ?? '') === '*' || $mine($r['teacher_id'] ?? '') || ($name === 'OtSubs' && $mine($r['original_teacher_id']))));
}

// ======================= ข้อมูลพื้นฐาน =======================
function api_bootstrap($token = '', $semesterId = '') {
  $me = auth_user($token);
  $s = readSettings();
  $sems = readTable('Semesters');
  $known = false;
  foreach ($sems as $x) if (js_true($semesterId) && (string)$x['id'] === (string)$semesterId) $known = true;
  $sem = $known ? $semesterId : (js_true($s['default_semester_id'] ?? '') ? $s['default_semester_id'] : ($sems ? $sems[count($sems) - 1]['id'] : ''));
  $data = [];
  foreach (['Semesters', 'Departments', 'Majors', 'Teachers', 'Buildings', 'Rooms', 'Courses', 'Groups', 'LockedSlots', 'PlcSlots', 'ColorGroups', 'Windows', 'OtSchemes', 'OtPerHead', 'OtRounds', 'OtBlocked'] as $t) $data[$t] = readTable($t);
  $data['Teachers'] = teachers_for($me, $data['Teachers']);
  return [
    'sem' => $sem,
    'plans' => array_values(array_filter(readTable('Plans'), fn($p) => (string)$p['semester_id'] === (string)$sem)),
    'sessions' => sem_sessions($sem),
    'me' => $me + ['avatar' => user_avatar($me['id'])], 'settings' => $s, 'days' => DAY_NAMES, 'url' => $GLOBALS['REQ_URL'] ?? '',
    'data' => $data,
  ];
}

function api_listTable($token = '', $name = '', $filter = null) {
  $me = auth_user($token);
  if (!is_string($name) || !isset(SCHEMA[$name]) || $name === 'Users') throw new Exception('ไม่อนุญาต');
  $rows = scope_rows($me, $name, readTable($name));
  if (is_array($filter) && $filter) {
    $rows = array_values(array_filter($rows, function ($r) use ($filter) {
      foreach ($filter as $k => $v) if (str($r[$k] ?? 'undefined') !== str($v)) return false;
      return true;
    }));
  }
  return $name === 'Teachers' ? teachers_for($me, $rows) : $rows;
}

function api_saveRow($token = '', $name = '', $row = []) {
  if (!is_string($name)) throw new Exception('แก้ไขตารางนี้ไม่ได้');
  $me = auth_user($token, EDITABLE[$name] ?? 'admin');
  if (!isset(EDITABLE[$name])) throw new Exception('แก้ไขตารางนี้ไม่ได้');
  $row = (array)$row;
  if ($name === 'Semesters' && js_true($row['start_date'] ?? '') && js_true($row['end_date'] ?? '') && str($row['end_date']) < str($row['start_date'])) throw new Exception('วันปิดภาคเรียนต้องไม่ก่อนวันเปิดภาคเรียน');
  return with_lock(function () use ($me, $name, $row) {
    $rows = readTable($name);
    $id = $row['id'] ?? '';
    $i = js_true($id) ? find_row($rows, $id) : null;
    // ตรวจสิทธิ์จากแถวเดิมด้วย ไม่ใช่แค่ค่าที่ส่งมา
    if ($i !== null && $name === 'Plans' && $me['role'] === 'dept') assert_dept_can_edit($me, $rows[$i]['group_id'], $rows[$i]['semester_id']);
    if ($i !== null && $name === 'OtAbsences') { assert_ot_teacher($me, $rows[$i]['teacher_id']); assert_claim_open($rows[$i]['round_id'], $rows[$i]['teacher_id']); }
    if ($name === 'Plans' && $me['role'] === 'dept') assert_dept_can_edit($me, $row['group_id'] ?? '', $row['semester_id'] ?? '');
    if ($name === 'OtAbsences') {
      assert_ot_teacher($me, $row['teacher_id'] ?? '');
      assert_claim_open($row['round_id'] ?? '', $row['teacher_id'] ?? '');
      $row['created_by'] = $me['name'];
    }
    if ($name === 'PlcSlots') {
      if ($i !== null) assert_plc_can_edit($me, $rows[$i]);
      if ($me['role'] === 'dept') $row['department_id'] = $me['department_id']; // ตัวแทนแผนกกำหนดได้เฉพาะแผนกตัวเอง
      assert_plc_can_edit($me, $row);
      if (!js_true($row['department_id'] ?? '') && !js_true($row['teacher_id'] ?? '')) throw new Exception('กรุณาเลือกแผนกหรือครู');
      if (num($row['start'] ?? 0) < 1) throw new Exception('กรุณาเลือกคาบเริ่ม');
      if ((string)($row['day'] ?? '') === '') throw new Exception('กรุณาเลือกวัน');
    }
    if ($i === null) { $rows[] = ['id' => new_id()]; $i = count($rows) - 1; }
    foreach (SCHEMA[$name] as $h) if ($h !== 'id' && array_key_exists($h, $row)) $rows[$i][$h] = $row[$h];
    $cur = $rows[$i];
    if ($name === 'Semesters' && str($cur['is_default'] ?? '') === 'true') {
      foreach ($rows as $k => $r) if ($k !== $i) $rows[$k]['is_default'] = false;
      saveSetting('default_semester_id', $cur['id']);
    }
    writeTable($name, $rows);
    log_action($me, 'save ' . $name, $cur['id']);
    return $cur;
  });
}

function api_deleteRow($token = '', $name = '', $id = '') {
  if (!is_string($name)) throw new Exception('ลบตารางนี้ไม่ได้');
  $me = auth_user($token, EDITABLE[$name] ?? 'admin');
  if (!isset(EDITABLE[$name])) throw new Exception('ลบตารางนี้ไม่ได้');
  return with_lock(function () use ($me, $name, $id) {
    $rows = readTable($name);
    $i = find_row($rows, str($id));
    if ($i === null) return false;
    $target = $rows[$i];
    if ($name === 'OtAbsences') { assert_ot_teacher($me, $target['teacher_id']); assert_claim_open($target['round_id'], $target['teacher_id']); }
    if ($name === 'PlcSlots') assert_plc_can_edit($me, $target);
    if ($name === 'Plans') {
      if ($me['role'] === 'dept') assert_dept_can_edit($me, $target['group_id'], $target['semester_id']);
      writeTable('Sessions', array_values(array_filter(readTable('Sessions'), fn($s) => (string)$s['plan_id'] !== str($id))));
    }
    writeTable($name, array_values(array_filter($rows, fn($r) => (string)$r['id'] !== str($id))));
    log_action($me, 'delete ' . $name, $id);
    return true;
  });
}

/** นำเข้าหลายแถวจากการวาง (CSV/จาก Excel) */
function api_bulkImport($token = '', $name = '', $rows = [], $mode = '') {
  $me = auth_user($token, 'admin');
  if (!is_string($name) || !isset(EDITABLE[$name])) throw new Exception('นำเข้าตารางนี้ไม่ได้');
  return with_lock(function () use ($me, $name, $rows, $mode) {
    $existing = $mode === 'replace' ? [] : readTable($name);
    foreach ((array)$rows as $r) {
      $r = (array)$r;
      $o = ['id' => js_true($r['id'] ?? '') ? $r['id'] : new_id()];
      foreach (SCHEMA[$name] as $h) if ($h !== 'id') $o[$h] = $r[$h] ?? '';
      $existing[] = $o;
    }
    writeTable($name, $existing);
    log_action($me, 'bulkImport ' . $name, count((array)$rows) . ' แถว');
    return count((array)$rows);
  });
}

function api_saveSettings($token = '', $obj = []) {
  $me = auth_user($token, 'admin');
  $obj = (array)$obj;
  if (array_key_exists('site_url', $obj)) {
    $url = trim(str($obj['site_url']));
    if ($url !== '' && !preg_match('#^https?://[^\s"\'<>]+$#i', $url)) throw new Exception('URL ต้องขึ้นต้นด้วย http:// หรือ https:// และไม่มีช่องว่าง');
    if ($url !== '') $url = rtrim($url, '/') . '/';
    $obj['site_url'] = $url;
  }
  return with_lock(function () use ($me, $obj) {
    foreach ($obj as $k => $v) saveSetting((string)$k, $v);
    log_action($me, 'saveSettings', implode(',', array_keys($obj)));
    return readSettings();
  });
}

/** คัดลอกแผนการเรียนจากภาคเรียนหนึ่งไปอีกภาคเรียน */
function api_copyPlans($token = '', $from = '', $to = '') {
  $me = auth_user($token, 'admin');
  return with_lock(function () use ($me, $from, $to) {
    $add = [];
    foreach (readTable('Plans') as $p) {
      if ((string)$p['semester_id'] !== str($from)) continue;
      $p['id'] = new_id(); $p['semester_id'] = $to; $add[] = $p;
    }
    appendRows('Plans', $add);
    log_action($me, 'copyPlans', str($from) . ' -> ' . str($to) . ' (' . count($add) . ')');
    return count($add);
  });
}

// ======================= คิวจัดตารางของแผนก =======================
/** คาบ PLC: ผู้ดูแลระบบแก้ได้ทุกแผนก ตัวแทนแผนกแก้ได้เฉพาะแผนกและครูในแผนกตัวเอง */
function assert_plc_can_edit(array $me, array $row): void {
  if ($me['role'] === 'admin') return;
  $dept = (string)$me['department_id'];
  if ($me['role'] !== 'dept' || $dept === '' || (string)($row['department_id'] ?? '') !== $dept) throw new Exception('กำหนดคาบ PLC ได้เฉพาะแผนกตัวเอง');
  $tid = (string)($row['teacher_id'] ?? '');
  if ($tid === '') return;
  foreach (readTable('Teachers') as $t) if ((string)$t['id'] === $tid && (string)$t['department_id'] === $dept) return;
  throw new Exception('กำหนดคาบ PLC รายบุคคลได้เฉพาะครูในแผนกตัวเอง');
}

function assert_dept_can_edit(array $me, $groupId, $semesterId): void {
  if ($me['role'] === 'admin') return;
  if ($me['role'] !== 'dept') throw new Exception('ไม่มีสิทธิ์จัดตาราง');
  $g = null;
  foreach (readTable('Groups') as $x) if ((string)$x['id'] === str($groupId)) { $g = $x; break; }
  if (!$g || (string)$g['department_id'] !== (string)$me['department_id']) throw new Exception('จัดได้เฉพาะกลุ่มเรียนของแผนกตัวเอง');
  $wins = array_filter(readTable('Windows'), fn($w) => (string)$w['department_id'] === (string)$me['department_id'] && (string)$w['semester_id'] === str($semesterId));
  if (!$wins) return; // ไม่ได้กำหนดคิว = จัดได้ตลอด
  $today = date('Y-m-d');
  foreach ($wins as $w) {
    if (($w['start_date'] === '' || $today >= $w['start_date']) && ($w['end_date'] === '' || $today <= $w['end_date'])) return;
  }
  throw new Exception('ยังไม่ถึงคิวจัดตารางของแผนก หรือหมดเวลาแล้ว');
}
