<?php
/**
 * ย้ายข้อมูลจากระบบ TAB เดิม ผ่าน "API ตารางเรียน" (อ่านอย่างเดียว)
 * ต้องตั้งค่า TAB_API_KEY ในไฟล์ .env ก่อน
 */
function tab_base(): string { return rtrim(cfg('TAB_BASE', 'https://tab.tkc.ac.th/tab-deploy/public/api/v1'), '/'); }

function tab_key(): string {
  $k = getProperty('TAB_API_KEY');
  if (!$k) throw new Exception('ยังไม่ได้ตั้งค่า TAB_API_KEY ในไฟล์ .env');
  return $k;
}

function tab_get(string $path, ?array $params = null) {
  $q = '';
  if ($params) {
    $parts = [];
    foreach ($params as $k => $v) if ($v !== '' && $v !== null) $parts[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
    if ($parts) $q = '?' . implode('&', $parts);
  }
  $ctx = stream_context_create(['http' => [
    'method' => 'GET', 'timeout' => 60, 'ignore_errors' => true,
    'header' => "Authorization: Bearer " . tab_key() . "\r\nAccept: application/json\r\nUser-Agent: tabhub\r\n",
  ]]);
  for ($i = 0; $i < 5; $i++) {
    $http_response_header = [];
    $body = @file_get_contents(tab_base() . $path . $q, false, $ctx);
    $headers = $http_response_header;
    if ($body === false && !$headers) throw new Exception('เชื่อมต่อระบบ TAB ไม่ได้');
    $code = preg_match('/\s(\d{3})\s?/', $headers[0] ?? '', $m) ? (int)$m[1] : 0;
    if ($code === 429) {
      $wait = 5;
      foreach ($headers as $h) if (stripos($h, 'retry-after:') === 0) $wait = (int)trim(substr($h, 12)) ?: 5;
      sleep($wait + 1);
      continue;
    }
    if ($code >= 400) throw new Exception('TAB API ' . $path . ' ตอบ ' . $code . ': ' . mb_substr((string)$body, 0, 300));
    usleep(550000); // ไม่เกิน 120 ครั้ง/นาที
    return json_decode((string)$body, true);
  }
  throw new Exception('TAB API ถูกจำกัดจำนวนครั้ง ลองใหม่ภายหลัง');
}

// ---------- ตัวช่วยอ่าน JSON ที่รูปแบบไม่แน่นอน ----------
function tab_list($resp): array {
  if (!is_array($resp)) return [];
  if (array_is_list($resp)) return $resp;
  foreach (['data', 'items', 'results', 'schedule', 'sessions', 'entries', 'periods', 'semesters', 'groups', 'teachers', 'rooms', 'courses', 'slots'] as $k) {
    $v = $resp[$k] ?? null;
    if (is_array($v) && array_is_list($v)) return $v;
    if (is_array($v)) { $inner = tab_list($v); if ($inner) return $inner; }
  }
  return [];
}

function tab_pick($o, array $keys) {
  if (!$o) return '';
  foreach ($keys as $key) {
    $v = $o;
    foreach (explode('.', $key) as $part) {
      $v = is_array($v) ? ($v[$part] ?? null) : null;
      if ($v === null) break;
    }
    if ($v !== null && $v !== '') return $v;
  }
  return '';
}

/** แปลงวันเป็น 0=จันทร์ ... 6=อาทิตย์ (ถ้าเป็นตัวเลข ใช้ TAB_DAY_BASE: ค่าเริ่ม 1 = จันทร์เป็น 1) */
function tab_norm_day($v) {
  if ($v === '' || $v === null) return '';
  $aliases = ['mon' => 0, 'monday' => 0, 'จันทร์' => 0, 'จ' => 0, 'tue' => 1, 'tuesday' => 1, 'อังคาร' => 1, 'อ' => 1, 'wed' => 2, 'wednesday' => 2, 'พุธ' => 2, 'พ' => 2,
    'thu' => 3, 'thursday' => 3, 'พฤหัสบดี' => 3, 'พฤหัส' => 3, 'พฤ' => 3, 'fri' => 4, 'friday' => 4, 'ศุกร์' => 4, 'ศ' => 4,
    'sat' => 5, 'saturday' => 5, 'เสาร์' => 5, 'ส' => 5, 'sun' => 6, 'sunday' => 6, 'อาทิตย์' => 6, 'อา' => 6];
  $s = mb_strtolower(trim((string)$v));
  if (isset($aliases[$s])) return $aliases[$s];
  if (ctype_digit($s)) {
    $base = (int)(getProperty('TAB_DAY_BASE') ?: 1);
    return ((int)$s - $base + 7) % 7;
  }
  return '';
}

function tab_teacher_list($o): array {
  $t = tab_pick($o, ['teachers', 'teacher', 'instructors', 'instructor']);
  if (!$t) return [];
  if (!is_array($t) || !array_is_list($t)) $t = [$t];
  return array_map(function ($x) {
    if (!is_array($x)) return ['id' => (string)$x, 'name' => (string)$x];
    return ['id' => (string)tab_pick($x, ['id', 'teacher_id']), 'name' => (string)tab_pick($x, ['full_name', 'name', 'display_name', 'teacher_name'])];
  }, $t);
}

function tab_import_all(): int {
  $n = 0; $t0 = time();
  foreach (tab_list(tab_get('/semesters')) as $s) {
    if (time() - $t0 > 240) break; // เกินเวลา ให้สั่งซ้ำเพื่อทำต่อ
    $id = tab_pick($s, ['id']);
    foreach (readTable('Sessions') as $x) if ((string)$x['semester_id'] === 'tab' . $id) continue 2;
    $n += tab_import_semester($id)['sessions'];
  }
  return $n;
}

function api_importFromTAB($token = '', $tabSemesterId = '') {
  $me = auth_user($token, 'curriculum');
  set_time_limit(0);
  $res = js_true($tabSemesterId) ? tab_import_semester($tabSemesterId) : ['sessions' => tab_import_all()];
  log_action($me, 'นำเข้าจาก TAB', $res);
  return $res;
}

function api_listTabSemesters($token = '') {
  auth_user($token, 'curriculum');
  return array_map(function ($s) {
    $lbl = tab_pick($s, ['label', 'name', 'title']);
    if (!$lbl || is_array($lbl)) $lbl = tab_pick($s, ['semester_no', 'term', 'semester']) . '/' . tab_pick($s, ['academic_year.year', 'year', 'academic_year']);
    return ['id' => tab_pick($s, ['id']), 'label' => (string)$lbl];
  }, tab_list(tab_get('/semesters')));
}

function tab_import_semester($tabSid): array {
  return with_lock(function () use ($tabSid) {
    $objs = fn(string $t) => array_map(fn($r) => (object)$r, readTable($t));
    $settings = readSettings();
    $semRaw = [];
    foreach (tab_list(tab_get('/semesters')) as $s) if ((string)tab_pick($s, ['id']) === (string)$tabSid) { $semRaw = $s; break; }
    // ---- ภาคเรียน ----
    $sems = readTable('Semesters');
    $semId = 'tab' . $tabSid;
    if (find_row($sems, $semId) === null) {
      $term = tab_pick($semRaw, ['semester_no', 'term', 'semester', 'no']);
      $year = tab_pick($semRaw, ['academic_year.year', 'year', 'academic_year']);
      if (is_array($year)) $year = json_encode($year, JSON_UNESCAPED_UNICODE);
      $label = tab_pick($semRaw, ['label', 'name', 'title']);
      $sems[] = ['id' => $semId, 'year' => $year, 'term' => $term, 'label' => js_true($label) ? $label : $term . '/' . $year, 'is_default' => (bool)tab_pick($semRaw, ['is_default', 'default', 'current']), 'start_date' => '', 'end_date' => ''];
      writeTable('Semesters', $sems);
    }
    // ---- ข้อมูลวิทยาลัย/คาบ ----
    try {
      $col = tab_get('/college');
      $c = $col['data'] ?? $col;
      $periods = tab_list(tab_pick($c, ['periods', 'period_times', 'time_slots']) ?: []);
      if ($periods) {
        saveSetting('periods_per_day', count($periods));
        saveSetting('period_times', json_encode(array_map(fn($p) => [tab_pick($p, ['start', 'start_time', 'from']), tab_pick($p, ['end', 'end_time', 'to'])], $periods), JSON_UNESCAPED_UNICODE));
      }
      $nm = tab_pick($c, ['name_th', 'name']);
      if ($nm && !js_true($settings['college_name'] ?? '')) saveSetting('college_name', $nm);
    } catch (Throwable $e) { error_log('college: ' . $e->getMessage()); }

    // ---- แผนก ----
    $depts = $objs('Departments');
    $deptId = function ($name) use (&$depts) {
      $name = trim((string)$name);
      if ($name === '') return '';
      foreach ($depts as $d) if ($d->name === $name) return $d->id;
      $d = (object)['id' => new_id(), 'name' => $name, 'sort' => count($depts) + 1];
      $depts[] = $d;
      return $d->id;
    };
    // ---- ครู ----
    $teachers = $objs('Teachers');
    $tByTab = []; $tByName = [];
    foreach ($teachers as $t) { if ($t->tab_id !== '') $tByTab[$t->tab_id] = $t; $tByName[$t->name] = $t; }
    $upTeacher = function ($tid, $name, $deptName) use (&$teachers, &$tByTab, &$tByName, $deptId) {
      $tid = (string)$tid; $name = trim((string)$name);
      $t = ($tid !== '' ? ($tByTab[$tid] ?? null) : null) ?? ($tByName[$name] ?? null);
      if (!$t) {
        $t = (object)array_merge(array_fill_keys(SCHEMA['Teachers'], ''), ['id' => new_id(), 'name' => $name, 'is_dept_head' => false, 'tab_id' => $tid]);
        $teachers[] = $t;
      }
      if ($tid !== '') { $t->tab_id = $tid; $tByTab[$tid] = $t; }
      if ($name !== '') { $t->name = $name; $tByName[$name] = $t; }
      if (js_true($deptName) && !js_true($t->department_id)) $t->department_id = $deptId($deptName);
      return $t->id;
    };
    foreach (tab_list(tab_get('/teachers', ['semester' => $tabSid])) as $x) {
      $upTeacher(tab_pick($x, ['id']), tab_pick($x, ['full_name', 'name', 'display_name']), tab_pick($x, ['department.name', 'department_name', 'department']));
    }
    // ---- อาคาร/ห้อง ----
    $buildings = $objs('Buildings');
    $bId = function ($name) use (&$buildings) {
      $name = trim((string)$name);
      if ($name === '') return '';
      foreach ($buildings as $b) if ($b->name === $name) return $b->id;
      $b = (object)['id' => new_id(), 'name' => $name];
      $buildings[] = $b;
      return $b->id;
    };
    $rooms = $objs('Rooms');
    $rByTab = [];
    foreach ($rooms as $r) if ($r->tab_id !== '') $rByTab[$r->tab_id] = $r;
    $upRoom = function ($rid, $name, $bname, $type, $cap) use (&$rooms, &$rByTab, $bId) {
      $rid = (string)$rid;
      if ($rid === '' && !js_true($name)) return '';
      $r = $rByTab[$rid] ?? null;
      if (!$r) foreach ($rooms as $x) if ($x->name === (string)$name && (!js_true($bname) || $x->building_id === $bId($bname))) { $r = $x; break; }
      if (!$r) {
        $r = (object)['id' => new_id(), 'building_id' => $bId($bname), 'name' => $name, 'type' => js_true($type) ? $type : '', 'capacity' => js_true($cap) ? $cap : '', 'shareable' => false, 'tab_id' => $rid];
        $rooms[] = $r;
      }
      if ($rid !== '') { $r->tab_id = $rid; $rByTab[$rid] = $r; }
      return $r->id;
    };
    foreach (tab_list(tab_get('/rooms', ['semester' => $tabSid])) as $x) {
      $upRoom(tab_pick($x, ['id']), tab_pick($x, ['name', 'room_no', 'number']), tab_pick($x, ['building.name', 'building_name', 'building']), tab_pick($x, ['type', 'room_type']), tab_pick($x, ['capacity']));
    }
    // ---- กลุ่มเรียน ----
    $groups = $objs('Groups');
    $gByTab = [];
    foreach ($groups as $g) if ($g->tab_id !== '') $gByTab[$g->tab_id] = $g;
    $upGroup = function ($gid, $code, $name, $level, $deptName) use (&$groups, &$gByTab, $deptId) {
      $gid = (string)$gid;
      $g = $gByTab[$gid] ?? null;
      if (!$g) foreach ($groups as $x) if ($x->code === (string)$code) { $g = $x; break; }
      if (!$g) {
        $g = (object)array_merge(array_fill_keys(SCHEMA['Groups'], ''), ['id' => new_id(), 'code' => $code, 'name' => js_true($name) ? $name : $code, 'tab_id' => $gid]);
        $groups[] = $g;
      }
      if ($gid !== '') { $g->tab_id = $gid; $gByTab[$gid] = $g; }
      if (js_true($code)) $g->code = $code;
      if (js_true($level)) {
        $clean = trim(preg_replace('/\.?\d+$/', '', (string)$level));
        $g->level = $clean !== '' ? $clean : $level;
        $g->year_level = $level;
      }
      if (js_true($deptName) && !js_true($g->department_id)) $g->department_id = $deptId($deptName);
      return $g;
    };
    $gRaw = tab_list(tab_get('/groups', ['semester' => $tabSid]));
    foreach ($gRaw as $x) {
      $upGroup(tab_pick($x, ['id']), tab_pick($x, ['code', 'name', 'short_name']), tab_pick($x, ['name', 'full_name']),
        tab_pick($x, ['level', 'grade', 'level_name', 'class_level']), tab_pick($x, ['department.name', 'department_name', 'department', 'major.department.name']));
    }
    // ---- รายวิชา + แผนการเรียน ----
    $courses = $objs('Courses');
    $cByCode = [];
    foreach ($courses as $c) $cByCode[$c->code] = $c;
    $upCourse = function ($code, $name, $th, $pr, $cr) use (&$courses, &$cByCode) {
      $code = trim((string)$code);
      if ($code === '') return '';
      $c = $cByCode[$code] ?? null;
      if (!$c) {
        $c = (object)array_merge(array_fill_keys(SCHEMA['Courses'], ''), ['id' => new_id(), 'code' => $code, 'name' => $name, 'theory' => $th, 'practice' => $pr, 'credits' => $cr]);
        $courses[] = $c; $cByCode[$code] = $c;
      }
      if (js_true($name)) $c->name = $name;
      if ($th !== '') $c->theory = $th;
      if ($pr !== '') $c->practice = $pr;
      if ($cr !== '') $c->credits = $cr;
      return $c->id;
    };
    $plans = array_map(fn($r) => (object)$r, array_values(array_filter(readTable('Plans'), fn($p) => (string)$p['semester_id'] !== $semId)));
    $planKey = [];
    $upPlan = function ($groupId, $courseId, array $teacherIds, $periods) use (&$plans, &$planKey, $semId) {
      $k = $groupId . '|' . $courseId;
      if (isset($planKey[$k])) {
        $p0 = $planKey[$k];
        $p0->teacher_ids = implode(',', array_values(array_unique(array_filter(array_merge(sched_ids($p0->teacher_ids), $teacherIds), 'js_true'))));
        if (js_true($periods) && !js_true($p0->periods)) $p0->periods = $periods;
        return $p0;
      }
      $p = (object)['id' => new_id(), 'semester_id' => $semId, 'group_id' => $groupId, 'course_id' => $courseId, 'teacher_ids' => implode(',', $teacherIds), 'room_id' => '', 'periods' => js_true($periods) ? $periods : '', 'split' => '', 'joint_key' => '', 'workplace' => '', 'note' => '', 'hour_type' => ''];
      $plans[] = $p; $planKey[$k] = $p;
      return $p;
    };
    try {
      foreach (tab_list(tab_get('/courses', ['semester' => $tabSid])) as $x) {
        $g = $upGroup(tab_pick($x, ['group.id', 'group_id']), tab_pick($x, ['group.code', 'group.name', 'group_code', 'group_name']), '', tab_pick($x, ['group.level', 'level']), '');
        $cid = $upCourse(tab_pick($x, ['course.code', 'course_code', 'code', 'subject_code']), tab_pick($x, ['course.name', 'course_name', 'name', 'subject_name']),
          tab_pick($x, ['course.theory', 'theory', 'course.theory_hours']), tab_pick($x, ['course.practice', 'practice', 'course.practice_hours']), tab_pick($x, ['course.credits', 'credits', 'course.credit']));
        if ($cid === '') continue;
        $tids = array_map(fn($t) => $upTeacher($t['id'], $t['name'], ''), tab_teacher_list($x));
        $hrs = tab_pick($x, ['periods_per_week', 'hours_per_week', 'periods', 'hours', 'scheduled_periods']);
        $upPlan($g->id, $cid, $tids, (is_int($hrs) || is_float($hrs)) ? $hrs : '');
      }
    } catch (Throwable $e) { error_log('courses: ' . $e->getMessage()); }

    // ---- ตารางรายกลุ่ม ----
    $sessions = [];
    $periodsPerDay = num(readSettings()['periods_per_day'] ?? 12) ?: 12;
    foreach ($gRaw as $x) {
      $g = $gByTab[(string)tab_pick($x, ['id'])] ?? null;
      if (!$g) continue;
      foreach (tab_list(tab_get('/groups/' . $g->tab_id . '/schedule', ['semester' => $tabSid])) as $e) {
        $day = tab_norm_day(tab_pick($e, ['day_of_week', 'day', 'weekday', 'day_no', 'day_name']));
        $ps = tab_pick($e, ['periods']);
        $start = num(tab_pick($e, ['period_start', 'start_period', 'period_from', 'start', 'period']));
        $end = num(tab_pick($e, ['period_end', 'end_period', 'period_to', 'end']));
        if (is_array($ps) && $ps) { $nums = array_map('num', $ps); $start = min($nums); $end = max($nums); }
        $len = num(tab_pick($e, ['length', 'duration', 'period_count'])) ?: (($end && $start) ? $end - $start + 1 : 1);
        if ($day === '' || !$start) continue;
        if ($start + $len - 1 > $periodsPerDay) $len = max(1, $periodsPerDay - $start + 1);
        $cid = $upCourse(tab_pick($e, ['course.code', 'course_code', 'subject.code', 'code']), tab_pick($e, ['course.name', 'course_name', 'subject.name', 'name']), '', '', '');
        if ($cid === '') continue;
        $tids = array_map(fn($t) => $upTeacher($t['id'], $t['name'], ''), tab_teacher_list($e));
        $roomObj = tab_pick($e, ['room']);
        if ($roomObj && is_array($roomObj)) $rid = $upRoom(tab_pick($roomObj, ['id']), tab_pick($roomObj, ['name', 'room_no', 'number']), tab_pick($roomObj, ['building.name', 'building_name', 'building']), '', '');
        else $rid = $roomObj ? $upRoom('', (string)$roomObj, tab_pick($e, ['building', 'building_name']), '', '') : '';
        $plan = $upPlan($g->id, $cid, $tids, '');
        $sessions[] = ['id' => new_id(), 'semester_id' => $semId, 'plan_id' => $plan->id, 'group_id' => $g->id, 'course_id' => $cid, 'teacher_ids' => implode(',', $tids), 'room_id' => $rid,
          'day' => $day, 'start' => $start, 'length' => $len, 'locked' => false, 'updated_by' => 'นำเข้าจาก TAB', 'updated_at' => now_dt()];
      }
    }
    // ช่องที่ลงไว้ในแผน = จำนวนคาบที่ลงตารางจริง (ถ้า API ไม่บอกชั่วโมง)
    foreach ($plans as $p) {
      if ((string)$p->semester_id !== $semId || js_true($p->periods)) continue;
      $n = 0;
      foreach ($sessions as $s) if ($s['plan_id'] === $p->id) $n += $s['length'];
      if ($n) $p->periods = $n;
    }
    // ตรวจกลุ่มเรียนร่วม: หลายกลุ่ม ครูเดียวกัน วิชาเดียวกัน วัน/คาบเดียวกัน → joint_key
    $slot = [];
    foreach ($sessions as $s) $slot[$s['course_id'] . '|' . $s['teacher_ids'] . '|' . $s['day'] . '|' . $s['start']][] = $s;
    $pById = [];
    foreach ($plans as $p) $pById[$p->id] = $p;
    foreach ($slot as $arr) {
      if (count($arr) < 2 || !js_true($arr[0]['teacher_ids'])) continue;
      $key = 'J' . $arr[0]['plan_id'];
      foreach ($arr as $s) if (!js_true($pById[$s['plan_id']]->joint_key)) $pById[$s['plan_id']]->joint_key = $key;
    }
    writeTable('Departments', $depts);
    writeTable('Teachers', $teachers);
    writeTable('Buildings', $buildings);
    writeTable('Rooms', $rooms);
    writeTable('Groups', $groups);
    writeTable('Courses', $courses);
    writeTable('Plans', $plans);
    $keep = array_values(array_filter(readTable('Sessions'), fn($s) => (string)$s['semester_id'] !== $semId));
    writeTable('Sessions', array_merge($keep, $sessions));
    $np = 0;
    foreach ($plans as $p) if ($p->semester_id === $semId) $np++;
    return ['semester' => $semId, 'groups' => count($gRaw), 'plans' => $np, 'sessions' => count($sessions)];
  });
}
