<?php
/** รายงานการสอนออนไลน์, นิเทศนักศึกษาฝึกงาน และงบหน้าแผนกวิชา */

function valid_date($s): bool { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4)); }

/** รับเฉพาะลิงก์ http/https เพื่อกันลิงก์อันตรายในหน้าเว็บ */
function clean_link($v): string {
  $v = trim(str($v ?? ''));
  if ($v === '') return '';
  if (!preg_match('#^https?://[^\s<>"\']+$#i', $v)) throw new Exception('ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
  return mb_substr($v, 0, 1000);
}

function clean_text($v, int $max = 5000): string { return mb_substr(trim(str($v ?? '')), 0, $max); }

/** ผู้ใช้ทำรายการแทนครูคนนี้ได้ไหม: แอดมินทุกคน, แผนกเฉพาะครูในแผนก, ครูเฉพาะตัวเอง */
function assert_teacher_scope(array $me, $teacherId): array {
  $t = idx_table('Teachers')[str($teacherId)] ?? null;
  if (!$t) throw new Exception('ไม่พบครู');
  if ($me['role'] === 'admin') return $t;
  if ($me['role'] === 'dept' && (string)$t['department_id'] === (string)$me['department_id']) return $t;
  if ($me['role'] === 'teacher' && (string)$me['teacher_id'] === (string)$t['id']) return $t;
  throw new Exception('ทำรายการได้เฉพาะครูของตัวเองหรือในแผนกตัวเอง');
}

function date_range(string $from, string $to): array {
  $out = [];
  for ($d = ot_parse($from), $e = ot_parse($to); $d <= $e; $d = $d->modify('+1 day')) $out[] = ot_ymd($d);
  return $out;
}

// ======================= รายงานการสอนออนไลน์ =======================
/**
 * คาบที่ครูต้องรายงานในช่วงวันที่ รวมคาบสอนแทน/สอนชดเชยที่ครูคนนี้สอนจริง
 * คาบที่มีผู้สอนแทนหรือย้ายไปสอนชดเชย จะไม่ต้องรายงานในวันเดิม
 */
function teacher_slots($semesterId, string $tid, string $from, string $to): array {
  $settings = readSettings();
  $days = array_map('num', sched_ids(js_true($settings['days'] ?? '') ? $settings['days'] : '0,1,2,3,4'));
  $sessions = array_filter(sem_sessions($semesterId), fn($s) => in_array($tid, sched_ids($s['teacher_ids']), true));
  $subs = array_filter(readTable('OtSubs'), fn($r) => $r['status'] !== 'cancelled');
  $all = idx_table('Sessions');
  $slots = [];
  foreach (date_range($from, $to) as $date) {
    $dow = ot_dow(ot_parse($date));
    if (in_array($dow, $days, true)) {
      foreach ($sessions as $s) {
        if (num($s['day']) !== $dow) continue;
        $replaced = false;
        foreach ($subs as $r) {
          $od = substr(js_true($r['original_date']) ? $r['original_date'] : $r['date'], 0, 10);
          if ((string)$r['original_teacher_id'] === $tid && (string)$r['session_id'] === (string)$s['id'] && $od === $date) { $replaced = true; break; }
        }
        $slots[] = ['date' => $date, 'session_id' => $s['id'], 'group_id' => $s['group_id'], 'course_id' => $s['course_id'], 'room_id' => $s['room_id'], 'start' => num($s['start']), 'length' => num($s['length']), 'kind' => 'normal', 'replaced' => $replaced];
      }
    }
    foreach ($subs as $r) {
      if ((string)$r['teacher_id'] !== $tid || substr((string)$r['date'], 0, 10) !== $date) continue;
      $s = $all[$r['session_id']] ?? null;
      if ($s && (string)$s['semester_id'] !== str($semesterId)) continue;
      $slots[] = ['date' => $date, 'session_id' => $r['session_id'], 'group_id' => $r['group_id'], 'course_id' => $r['course_id'], 'room_id' => $s['room_id'] ?? '', 'start' => num($r['start']), 'length' => num($r['length']), 'kind' => $r['type'], 'replaced' => false];
    }
  }
  usort($slots, fn($a, $b) => [$a['date'], $a['start']] <=> [$b['date'], $b['start']]);
  return $slots;
}

function teach_report_index($semesterId, string $tid): array {
  $idx = [];
  foreach (readTable('TeachReports') as $r) {
    if ((string)$r['semester_id'] === str($semesterId) && (string)$r['teacher_id'] === $tid) $idx[$r['date'] . '|' . $r['session_id'] . '|' . $r['start']] = $r;
  }
  return $idx;
}

function api_teachReportSlots($token = '', $semesterId = '', $teacherId = '', $anyDate = '') {
  $me = auth_user($token);
  $t = assert_teacher_scope($me, $teacherId);
  if (!valid_date($anyDate)) throw new Exception('ระบุวันที่ไม่ถูกต้อง');
  $d = ot_parse($anyDate);
  $mon = $d->modify('-' . ot_dow($d) . ' day');
  $from = ot_ymd($mon); $to = ot_ymd($mon->modify('+6 day'));
  $idx = teach_report_index($semesterId, (string)$t['id']);
  $slots = array_map(function ($s) use ($idx) {
    $s['report'] = $idx[$s['date'] . '|' . $s['session_id'] . '|' . $s['start']] ?? null;
    return $s;
  }, teacher_slots($semesterId, (string)$t['id'], $from, $to));
  return ['from' => $from, 'to' => $to, 'today' => date('Y-m-d'), 'slots' => $slots];
}

/** rec: {semester_id, teacher_id, session_id, date, start, topic, activity, students_present, students_absent, problems, attachment_link} */
function api_teachReportSave($token = '', $rec = [], $submit = false) {
  $me = auth_user($token);
  $rec = (array)$rec;
  $t = assert_teacher_scope($me, $rec['teacher_id'] ?? '');
  $tid = (string)$t['id'];
  $date = $rec['date'] ?? '';
  if (!valid_date($date)) throw new Exception('ระบุวันที่ไม่ถูกต้อง');
  if ($date > date('Y-m-d')) throw new Exception('รายงานล่วงหน้าไม่ได้ ต้องรายงานหลังวันสอน');
  $topic = clean_text($rec['topic'] ?? '', 500);
  if (js_true($submit) && $topic === '') throw new Exception('กรุณาระบุหัวข้อที่สอน');
  $link = clean_link($rec['attachment_link'] ?? '');
  $present = num($rec['students_present'] ?? 0); $absent = num($rec['students_absent'] ?? 0);
  if ($present < 0 || $absent < 0) throw new Exception('จำนวนนักเรียนต้องไม่ติดลบ');
  $sem = $rec['semester_id'] ?? '';
  return with_lock(function () use ($me, $rec, $tid, $date, $topic, $link, $present, $absent, $sem, $submit) {
    $slot = null;
    foreach (teacher_slots($sem, $tid, $date, $date) as $s) {
      if ((string)$s['session_id'] === str($rec['session_id'] ?? '') && (string)$s['start'] === str($rec['start'] ?? '') && !$s['replaced']) { $slot = $s; break; }
    }
    if (!$slot) throw new Exception('คาบนี้ไม่อยู่ในตารางสอนของครูในวันที่เลือก');
    $rows = readTable('TeachReports');
    $i = null;
    foreach ($rows as $k => $r) {
      if ((string)$r['semester_id'] === str($sem) && (string)$r['teacher_id'] === $tid && $r['date'] === $date && (string)$r['session_id'] === (string)$slot['session_id'] && (string)$r['start'] === (string)$slot['start']) { $i = $k; break; }
    }
    if ($i !== null && $rows[$i]['status'] === 'approved' && $me['role'] !== 'admin') throw new Exception('รายงานนี้อนุมัติแล้ว แก้ไขไม่ได้');
    if ($i === null) { $rows[] = ['id' => new_id(), 'created_at' => now_dt(), 'reviewed_by' => '', 'reviewed_at' => '', 'review_note' => '', 'submitted_at' => '', 'status' => 'draft']; $i = count($rows) - 1; }
    $rows[$i] = array_merge($rows[$i], [
      'semester_id' => $sem, 'teacher_id' => $tid, 'session_id' => $slot['session_id'], 'group_id' => $slot['group_id'], 'course_id' => $slot['course_id'],
      'date' => $date, 'start' => $slot['start'], 'length' => $slot['length'], 'kind' => $slot['kind'], 'topic' => $topic, 'activity' => clean_text($rec['activity'] ?? ''),
      'students_present' => $present, 'students_absent' => $absent, 'problems' => clean_text($rec['problems'] ?? ''), 'attachment_link' => $link,
    ]);
    if (js_true($submit)) { $rows[$i]['status'] = 'submitted'; $rows[$i]['submitted_at'] = now_dt(); }
    $id = $rows[$i]['id']; $status = $rows[$i]['status'];
    writeTable('TeachReports', $rows);
    log_action($me, js_true($submit) ? 'ส่งรายงานการสอน' : 'บันทึกร่างรายงานการสอน', $id);
    return ['id' => $id, 'status' => $status];
  });
}

/** filter: {semester_id, department_id?, teacher_id?, status?, from?, to?} */
function api_teachReportList($token = '', $filter = []) {
  $me = auth_user($token);
  $f = (array)$filter;
  $teachers = idx_table('Teachers');
  $rows = array_values(array_filter(readTable('TeachReports'), function ($r) use ($me, $f, $teachers) {
    $dep = $teachers[$r['teacher_id']]['department_id'] ?? '';
    if ($me['role'] === 'dept' && (string)$dep !== (string)$me['department_id']) return false;
    if ($me['role'] === 'teacher' && (string)$r['teacher_id'] !== (string)$me['teacher_id']) return false;
    if (js_true($f['semester_id'] ?? '') && (string)$r['semester_id'] !== str($f['semester_id'])) return false;
    if (js_true($f['department_id'] ?? '') && (string)$dep !== str($f['department_id'])) return false;
    if (js_true($f['teacher_id'] ?? '') && (string)$r['teacher_id'] !== str($f['teacher_id'])) return false;
    if (js_true($f['status'] ?? '') && $r['status'] !== $f['status']) return false;
    if (js_true($f['from'] ?? '') && $r['date'] < $f['from']) return false;
    if (js_true($f['to'] ?? '') && $r['date'] > $f['to']) return false;
    return true;
  }));
  usort($rows, fn($a, $b) => [$b['date'], $a['start']] <=> [$a['date'], $b['start']]);
  return $rows;
}

function api_teachReportReview($token = '', $id = '', $action = '', $note = '') {
  $me = auth_user($token, 'dept');
  if (!in_array($action, ['approve', 'return'], true)) throw new Exception('คำสั่งไม่ถูกต้อง');
  $note = clean_text($note, 1000);
  if ($action === 'return' && $note === '') throw new Exception('กรุณาระบุเหตุผลที่ส่งกลับ');
  return with_lock(function () use ($me, $id, $action, $note) {
    $rows = readTable('TeachReports');
    $i = find_row($rows, str($id));
    if ($i === null) throw new Exception('ไม่พบรายงาน');
    assert_teacher_scope($me, $rows[$i]['teacher_id']);
    if ($rows[$i]['status'] !== 'submitted') throw new Exception('ตรวจได้เฉพาะรายงานที่ส่งแล้ว');
    $rows[$i]['status'] = $action === 'approve' ? 'approved' : 'returned';
    $rows[$i]['reviewed_by'] = $me['name']; $rows[$i]['reviewed_at'] = now_dt(); $rows[$i]['review_note'] = $note;
    writeTable('TeachReports', $rows);
    log_action($me, $action === 'approve' ? 'อนุมัติรายงานการสอน' : 'ส่งกลับรายงานการสอน', $id);
    return true;
  });
}

function api_teachReportDelete($token = '', $id = '') {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id) {
    $rows = readTable('TeachReports');
    $i = find_row($rows, str($id));
    if ($i === null) throw new Exception('ไม่พบรายงาน');
    assert_teacher_scope($me, $rows[$i]['teacher_id']);
    if ($rows[$i]['status'] === 'approved' && $me['role'] !== 'admin') throw new Exception('รายงานนี้อนุมัติแล้ว ลบไม่ได้');
    array_splice($rows, $i, 1);
    writeTable('TeachReports', $rows);
    log_action($me, 'ลบรายงานการสอน', $id);
    return true;
  });
}

/** สรุปการส่งรายงานรายครูในช่วงวันที่ */
function api_teachReportSummary($token = '', $semesterId = '', $deptId = '', $from = '', $to = '') {
  $me = auth_user($token, 'dept');
  if ($me['role'] === 'dept') $deptId = $me['department_id'];
  if (!valid_date($from) || !valid_date($to) || $from > $to) throw new Exception('ช่วงวันที่ไม่ถูกต้อง');
  if ((ot_parse($to)->getTimestamp() - ot_parse($from)->getTimestamp()) > 200 * 86400) throw new Exception('เลือกช่วงวันที่ได้ไม่เกิน 200 วัน');
  $out = [];
  foreach (readTable('Teachers') as $t) {
    if (js_true($deptId) && (string)$t['department_id'] !== str($deptId)) continue;
    $idx = teach_report_index($semesterId, (string)$t['id']);
    $c = ['expected' => 0, 'draft' => 0, 'submitted' => 0, 'approved' => 0, 'returned' => 0, 'missing' => 0];
    foreach (teacher_slots($semesterId, (string)$t['id'], $from, $to) as $s) {
      if ($s['replaced']) continue;
      $c['expected']++;
      $r = $idx[$s['date'] . '|' . $s['session_id'] . '|' . $s['start']] ?? null;
      if ($r) $c[$r['status']]++; else $c['missing']++;
    }
    if ($c['expected']) $out[] = ['teacher_id' => $t['id'], 'name' => $t['name'], 'department_id' => $t['department_id']] + $c;
  }
  return $out;
}

// ======================= นิเทศนักศึกษาฝึกงาน =======================
function intern_scope(array $me, array $in): void {
  if ($me['role'] === 'admin') return;
  if ($me['role'] === 'teacher') {
    if ((string)$in['supervisor_teacher_id'] === (string)$me['teacher_id']) return;
    throw new Exception('ดูได้เฉพาะนักศึกษาที่ครูเป็นผู้นิเทศ');
  }
  $g = idx_table('Groups')[$in['group_id']] ?? null;
  $t = idx_table('Teachers')[$in['supervisor_teacher_id']] ?? null;
  if (($g && (string)$g['department_id'] === (string)$me['department_id']) || ($t && (string)$t['department_id'] === (string)$me['department_id'])) return;
  throw new Exception('จัดการได้เฉพาะนักศึกษาของแผนกตัวเอง');
}

function intern_clean(array $me, array $r): array {
  $name = clean_text($r['student_name'] ?? '', 200);
  $company = clean_text($r['company_name'] ?? '', 300);
  if ($name === '') throw new Exception('กรุณาระบุชื่อนักศึกษา');
  if ($company === '') throw new Exception('กรุณาระบุสถานประกอบการ (' . $name . ')');
  foreach (['start_date', 'end_date'] as $f) if (js_true($r[$f] ?? '') && !valid_date($r[$f])) throw new Exception('วันที่ไม่ถูกต้อง (' . $name . ')');
  if (js_true($r['start_date'] ?? '') && js_true($r['end_date'] ?? '') && $r['start_date'] > $r['end_date']) throw new Exception('วันเริ่มฝึกต้องไม่หลังวันสิ้นสุด (' . $name . ')');
  $groups = idx_table('Groups');
  $gid = str($r['group_id'] ?? '');
  if ($gid !== '' && !isset($groups[$gid])) throw new Exception('ไม่พบกลุ่มเรียน (' . $name . ')');
  if ($me['role'] === 'dept' && ($gid === '' || (string)$groups[$gid]['department_id'] !== (string)$me['department_id'])) throw new Exception('เพิ่มได้เฉพาะกลุ่มเรียนของแผนกตัวเอง (' . $name . ')');
  $sup = str($r['supervisor_teacher_id'] ?? '');
  if ($sup !== '' && !isset(idx_table('Teachers')[$sup])) throw new Exception('ไม่พบครูนิเทศ (' . $name . ')');
  $st = $r['status'] ?? 'active';
  return [
    'student_code' => clean_text($r['student_code'] ?? '', 50), 'student_name' => $name, 'group_id' => $gid, 'company_name' => $company,
    'company_address' => clean_text($r['company_address'] ?? '', 500), 'company_phone' => clean_text($r['company_phone'] ?? '', 50), 'mentor_name' => clean_text($r['mentor_name'] ?? '', 200),
    'start_date' => $r['start_date'] ?? '', 'end_date' => $r['end_date'] ?? '', 'supervisor_teacher_id' => $sup,
    'status' => in_array($st, ['active', 'done', 'cancelled'], true) ? $st : 'active', 'note' => clean_text($r['note'] ?? '', 1000),
  ];
}

function api_internList($token = '', $semesterId = '') {
  $me = auth_user($token);
  $groups = idx_table('Groups'); $teachers = idx_table('Teachers');
  $counts = [];
  foreach (readTable('SupervisionVisits') as $v) {
    $c = &$counts[$v['internship_id']];
    $c ??= ['done' => 0, 'planned' => 0, 'last' => ''];
    if ($v['status'] === 'done') { $c['done']++; if ($v['visit_date'] > $c['last']) $c['last'] = $v['visit_date']; } else $c['planned']++;
    unset($c);
  }
  $out = [];
  foreach (readTable('Internships') as $in) {
    if (js_true($semesterId) && (string)$in['semester_id'] !== str($semesterId)) continue;
    if ($me['role'] === 'teacher' && (string)$in['supervisor_teacher_id'] !== (string)$me['teacher_id']) continue;
    if ($me['role'] === 'dept') {
      $gd = $groups[$in['group_id']]['department_id'] ?? ''; $td = $teachers[$in['supervisor_teacher_id']]['department_id'] ?? '';
      if ((string)$gd !== (string)$me['department_id'] && (string)$td !== (string)$me['department_id']) continue;
    }
    $c = $counts[$in['id']] ?? ['done' => 0, 'planned' => 0, 'last' => ''];
    $out[] = $in + ['visits_done' => $c['done'], 'visits_planned' => $c['planned'], 'last_visit' => $c['last']];
  }
  return $out;
}

function api_internSave($token = '', $rec = []) {
  $me = auth_user($token, 'dept');
  $rec = (array)$rec;
  return with_lock(function () use ($me, $rec) {
    $clean = intern_clean($me, $rec);
    $rows = readTable('Internships');
    $id = $rec['id'] ?? '';
    $i = js_true($id) ? find_row($rows, $id) : null;
    if ($i !== null) intern_scope($me, $rows[$i]); // ตรวจจากแถวเดิมก่อนเขียนทับ
    if ($i === null) {
      if (!js_true($rec['semester_id'] ?? '')) throw new Exception('กรุณาเลือกภาคเรียน');
      $rows[] = ['id' => new_id(), 'semester_id' => $rec['semester_id'], 'created_by' => $me['name'], 'created_at' => now_dt()];
      $i = count($rows) - 1;
    }
    $rows[$i] = array_merge($rows[$i], $clean);
    $newId = $rows[$i]['id'];
    writeTable('Internships', $rows);
    log_action($me, 'บันทึกนักศึกษาฝึกงาน', $newId);
    return $newId;
  });
}

/** เพิ่มหลายคนจากการวางข้อมูล ถ้ามีแถวผิดจะไม่บันทึกเลยทั้งชุด */
function api_internBulkSave($token = '', $semesterId = '', $list = []) {
  $me = auth_user($token, 'dept');
  if (!js_true($semesterId)) throw new Exception('กรุณาเลือกภาคเรียน');
  return with_lock(function () use ($me, $semesterId, $list) {
    $add = [];
    foreach ((array)$list as $r) $add[] = ['id' => new_id(), 'semester_id' => $semesterId, 'created_by' => $me['name'], 'created_at' => now_dt()] + intern_clean($me, (array)$r);
    appendRows('Internships', $add);
    log_action($me, 'นำเข้านักศึกษาฝึกงาน', count($add) . ' คน');
    return count($add);
  });
}

function api_internDelete($token = '', $id = '') {
  $me = auth_user($token, 'dept');
  return with_lock(function () use ($me, $id) {
    $rows = readTable('Internships');
    $i = find_row($rows, str($id));
    if ($i === null) throw new Exception('ไม่พบข้อมูล');
    intern_scope($me, $rows[$i]);
    writeTable('SupervisionVisits', array_values(array_filter(readTable('SupervisionVisits'), fn($v) => (string)$v['internship_id'] !== str($id))));
    array_splice($rows, $i, 1);
    writeTable('Internships', $rows);
    log_action($me, 'ลบนักศึกษาฝึกงาน', $id);
    return true;
  });
}

function intern_row($id): array {
  $rows = readTable('Internships');
  $i = find_row($rows, str($id));
  if ($i === null) throw new Exception('ไม่พบนักศึกษาฝึกงาน');
  return $rows[$i];
}

function api_visitList($token = '', $internshipId = '') {
  $me = auth_user($token);
  intern_scope($me, intern_row($internshipId));
  $rows = array_values(array_filter(readTable('SupervisionVisits'), fn($v) => (string)$v['internship_id'] === str($internshipId)));
  usort($rows, fn($a, $b) => strcmp($b['visit_date'], $a['visit_date']));
  return $rows;
}

function api_visitSave($token = '', $rec = []) {
  $me = auth_user($token);
  $rec = (array)$rec;
  $in = intern_row($rec['internship_id'] ?? '');
  intern_scope($me, $in);
  if (!valid_date($rec['visit_date'] ?? '')) throw new Exception('กรุณาระบุวันที่นิเทศ');
  if (js_true($rec['next_visit_date'] ?? '') && !valid_date($rec['next_visit_date'])) throw new Exception('วันที่นิเทศครั้งถัดไปไม่ถูกต้อง');
  $type = ($rec['visit_type'] ?? '') === 'onsite' ? 'onsite' : 'online';
  $status = ($rec['status'] ?? '') === 'planned' ? 'planned' : 'done';
  if ($status === 'done' && $rec['visit_date'] > date('Y-m-d')) throw new Exception('บันทึกผลนิเทศล่วงหน้าไม่ได้ ถ้ายังไม่ถึงวันให้เลือก "นัดหมาย"');
  $score = num($rec['score'] ?? 0);
  if ($score < 0 || $score > 5) throw new Exception('คะแนนต้องอยู่ระหว่าง 0–5');
  $meet = clean_link($rec['meeting_link'] ?? ''); $evid = clean_link($rec['evidence_link'] ?? '');
  if ($status === 'done' && clean_text($rec['findings'] ?? '') === '') throw new Exception('กรุณาบันทึกผลการนิเทศ');
  return with_lock(function () use ($me, $rec, $type, $status, $score, $meet, $evid) {
    $rows = readTable('SupervisionVisits');
    $id = $rec['id'] ?? '';
    $i = js_true($id) ? find_row($rows, $id) : null;
    if ($i !== null && (string)$rows[$i]['internship_id'] !== str($rec['internship_id'])) throw new Exception('รายการนิเทศไม่ตรงกับนักศึกษา');
    if ($i === null) { $rows[] = ['id' => new_id(), 'created_by' => $me['name'], 'created_at' => now_dt()]; $i = count($rows) - 1; }
    $rows[$i] = array_merge($rows[$i], [
      'internship_id' => $rec['internship_id'], 'visit_date' => $rec['visit_date'], 'visit_type' => $type, 'meeting_link' => $meet, 'topic' => clean_text($rec['topic'] ?? '', 500),
      'findings' => clean_text($rec['findings'] ?? ''), 'suggestions' => clean_text($rec['suggestions'] ?? ''), 'score' => $score, 'evidence_link' => $evid,
      'next_visit_date' => $rec['next_visit_date'] ?? '', 'status' => $status,
    ]);
    $newId = $rows[$i]['id'];
    writeTable('SupervisionVisits', $rows);
    log_action($me, 'บันทึกการนิเทศ', $newId);
    return $newId;
  });
}

function api_visitDelete($token = '', $id = '') {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id) {
    $rows = readTable('SupervisionVisits');
    $i = find_row($rows, str($id));
    if ($i === null) throw new Exception('ไม่พบรายการนิเทศ');
    intern_scope($me, intern_row($rows[$i]['internship_id']));
    array_splice($rows, $i, 1);
    writeTable('SupervisionVisits', $rows);
    log_action($me, 'ลบรายการนิเทศ', $id);
    return true;
  });
}

// ======================= คิว/ช่วงเวลาจัดตาราง =======================
/** คิวของภาคเรียน เรียงตามลำดับคิว (แถวที่ยังไม่มีลำดับต่อท้ายตามลำดับเดิม) */
function windows_of($semesterId): array {
  $rows = array_values(array_filter(readTable('Windows'), fn($w) => (string)$w['semester_id'] === str($semesterId)));
  $i = 0;
  $keyed = array_map(function ($w) use (&$i) { return ['w' => $w, 's' => $w['sort'] === '' ? PHP_INT_MAX : num($w['sort']), 'i' => $i++]; }, $rows);
  usort($keyed, fn($a, $b) => [$a['s'], $a['i']] <=> [$b['s'], $b['i']]);
  return array_map(fn($k) => $k['w'], $keyed);
}

function api_windowList($token = '', $semesterId = '') {
  auth_user($token, 'admin');
  return windows_of($semesterId);
}

/** เพิ่มคิวให้แผนกที่ยังไม่มีในภาคเรียนนี้ โดยยังไม่กำหนดวันที่ */
function api_windowCreateMissing($token = '', $semesterId = '') {
  $me = auth_user($token, 'admin');
  if (find_row(readTable('Semesters'), str($semesterId)) === null) throw new Exception('ไม่พบภาคเรียน');
  return with_lock(function () use ($me, $semesterId) {
    $cur = windows_of($semesterId);
    $has = array_column($cur, 'department_id');
    $depts = readTable('Departments');
    usort($depts, fn($a, $b) => [num($a['sort']), $a['name']] <=> [num($b['sort']), $b['name']]);
    $n = count($cur); $add = [];
    foreach ($depts as $d) {
      if (in_array($d['id'], $has, true)) continue;
      $add[] = ['id' => new_id(), 'semester_id' => $semesterId, 'department_id' => $d['id'], 'start_date' => '', 'end_date' => '', 'sort' => ++$n];
    }
    appendRows('Windows', $add);
    log_action($me, 'สร้างคิวจัดตาราง', count($add) . ' แผนก');
    return count($add);
  });
}

/** เลื่อนลำดับคิวขึ้น (-1) หรือลง (+1) */
function api_windowMove($token = '', $id = '', $dir = 0) {
  $me = auth_user($token, 'admin');
  $dir = num($dir) < 0 ? -1 : 1;
  return with_lock(function () use ($me, $id, $dir) {
    $all = readTable('Windows');
    $i = find_row($all, str($id));
    if ($i === null) throw new Exception('ไม่พบคิว');
    $list = windows_of($all[$i]['semester_id']);
    $pos = find_row($list, str($id));
    $to = $pos + $dir;
    if ($to < 0 || $to >= count($list)) return false;
    [$list[$pos], $list[$to]] = [$list[$to], $list[$pos]];
    $order = [];
    foreach ($list as $n => $w) $order[$w['id']] = $n + 1;
    foreach ($all as $k => $w) if (isset($order[$w['id']])) $all[$k]['sort'] = $order[$w['id']];
    writeTable('Windows', $all);
    log_action($me, 'เลื่อนคิวจัดตาราง', $id);
    return true;
  });
}

function api_windowSave($token = '', $id = '', $start = '', $end = '') {
  $me = auth_user($token, 'admin');
  foreach ([$start, $end] as $d) if ($d !== '' && !valid_date($d)) throw new Exception('วันที่ไม่ถูกต้อง');
  if ($start !== '' && $end !== '' && $start > $end) throw new Exception('วันเริ่มต้องไม่หลังวันสิ้นสุด');
  return with_lock(function () use ($me, $id, $start, $end) {
    $all = readTable('Windows');
    $i = find_row($all, str($id));
    if ($i === null) throw new Exception('ไม่พบคิว');
    $all[$i]['start_date'] = $start; $all[$i]['end_date'] = $end;
    writeTable('Windows', $all);
    log_action($me, 'บันทึกช่วงเวลาคิว', $id . ' ' . $start . ' - ' . $end);
    return true;
  });
}

/** เปิดคิวตั้งแต่วันนี้ ถ้าวันสิ้นสุดผ่านไปแล้วหรือยังไม่กำหนด จะเปิดถึงสิ้นวันนี้ */
function api_windowOpenNow($token = '', $id = '') {
  $me = auth_user($token, 'admin');
  return with_lock(function () use ($me, $id) {
    $all = readTable('Windows');
    $i = find_row($all, str($id));
    if ($i === null) throw new Exception('ไม่พบคิว');
    $today = date('Y-m-d');
    $all[$i]['start_date'] = $today;
    if ($all[$i]['end_date'] === '' || $all[$i]['end_date'] < $today) $all[$i]['end_date'] = $today;
    writeTable('Windows', $all);
    log_action($me, 'เปิดคิวก่อนกำหนด', $id);
    return true;
  });
}

function api_windowDelete($token = '', $id = '') {
  $me = auth_user($token, 'admin');
  return with_lock(function () use ($me, $id) {
    $all = readTable('Windows');
    $i = find_row($all, str($id));
    if ($i === null) throw new Exception('ไม่พบคิว');
    array_splice($all, $i, 1);
    writeTable('Windows', $all);
    log_action($me, 'ลบคิวจัดตาราง', $id);
    return true;
  });
}

// ======================= งบหน้าแผนกวิชา =======================
/** งบหน้าค่าสอนเกินภาระงาน: นับเฉพาะใบเบิกที่ล็อกแล้ว แยกยอดรายสัปดาห์ */
function api_otCoverSheet($token = '', $roundId = '', $deptId = '') {
  $me = auth_user($token, 'dept');
  if ($me['role'] === 'dept' && str($deptId) !== (string)$me['department_id']) throw new Exception('ดูงบหน้าได้เฉพาะแผนกตัวเอง');
  $round = idx_table('OtRounds')[str($roundId)] ?? null;
  if (!$round) throw new Exception('ไม่พบรอบการเบิก');
  $sum = ot_dept_summary($roundId, $deptId);
  $weeks = array_map(fn($w) => ['index' => $w['index'], 'from' => $w['dates'][0], 'to' => $w['dates'][count($w['dates']) - 1]], ot_weeks_of($round));
  $claims = [];
  foreach (readTable('OtClaims') as $c) if ((string)$c['round_id'] === str($roundId)) $claims[$c['teacher_id']] ??= $c;
  $rows = []; $totals = ['hours' => 0, 'amount' => 0, 'vc' => 0, 'vs' => 0, 'bd' => 0, 'perHeadAmount' => 0];
  $weekTotals = array_fill_keys(array_column($weeks, 'index'), 0);
  foreach ($sum['rows'] as $r) {
    if ($r['status'] !== 'locked') continue;
    $sm = json_decode($claims[$r['teacher_id']]['summary'] ?? '{}', true) ?: [];
    $wk = [];
    foreach ($weeks as $w) {
      $a = $sm['weeks'][$w['index']]['amount'] ?? 0;
      $wk[$w['index']] = $a;
      $weekTotals[$w['index']] += $a;
    }
    $r['week_amounts'] = (object)$wk;
    $rows[] = $r;
    foreach ($totals as $k => $_) $totals[$k] += $r[$k];
  }
  return ['round' => $round, 'weeks' => $weeks, 'rows' => $rows, 'totals' => $totals, 'weekTotals' => (object)$weekTotals, 'totalText' => ot_baht_text($totals['amount']), 'notLocked' => $sum['notLocked'], 'submission' => $sum['submission']];
}
