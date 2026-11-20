<?php
/**
 * ติดตามการส่งงาน (สำหรับรองผู้อำนวยการฝ่ายวิชาการ / งานพัฒนาหลักสูตรฯ) — อ่านอย่างเดียว ทุกแผนก
 *  1. ครูที่ยังไม่ทำเบิก (ยังไม่ยืนยันล็อกใบเบิกในรอบ)
 *  2. สอนแทน/สอนชดเชย: คาบที่ลา/ไปราชการแต่ยังไม่บันทึกสอนชดเชยหรือสอนแทน และสอนชดเชยที่ยังไม่ส่งรายงานผล
 *  3. วิชาที่สอนในสถานประกอบการที่ยังไม่มีบันทึกนิเทศ
 *  4. แผนกวิชาที่ยังไม่ยืนยันส่งงบหน้า (ตาราง OtCovers)
 *  5. ผู้ที่ยังไม่มีลายเซ็นอิเล็กทรอนิกส์
 */

/** รอบการเบิกของภาคเรียน เรียงตามวันเริ่ม และรอบที่เลือก (ไม่ระบุ = รอบล่าสุดที่เริ่มแล้ว) */
function fu_rounds($semesterId, $roundId): array {
  $rounds = array_values(array_filter(readTable('OtRounds'), fn($r) => (string)$r['semester_id'] === str($semesterId)));
  stable_usort($rounds, fn($a, $b) => strcmp((string)$a['start_date'], (string)$b['start_date']));
  $sel = null;
  foreach ($rounds as $r) if ((string)$r['id'] === str($roundId)) $sel = $r;
  if (!$sel) {
    $today = date('Y-m-d');
    foreach ($rounds as $r) if (substr((string)$r['start_date'], 0, 10) <= $today) $sel = $r;
    $sel ??= $rounds[0] ?? null;
  }
  return [$rounds, $sel];
}

/** 1. ครูที่เปิดสิทธิ์เบิกแต่ยังไม่ยืนยันล็อกใบเบิกในรอบ */
function fu_claims(?array $round, array $teachers): array {
  if (!$round) return [];
  $claims = [];
  foreach (readTable('OtClaims') as $c) if ((string)$c['round_id'] === (string)$round['id'] && !isset($claims[$c['teacher_id']])) $claims[$c['teacher_id']] = $c;
  $out = [];
  foreach ($teachers as $t) {
    if ((string)$t['ot_enabled'] !== 'true') continue;
    $c = $claims[$t['id']] ?? null;
    if ($c && $c['status'] === 'locked') continue;
    $out[] = ['teacher_id' => $t['id'], 'status' => $c ? 'draft' : 'none', 'updated_at' => $c ? substr((string)$c['updated_at'], 0, 16) : ''];
  }
  return $out;
}

/** 2ก. คาบสอนที่ตรงกับวันลา/ไปราชการ แต่ยังไม่มีรายการสอนชดเชยหรือสอนแทน (รวมรายวัน+คาบเรียน) */
function fu_uncovered(?array $round, array $teachers): array {
  if (!$round) return [];
  $C = ot_ctx($round);
  $own = array_values(array_filter($C['absences'], fn($a) => $a['teacher_id'] !== '*'));
  if (!$own) return [];
  $holidays = array_values(array_filter($C['absences'], fn($a) => $a['teacher_id'] === '*')); // วันหยุดของทุกคน ไม่ต้องสอนชดเชย
  $subs = array_values(array_filter(readTable('OtSubs'), fn($r) => !in_array((string)$r['status'], ['cancelled', 'deleted'], true)));
  $out = [];
  foreach (array_unique(array_map(fn($a) => (string)$a['teacher_id'], $own)) as $tid) {
    $t = $teachers[$tid] ?? null;
    if (!$t) continue;
    $mine = array_values(array_filter($own, fn($a) => (string)$a['teacher_id'] === $tid));
    $weeks = ot_hours_for(['settings' => $C['settings'], 'teacher' => $t, 'round' => $round, 'sessions' => $C['sessions'], 'groups' => $C['groups'], 'courses' => $C['courses'], 'absences' => $mine, 'blocked' => $C['blocked'], 'subs' => []]);
    foreach ($weeks as $w) foreach ($w['items'] as $it) {
      if (js_true($it['sub'] ?? '') || js_true($it['blocked'] ?? '')) continue; // คาบที่ไปสอนแทนผู้อื่น / วันหยุด
      $a = ot_absent_at($mine, $tid, $it['date'], (int)$it['period']);
      if (!$a || ot_absent_at($holidays, $tid, $it['date'], (int)$it['period'])) continue;
      $covered = false;
      foreach ($subs as $r) {
        $od = substr(js_true($r['original_date'] ?? '') ? (string)$r['original_date'] : (string)$r['date'], 0, 10);
        if ((string)$r['original_teacher_id'] === $tid && (string)$r['session_id'] === (string)$it['session_id'] && $od === $it['date']) { $covered = true; break; }
      }
      if ($covered) continue;
      $k = $tid . '|' . $it['date'] . '|' . $it['session_id'];
      $row = &$out[$k];
      $row ??= ['teacher_id' => $tid, 'date' => $it['date'], 'session_id' => $it['session_id'], 'course_id' => $it['course_id'], 'group_ids' => $it['group_ids'] ?? [$it['group_id']], 'periods' => [], 'type' => js_true($a['type'] ?? '') ? $a['type'] : 'ลา'];
      $row['periods'][] = (int)$it['period'];
      unset($row);
    }
  }
  $out = array_values($out);
  foreach ($out as &$r) { $r['periods'] = array_values(array_unique($r['periods'])); sort($r['periods']); }
  unset($r);
  stable_usort($out, fn($a, $b) => [$a['teacher_id'], $a['date']] <=> [$b['teacher_id'], $b['date']]);
  return $out;
}

/** 2ข. สอนชดเชยที่บันทึกแล้วแต่ยังไม่ส่งรายงานผล */
function fu_reports(?array $round): array {
  if (!$round) return [];
  $today = date('Y-m-d');
  $out = [];
  foreach (ot_subs_with_reports(readTable('OtSubs')) as $r) {
    if ((string)$r['round_id'] !== (string)$round['id'] || $r['type'] !== 'makeup' || (string)$r['report_done'] === 'true' || in_array((string)$r['status'], ['cancelled', 'deleted'], true)) continue;
    $date = substr((string)$r['date'], 0, 10); $due = substr((string)$r['report_due'], 0, 10);
    $out[] = ['id' => $r['id'], 'teacher_id' => $r['original_teacher_id'], 'course_id' => $r['course_id'], 'group_id' => $r['group_id'], 'original_date' => substr((string)$r['original_date'], 0, 10), 'date' => $date, 'due' => $due,
      'state' => $date > $today ? 'upcoming' : ($due !== '' && $today > $due ? 'overdue' : 'waiting')];
  }
  stable_usort($out, fn($a, $b) => [$a['teacher_id'], $a['date']] <=> [$b['teacher_id'], $b['date']]);
  return $out;
}

/** 3. วิชาในสถานประกอบการที่ยังไม่มีการนิเทศที่ทำเสร็จแล้วในภาคเรียน */
function fu_visits($semesterId, ?callable $keep = null): array {
  $done = [];
  foreach (readTable('SupervisionVisits') as $v) if ((string)$v['semester_id'] === str($semesterId) && $v['status'] === 'done') $done[$v['unit_key']] = ($done[$v['unit_key']] ?? 0) + 1;
  $units = supervise_units($semesterId);
  // กิจกรรมในสถานประกอบการไม่ต้องรายงานการนิเทศ (ยังบันทึกนิเทศได้ในเมนูนิเทศออนไลน์ แต่ไม่นับว่าค้าง)
  $courses = idx_table('Courses');
  $units = array_filter($units, fn($u) => (string)($courses[$u['course_id']]['course_type'] ?? '') !== 'activity');
  if ($keep) $units = array_filter($units, $keep);
  $out = [];
  foreach ($units as $key => $u) {
    if (!empty($done[$key])) continue;
    $out[] = ['key' => $key, 'course_id' => $u['course_id'], 'group_ids' => $u['group_ids'], 'teacher_ids' => $u['teacher_ids'], 'hours' => $u['hours']];
  }
  return ['rows' => $out, 'total' => count($units)];
}

/** 4. สถานะยืนยันส่งงบหน้าของแต่ละแผนก (แผนกที่มีครูเปิดสิทธิ์เบิก) */
function fu_covers($semesterId, array $teachers): array {
  $conf = [];
  foreach (readTable('OtCovers') as $c) if ((string)$c['semester_id'] === str($semesterId) && $c['status'] === 'confirmed') $conf[$c['department_id']] = $c;
  $depts = [];
  foreach ($teachers as $t) if ((string)$t['ot_enabled'] === 'true') $depts[(string)$t['department_id']] = true;
  $out = [];
  foreach (idx_table('Departments') as $d) {
    if (!isset($depts[(string)$d['id']])) continue;
    $c = $conf[$d['id']] ?? null;
    $out[] = ['department_id' => $d['id'], 'confirmed' => (bool)$c, 'by' => $c['created_by'] ?? '', 'at' => $c ? substr((string)$c['created_at'], 0, 16) : '', 'note' => $c['note'] ?? ''];
  }
  return $out;
}

/** 5. ครูที่มีคาบสอนในภาคเรียนแต่ยังไม่มีลายเซ็น และผู้ลงนามในหน้าตั้งค่าที่ยังไม่มีลายเซ็น */
function fu_signatures($semesterId, array $teachers): array {
  $sigs = readTable('Signatures');
  $owners = [];
  foreach ($sigs as $s) $owners[(string)$s['owner']] = true;
  $users = readTable('users');
  $byTeacher = []; $signedNames = [];
  foreach ($users as $u) {
    if (!js_true($u['teacher_id'] ?? '')) continue;
    $byTeacher[(string)$u['teacher_id']][] = $u;
  }
  foreach ($users as $u) if (isset($owners['u:' . $u['id']])) foreach (sig_user_names($u, $teachers) as $n) $signedNames[sig_key($n)] = true;
  $teaching = [];
  foreach (sem_sessions($semesterId) as $s) foreach (sched_ids($s['teacher_ids'] ?? '') as $tid) $teaching[$tid] = true;
  $out = [];
  foreach ($teachers as $t) {
    if (!isset($teaching[(string)$t['id']])) continue;
    if (mb_strpos((string)$t['name'], 'ครูฝึก') !== false) continue; // ครูฝึกในสถานประกอบการ (ชื่อตัวแทน) ไม่ได้ลงนามในเอกสารของระบบ
    if (isset($signedNames[sig_key($t['name'])])) continue;
    $out[] = ['teacher_id' => $t['id'], 'has_account' => !empty($byTeacher[(string)$t['id']])];
  }
  $settings = readSettings(); $signers = [];
  foreach (SIGNATURE_SETTING_KEYS as $k) {
    $name = trim((string)($settings[$k] ?? ''));
    if ($name === '' || isset($owners['s:' . $k]) || isset($signedNames[sig_key($name)])) continue;
    $signers[] = ['key' => $k, 'name' => $name];
  }
  return ['teachers' => $out, 'signers' => $signers];
}

function api_followupReport($token = '', $semesterId = '', $roundId = '') {
  $me = auth_user($token, 'dept');
  $sem = idx_table('Semesters')[str($semesterId)] ?? null;
  if (!$sem) throw new Exception('ไม่พบภาคเรียน');
  [$rounds, $round] = fu_rounds($semesterId, $roundId);
  $teachers = idx_table('Teachers');
  // วิชาการแผนก/หัวหน้าแผนกวิชา: เห็นเฉพาะแผนกตัวเอง (ส่วนงานพัฒนาหลักสูตรฯ ผู้บริหาร ผู้ดูแลระบบ เห็นทุกแผนก)
  $myDept = USER_ROLES[$me['role']] < USER_ROLES['curriculum'] ? (string)$me['department_id'] : '';
  if (USER_ROLES[$me['role']] < USER_ROLES['curriculum'] && $myDept === '') throw new Exception('บัญชีนี้ยังไม่ได้ระบุแผนกวิชา');
  if ($myDept !== '') $teachers = array_filter($teachers, fn($t) => (string)$t['department_id'] === $myDept);
  // วิชาในสถานประกอบการของแผนก: ครูผู้สอนอยู่ในแผนก หรือยังไม่กำหนดครูแต่เป็นกลุ่มของแผนก
  $groups = idx_table('Groups');
  $visits = fu_visits($semesterId, $myDept === '' ? null : function ($u) use ($teachers, $groups, $myDept) {
    if ($u['teacher_ids']) { foreach ($u['teacher_ids'] as $t) if (isset($teachers[$t])) return true; return false; }
    foreach ($u['group_ids'] as $g) if ((string)($groups[$g]['department_id'] ?? '') === $myDept) return true;
    return false;
  });
  $sigs = fu_signatures($semesterId, $teachers);
  if ($myDept !== '') $sigs['signers'] = []; // ผู้ลงนามระดับวิทยาลัย เป็นเรื่องของผู้ดูแลระบบ
  $reports = array_values(array_filter(fu_reports($round), fn($r) => isset($teachers[$r['teacher_id']])));
  return [
    'my_dept' => $myDept,
    'rounds' => array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'start_date' => substr((string)$r['start_date'], 0, 10), 'end_date' => substr((string)$r['end_date'], 0, 10)], $rounds),
    'round_id' => $round['id'] ?? '',
    'claims' => fu_claims($round, $teachers),
    'uncovered' => fu_uncovered($round, $teachers),
    'reports' => $reports,
    'visits' => $visits,
    'covers' => fu_covers($semesterId, $teachers),
    'signatures' => $sigs,
    'today' => date('Y-m-d'),
  ];
}

/** ยืนยัน/ยกเลิกการส่งงบหน้าแผนกวิชาของภาคเรียน */
function api_otCoverConfirm($token = '', $semesterId = '', $deptId = '', $note = '', $cancel = false) {
  $me = auth_user($token, 'dept');
  if ($me['role'] === 'dept' && str($deptId) !== (string)$me['department_id']) throw new Exception('ยืนยันงบหน้าได้เฉพาะแผนกตัวเอง');
  if (!isset(idx_table('Departments')[str($deptId)])) throw new Exception('กรุณาเลือกแผนกวิชา');
  if (!isset(idx_table('Semesters')[str($semesterId)])) throw new Exception('ไม่พบภาคเรียน');
  return with_lock(function () use ($me, $semesterId, $deptId, $note, $cancel) {
    $rows = readTable('OtCovers');
    $i = null;
    foreach ($rows as $k => $c) if ((string)$c['semester_id'] === str($semesterId) && (string)$c['department_id'] === str($deptId) && $c['status'] === 'confirmed') { $i = $k; break; }
    if (js_true($cancel)) {
      if ($i === null) return true;
      $rows[$i]['status'] = 'cancelled';
      writeTable('OtCovers', $rows);
      log_action($me, 'ยกเลิกยืนยันส่งงบหน้า', str($deptId));
      return true;
    }
    if ($i !== null) throw new Exception('แผนกนี้ยืนยันส่งงบหน้าภาคเรียนนี้แล้ว');
    $rows[] = ['id' => new_id(), 'semester_id' => str($semesterId), 'department_id' => str($deptId), 'status' => 'confirmed', 'note' => clean_text($note, 300), 'created_by' => $me['name'], 'created_at' => now_dt()];
    writeTable('OtCovers', $rows);
    log_action($me, 'ยืนยันส่งงบหน้า', str($deptId));
    return true;
  });
}

/** สถานะยืนยันส่งงบหน้าของแผนก (แสดงในหน้างบหน้า) */
function api_otCoverStatus($token = '', $semesterId = '', $deptId = '') {
  $me = auth_user($token, 'dept');
  if ($me['role'] === 'dept' && str($deptId) !== (string)$me['department_id']) throw new Exception('ดูได้เฉพาะแผนกตัวเอง');
  foreach (readTable('OtCovers') as $c) {
    if ((string)$c['semester_id'] === str($semesterId) && (string)$c['department_id'] === str($deptId) && $c['status'] === 'confirmed') return ['confirmed' => true, 'by' => $c['created_by'], 'at' => substr((string)$c['created_at'], 0, 16), 'note' => $c['note']];
  }
  return ['confirmed' => false];
}
