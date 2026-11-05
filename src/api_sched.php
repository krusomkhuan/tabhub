<?php
/** API จัดตาราง รายงาน และการส่งออก */

/** แผนที่ไม่ได้ระบุห้อง ใช้ห้องประจำกลุ่ม */
function with_home_room(array $plans): array {
  $g = idx_table('Groups');
  return array_map(function ($p) use ($g) {
    if (js_true($p['room_id'] ?? '') || !js_true($g[$p['group_id']]['room_id'] ?? '')) return $p;
    $p['room_id'] = $g[$p['group_id']]['room_id'];
    return $p;
  }, $plans);
}

function sched_ctx_now(): array {
  return sched_ctx(['settings' => readSettings(), 'locked' => readTable('LockedSlots'), 'plc' => readTable('PlcSlots'), 'teachers' => readTable('Teachers'),
    'rooms' => readTable('Rooms'), 'groups' => readTable('Groups'), 'courses' => readTable('Courses'), 'plans' => readTable('Plans')]);
}

function sem_sessions($semesterId): array {
  return array_values(array_filter(readTable('Sessions'), fn($s) => (string)$s['semester_id'] === str($semesterId)));
}

function sched_describe(array $s, array $ctx): string {
  $g = $ctx['groups'][$s['group_id']] ?? null; $c = $ctx['courses'][$s['course_id']] ?? null;
  return ($c ? $c['code'] . ' ' . $c['name'] : '') . ' · ' . ($g ? $g['code'] : '') . ' · ' . (DAY_NAMES[num($s['day'])] ?? '') . ' คาบ ' . period_no($s['start']) . '-' . period_no(num($s['start']) + num($s['length']) - 1);
}

function api_getSchedule($token = '', $semesterId = '') {
  auth_user($token);
  return [
    'plans' => array_values(array_filter(readTable('Plans'), fn($p) => (string)$p['semester_id'] === str($semesterId))),
    'sessions' => sem_sessions($semesterId),
  ];
}

/** วาง/ย้ายคาบ payload: {id?, plan_id, day, start, length, room_id?, force?} */
function api_placeSession($token = '', $payload = []) {
  $me = auth_user($token, 'dept');
  $payload = (array)$payload;
  return with_lock(function () use ($me, $payload) {
    $allPlans = readTable('Plans');
    $pi = find_row($allPlans, str($payload['plan_id'] ?? ''));
    if ($pi === null) throw new Exception('ไม่พบแผนการเรียน');
    $plan = $allPlans[$pi];
    assert_dept_can_edit($me, $plan['group_id'], $plan['semester_id']);
    $all = readTable('Sessions');
    $sem = array_filter($all, fn($s) => (string)$s['semester_id'] === (string)$plan['semester_id']); // คงลำดับแถวเดิมไว้เป็นคีย์
    $ctx = sched_ctx_now();
    // แผนเรียนร่วม: วางทุกกลุ่มพร้อมกัน
    $plans = js_true($plan['joint_key']) ? array_values(array_filter($allPlans, fn($p) => (string)$p['semester_id'] === (string)$plan['semester_id'] && (string)$p['joint_key'] === (string)$plan['joint_key'])) : [$plan];
    $ei = js_true($payload['id'] ?? '') ? find_row($all, $payload['id']) : null;
    $existing = $ei !== null ? $all[$ei] : null;
    if ($existing && (string)$existing['locked'] === 'true' && $me['role'] !== 'admin') throw new Exception('คาบนี้ถูกล็อก');
    if ($existing && $me['role'] !== 'admin' && sched_is_workplace($existing, $ctx)) throw new Exception('วิชาฝึกงาน/ทวิภาคี ล็อกห้ามขยับ (ระเบียบข้อ 6) — ติดต่อผู้ดูแลระบบ');
    // คาบเดิมของกลุ่มร่วมที่จะย้ายตาม
    $planIds = array_map(fn($p) => (string)$p['id'], $plans);
    $moving = [];
    if ($existing) {
      foreach ($sem as $k => $s) {
        if (in_array((string)$s['plan_id'], $planIds, true) && (string)$s['day'] === (string)$existing['day'] && (string)$s['start'] === (string)$existing['start']) $moving[$k] = $s;
      }
    }
    $others = array_diff_key($sem, $moving);
    $len = num(js_true($payload['length'] ?? '') ? $payload['length'] : ($existing['length'] ?? 1)) ?: 1;
    $problems = [];
    $cands = [];
    foreach ($plans as $p) {
      $old = null;
      foreach ($moving as $s) if ((string)$s['plan_id'] === (string)$p['id']) { $old = $s; break; }
      if (array_key_exists('room_id', $payload)) $room = $payload['room_id'];
      elseif ($old) $room = $old['room_id'];
      else $room = with_home_room([$p])[0]['room_id'] ?? '';
      $cands[] = [
        'id' => $old ? $old['id'] : new_id(), 'semester_id' => $p['semester_id'], 'plan_id' => $p['id'], 'group_id' => $p['group_id'], 'course_id' => $p['course_id'],
        'teacher_ids' => $p['teacher_ids'], 'room_id' => $room, 'day' => num($payload['day'] ?? 0), 'start' => num($payload['start'] ?? 0), 'length' => $len,
        'locked' => $old ? $old['locked'] : false, 'updated_by' => $me['name'], 'updated_at' => now_dt(),
      ];
    }
    foreach ($cands as $c) $problems = array_merge($problems, sched_check($c, $others, $ctx));
    $hard = array_filter($problems, fn($x) => $x['hard']);
    if ($hard && !(js_true($payload['force'] ?? false) && $me['role'] === 'admin')) return ['ok' => false, 'problems' => $problems];
    // จำนวนคาบไม่เกินแผน ผู้ดูแลระบบก็บังคับวางเกินไม่ได้
    $over = sched_over_problem($plans, $sem, $moving, $len, $existing, $ctx);
    if ($over) return ['ok' => false, 'problems' => [$over]];
    writeTable('Sessions', array_merge(array_values(array_diff_key($all, $moving)), $cands));
    log_action($me, $existing ? 'ย้ายคาบ' : 'สร้างคาบ', sched_describe($cands[0], $ctx));
    return ['ok' => true, 'problems' => $problems, 'sessions' => sem_sessions($plan['semester_id'])];
  });
}

function api_removeSession($token = '', $id = '') {
  $me = auth_user($token, 'dept');
  return with_lock(function () use ($me, $id) {
    $all = readTable('Sessions');
    $i = find_row($all, str($id));
    if ($i === null) throw new Exception('ไม่พบคาบ');
    $s = $all[$i];
    assert_dept_can_edit($me, $s['group_id'], $s['semester_id']);
    if ((string)$s['locked'] === 'true' && $me['role'] !== 'admin') throw new Exception('คาบนี้ถูกล็อก');
    $ctx = sched_ctx_now();
    if ($me['role'] !== 'admin' && sched_is_workplace($s, $ctx)) throw new Exception('วิชาฝึกงาน/ทวิภาคี ล็อกห้ามขยับ (ระเบียบข้อ 6)');
    $plan = $ctx['plans'][$s['plan_id']] ?? null;
    $jk = $plan['joint_key'] ?? '';
    $jointIds = [(string)$s['plan_id']];
    if (js_true($jk)) {
      $jointIds = [];
      foreach (readTable('Plans') as $p) if ((string)$p['joint_key'] === (string)$jk && (string)$p['semester_id'] === (string)$s['semester_id']) $jointIds[] = (string)$p['id'];
    }
    $keep = [];
    foreach ($all as $k => $x) {
      $rm = $k === $i || (in_array((string)$x['plan_id'], $jointIds, true) && (string)$x['day'] === (string)$s['day'] && (string)$x['start'] === (string)$s['start']);
      if (!$rm) $keep[] = $x;
    }
    writeTable('Sessions', $keep);
    log_action($me, 'ลบคาบ', sched_describe($s, $ctx));
    return sem_sessions($s['semester_id']);
  });
}

function api_toggleLock($token = '', $id = '') {
  $me = auth_user($token, 'dept');
  return with_lock(function () use ($me, $id) {
    $all = readTable('Sessions');
    $i = find_row($all, str($id));
    if ($i === null) throw new Exception('ไม่พบคาบ');
    assert_dept_can_edit($me, $all[$i]['group_id'], $all[$i]['semester_id']);
    $all[$i]['locked'] = (string)$all[$i]['locked'] !== 'true';
    $sem = $all[$i]['semester_id'];
    writeTable('Sessions', $all);
    return sem_sessions($sem);
  });
}

/** ตรวจว่าวางได้ไหม */
function api_checkPlacement($token = '', $cand = []) {
  auth_user($token);
  $cand = (array)$cand;
  $plans = readTable('Plans');
  $i = find_row($plans, str($cand['plan_id'] ?? ''));
  if ($i === null) throw new Exception('ไม่พบแผนการเรียน');
  $others = array_filter(sem_sessions($plans[$i]['semester_id']), fn($s) => (string)$s['id'] !== str($cand['id'] ?? ''));
  return sched_check($cand, $others, sched_ctx_now());
}

/** จัดตารางอัตโนมัติ opt: {departmentId?, groupIds?: [], clear: bool} */
function api_runAutoSchedule($token = '', $semesterId = '', $opt = []) {
  $me = auth_user($token, 'dept');
  $opt = (array)($opt ?? []);
  if ($me['role'] === 'dept') $opt['departmentId'] = $me['department_id'];
  return with_lock(function () use ($me, $semesterId, $opt) {
    $ids = array_map('str', (array)($opt['groupIds'] ?? []));
    $groups = array_filter(readTable('Groups'), function ($g) use ($ids, $opt) {
      if ($ids) return in_array((string)$g['id'], $ids, true);
      if (js_true($opt['departmentId'] ?? '')) return (string)$g['department_id'] === str($opt['departmentId']);
      return true;
    });
    if ($me['role'] === 'dept') foreach ($groups as $g) assert_dept_can_edit($me, $g['id'], $semesterId);
    $gset = [];
    foreach ($groups as $g) $gset[$g['id']] = 1;
    $sem = str($semesterId);
    $all = readTable('Sessions');
    if (js_true($opt['clear'] ?? false)) {
      $all = array_values(array_filter($all, fn($s) => !((string)$s['semester_id'] === $sem && isset($gset[$s['group_id']]) && (string)$s['locked'] !== 'true')));
    }
    $fixed = array_values(array_filter($all, fn($s) => (string)$s['semester_id'] === $sem));
    $semPlans = array_values(array_filter(readTable('Plans'), fn($p) => (string)$p['semester_id'] === $sem));
    $plans = array_values(array_filter($semPlans, fn($p) => isset($gset[$p['group_id']])));
    // แผนเรียนร่วมที่มีกลุ่มนอกขอบเขต ให้ดึงเข้ามาด้วย
    $jk = [];
    foreach ($plans as $p) if (js_true($p['joint_key'])) $jk[$p['joint_key']] = 1;
    foreach ($semPlans as $p) if (js_true($p['joint_key']) && isset($jk[$p['joint_key']]) && !isset($gset[$p['group_id']])) $plans[] = $p;
    $ctx = sched_ctx_now();
    $res = sched_auto(with_home_room($plans), $fixed, $ctx, ['attempts' => 6, 'deadline' => microtime(true) + 20]);
    $now = now_dt();
    $add = array_map(fn($s) => [
      'id' => new_id(), 'semester_id' => $semesterId, 'plan_id' => $s['plan_id'], 'group_id' => $s['group_id'], 'course_id' => $s['course_id'],
      'teacher_ids' => $s['teacher_ids'], 'room_id' => $s['room_id'], 'day' => $s['day'], 'start' => $s['start'], 'length' => $s['length'], 'locked' => false,
      'updated_by' => $me['name'] . ' (อัตโนมัติ)', 'updated_at' => $now,
    ], $res['added']);
    writeTable('Sessions', array_merge($all, $add));
    log_action($me, 'จัดอัตโนมัติ', count($add) . ' คาบ, ค้าง ' . count($res['unplaced']));
    $planById = [];
    foreach ($plans as $p) $planById[$p['id']] = $p;
    return [
      'added' => count($add),
      'unplaced' => array_map(function ($u) use ($planById, $ctx) {
        $p = $planById[$u['plan_id']]; $g = $ctx['groups'][$p['group_id']] ?? null; $c = $ctx['courses'][$p['course_id']] ?? null;
        return ['plan_id' => $u['plan_id'], 'length' => $u['length'], 'label' => ($g ? $g['code'] : '') . ' · ' . ($c ? $c['code'] . ' ' . $c['name'] : '')];
      }, $res['unplaced']),
      'sessions' => sem_sessions($semesterId),
    ];
  });
}

/** ล้างตาราง (เฉพาะคาบที่ไม่ล็อก) */
function api_clearSchedule($token = '', $semesterId = '', $groupIds = []) {
  $me = auth_user($token, 'dept');
  $groupIds = (array)($groupIds ?? []);
  return with_lock(function () use ($me, $semesterId, $groupIds) {
    $set = [];
    foreach ($groupIds as $g) $set[str($g)] = 1;
    if ($me['role'] === 'dept') foreach ($groupIds as $g) assert_dept_can_edit($me, $g, $semesterId);
    if ($me['role'] !== 'admin' && !$groupIds) throw new Exception('เลือกกลุ่มเรียนก่อน');
    $keep = array_values(array_filter(readTable('Sessions'), function ($s) use ($semesterId, $groupIds, $set) {
      $inScope = (string)$s['semester_id'] === str($semesterId) && (!$groupIds || isset($set[$s['group_id']]));
      return !($inScope && (string)$s['locked'] !== 'true');
    }));
    writeTable('Sessions', $keep);
    log_action($me, 'ล้างตาราง', count($groupIds) . ' กลุ่ม');
    return sem_sessions($semesterId);
  });
}

function api_getActivityLog($token = '', $limit = 300) {
  auth_user($token, 'dept');
  $n = num($limit) ?: 300;
  return array_reverse(array_slice(readTable('Log'), -$n));
}

// ======================= รายงาน =======================
/** ข้อมูลสำหรับหน้าสาธารณะ (ไม่ต้องล็อกอิน) ไม่มีอีเมลหรือข้อมูลส่วนตัว */
/**
 * กลุ่มเรียนและครูที่ตารางยังไม่สมบูรณ์ คือมีวิชาในแผนที่ลงคาบไม่ครบ
 * กลุ่มที่ไม่มีทั้งแผนและคาบเรียนนับว่าไม่สมบูรณ์ด้วย ส่วนครูที่ไม่มีแผนไม่นับ เพราะอาจไม่มีชั่วโมงสอนจริง
 */
function plan_incomplete(string $sem, array $sessions, array $ctx): array {
  $placed = []; $busy = [];
  foreach ($sessions as $s) { $placed[$s['plan_id']] = ($placed[$s['plan_id']] ?? 0) + num($s['length']); $busy[$s['group_id']] = true; }
  $groups = []; $teachers = [];
  foreach ($ctx['plans'] as $p) {
    if ((string)$p['semester_id'] !== $sem) continue;
    $busy[$p['group_id']] = true;
    if (($placed[$p['id']] ?? 0) >= sched_plan_periods($p, $ctx)) continue;
    $groups[$p['group_id']] = true;
    foreach (explode(',', (string)$p['teacher_ids']) as $t) if (trim($t) !== '') $teachers[trim($t)] = true;
  }
  foreach ($ctx['groups'] as $id => $g) if (!isset($busy[$id])) $groups[$id] = true;
  return ['groups' => array_map('strval', array_keys($groups)), 'teachers' => array_map('strval', array_keys($teachers))];
}

function api_getPublicData($semesterId = '') {
  $s = readSettings();
  if (($s['public_enabled'] ?? '') === 'false') throw new Exception('ยังไม่เปิดเผยแพร่ตาราง');
  $sems = readTable('Semesters');
  $sem = js_true($semesterId) ? $semesterId : (js_true($s['default_semester_id'] ?? '') ? $s['default_semester_id'] : ($sems ? $sems[count($sems) - 1]['id'] : ''));
  $pick = fn(array $rows, array $cols) => array_map(fn($r) => array_intersect_key($r, array_flip($cols)), $rows);
  $ctx = sched_ctx_now();
  $rawSessions = sem_sessions($sem);
  $sessions = array_map(fn($x) => array_intersect_key($x, array_flip(['id', 'group_id', 'course_id', 'teacher_ids', 'room_id', 'day', 'start', 'length'])) + ['workplace' => sched_is_workplace($x, $ctx), 'hour_type' => (string)($ctx['plans'][$x['plan_id']]['hour_type'] ?? '')], $rawSessions);
  return [
    'incomplete' => plan_incomplete(str($sem), $rawSessions, $ctx),
    'settings' => [
      'college_name' => $s['college_name'] ?? null, 'periods_per_day' => $s['periods_per_day'] ?? null, 'period_start' => $s['period_start'] ?? null,
      'period_minutes' => $s['period_minutes'] ?? null, 'lunch_period' => $s['lunch_period'] ?? null, 'days' => $s['days'] ?? null, 'period_times' => $s['period_times'] ?? '',
      'color_default' => $s['color_default'] ?? '', 'color_workplace' => $s['color_workplace'] ?? '',
      'signer_curriculum' => $s['signer_curriculum'] ?? '', 'signer_deputy' => $s['signer_deputy'] ?? '', 'signer_director' => $s['signer_director'] ?? '',
    ],
    'days' => DAY_NAMES,
    'semester_id' => $sem,
    'semesters' => array_map(fn($x) => ['id' => $x['id'], 'label' => js_true($x['label']) ? $x['label'] : $x['term'] . '/' . $x['year']], $sems),
    'departments' => $pick(readTable('Departments'), ['id', 'name']),
    'groups' => $pick(readTable('Groups'), ['id', 'code', 'name', 'level', 'department_id', 'room_id', 'advisor_id']),
    // วุฒิการศึกษาและหน้าที่พิเศษ แสดงในแบบฟอร์มตารางสอนตามที่วิทยาลัยกำหนด
    'teachers' => $pick(readTable('Teachers'), ['id', 'name', 'department_id', 'is_dept_head', 'education', 'special_duty']),
    'rooms' => $pick(readTable('Rooms'), ['id', 'name', 'building_id']),
    'buildings' => readTable('Buildings'),
    'locked' => readTable('LockedSlots'),
    'plc' => readTable('PlcSlots'),
    'color_groups' => readTable('ColorGroups'),
    'courses' => $pick(readTable('Courses'), ['id', 'code', 'name', 'theory', 'practice', 'credits', 'color', 'course_type']),
    'sessions' => $sessions,
  ];
}

/** สรุปชั่วโมงสอนของครูทุกคนในภาคเรียน */
function api_teachingHours($token = '', $semesterId = '') {
  auth_user($token);
  $groups = idx_table('Groups');
  $res = [];
  foreach (readTable('Teachers') as $t) {
    $res[$t['id']] = ['teacher_id' => $t['id'], 'name' => $t['name'], 'department_id' => $t['department_id'], 'total' => 0, 'byLevel' => [], 'byDay' => [0, 0, 0, 0, 0, 0, 0], 'courses' => []];
  }
  foreach (sem_sessions($semesterId) as $s) {
    foreach (sched_ids($s['teacher_ids']) as $t) {
      if (!isset($res[$t])) continue;
      $len = num($s['length']);
      $lvl = js_true($groups[$s['group_id']]['level'] ?? '') ? $groups[$s['group_id']]['level'] : '-';
      $res[$t]['total'] += $len;
      $res[$t]['byLevel'][$lvl] = ($res[$t]['byLevel'][$lvl] ?? 0) + $len;
      $res[$t]['byDay'][num($s['day'])] += $len;
      $res[$t]['courses'][$s['course_id'] . '|' . $s['group_id']] = 1;
    }
  }
  return array_map(function ($r) {
    $r['courseCount'] = count($r['courses']);
    unset($r['courses']);
    $r['byLevel'] = (object)$r['byLevel'];
    return $r;
  }, array_values($res));
}

/** ความคืบหน้า: ชั่วโมงที่ลงแล้ว / ชั่วโมงตามแผน */
function api_progress($token = '', $semesterId = '') {
  auth_user($token);
  $ctx = sched_ctx_now();
  $plans = array_filter(readTable('Plans'), fn($p) => (string)$p['semester_id'] === str($semesterId));
  $need = 0;
  foreach ($plans as $p) $need += sched_plan_periods($p, $ctx);
  $done = 0;
  foreach (sem_sessions($semesterId) as $s) $done += num($s['length']);
  return ['need' => $need, 'done' => $done, 'plans' => count($plans)];
}

/** ส่งออกตารางเป็นไฟล์ Excel */
function api_exportToSheet($token = '', $semesterId = '') {
  auth_user($token, 'dept');
  $ctx = sched_ctx_now();
  $rows = [['กลุ่มเรียน', 'รหัสวิชา', 'ชื่อวิชา', 'ครูผู้สอน', 'ห้อง', 'วัน', 'คาบเริ่ม', 'จำนวนคาบ']];
  foreach (sem_sessions($semesterId) as $s) {
    $g = $ctx['groups'][$s['group_id']] ?? []; $c = $ctx['courses'][$s['course_id']] ?? [];
    $rows[] = [$g['code'] ?? '', $c['code'] ?? '', $c['name'] ?? '',
      implode(', ', array_map(fn($t) => $ctx['teachers'][$t]['name'] ?? $t, sched_ids($s['teacher_ids']))),
      $ctx['rooms'][$s['room_id']]['name'] ?? '', DAY_NAMES[num($s['day'])] ?? '', period_no($s['start']), num($s['length'])];
  }
  $label = str($semesterId);
  foreach (readTable('Semesters') as $x) if ((string)$x['id'] === $label) { $label = $x['label']; break; }
  return save_download(write_xlsx($rows, 'คาบทั้งหมด'), 'ตารางเรียน ' . preg_replace('/[\\\\\/:*?"<>|]/', '-', (string)$label) . '.xlsx');
}

// ======================= ไฟล์ดาวน์โหลดชั่วคราว (เก็บ 15 นาที) =======================
function exports_dir(): string {
  $d = APP_ROOT . '/data/exports';
  if (!is_dir($d)) mkdir($d, 0775, true);
  return $d;
}

function save_download(string $bytes, string $name): string {
  $d = exports_dir();
  foreach (glob($d . '/*') ?: [] as $f) if (filemtime($f) < time() - 900) @unlink($f);
  $id = bin2hex(random_bytes(12));
  file_put_contents($d . '/' . $id . '.xlsx', $bytes);
  file_put_contents($d . '/' . $id . '.name', $name);
  return 'download/' . $id;
}

/** เหมือน save_download แต่คัดลอกจากไฟล์ ไม่ต้องโหลดไฟล์ใหญ่ลงหน่วยความจำ */
function save_download_file(string $path, string $name): string {
  $url = save_download('', $name);
  if (!copy($path, exports_dir() . '/' . basename($url) . '.xlsx')) throw new Exception('เตรียมไฟล์ดาวน์โหลดไม่สำเร็จ ตรวจสอบพื้นที่ของเครื่อง');
  return $url;
}
