<?php
/** API ค่าสอนเกินภาระงาน */

function assert_ot_teacher(array $me, $teacherId): void {
  if (is_academic_manager($me)) return;
  $t = idx_table('Teachers')[str($teacherId)] ?? null;
  if ($me['role'] === 'dept' && $t && (string)$t['department_id'] === (string)$me['department_id']) return;
  if ($me['role'] === 'teacher' && (string)$me['teacher_id'] === str($teacherId)) throw new Exception('ครูดูใบเบิกของตัวเองได้ แต่แก้ไขต้องให้ตัวแทนแผนก');
  throw new Exception('จัดการได้เฉพาะครูในแผนกของตัวเอง');
}

/** สอนแทน/สอนชดเชย: ครูทำรายการได้เฉพาะคาบของตนเอง จึงเพิ่มชั่วโมงเบิกให้ตัวเองจากคาบของครูอื่นไม่ได้ */
function assert_ot_sub_owner(array $me, $originalTeacherId): void {
  if ($me['role'] !== 'teacher') { assert_ot_teacher($me, $originalTeacherId); return; }
  if (!js_true($me['teacher_id'] ?? '') || (string)$me['teacher_id'] !== str($originalTeacherId)) throw new Exception('ครูทำรายการได้เฉพาะคาบสอนของตนเอง');
}

/** ใบเบิกที่ล็อกแล้วอ้างอิงรายการนี้อยู่ ครูจึงแก้ไม่ได้ ต้องให้วิชาการแผนกหรือผู้ดูแลจัดการ */
function assert_ot_sub_claims_open(array $me, $roundId, array $teacherIds): void {
  if ($me['role'] !== 'teacher') return;
  foreach ($teacherIds as $tid) assert_claim_open($roundId, $tid);
}

function ot_claim_row($roundId, $teacherId): ?array {
  foreach (readTable('OtClaims') as $x) if ((string)$x['round_id'] === str($roundId) && (string)$x['teacher_id'] === str($teacherId)) return $x;
  return null;
}

function assert_claim_open($roundId, $teacherId): void {
  $c = ot_claim_row($roundId, $teacherId);
  if ($c && $c['status'] !== 'draft' && $c['status'] !== '') throw new Exception('ใบเบิกนี้ล็อกแล้ว — ผู้ดูแลต้องปลดล็อกก่อน');
}

function ot_ctx(array $round): array {
  return [
    'settings' => readSettings(),
    'sessions' => sem_sessions($round['semester_id']),
    'groups' => idx_table('Groups'), 'courses' => idx_table('Courses'),
    'absences' => array_values(array_filter(readTable('OtAbsences'), fn($a) => (string)$a['round_id'] === (string)$round['id'])),
    'blocked' => array_values(array_filter(readTable('OtBlocked'), fn($b) => $b['round_id'] === '' || (string)$b['round_id'] === (string)$round['id'])),
    'subs' => ot_subs_with_reports(array_values(array_filter(readTable('OtSubs'), fn($r) => (string)$r['round_id'] === (string)$round['id']))),
    'perHead' => readTable('OtPerHead'),
    'schemes' => array_map('ot_scheme', readTable('OtSchemes')),
  ];
}

function ot_teacher_schemes(array $teacher, array $C): array {
  $ids = sched_ids($teacher['ot_scheme_ids'] ?? '');
  return array_values(array_filter($C['schemes'], fn($s) => in_array((string)$s['id'], $ids, true)));
}

/** สิทธิ์คงเหลือของครูผู้สอนเดิม สำหรับรายการสอนแทนในสัปดาห์นั้น */
function ot_sub_quota(array $round, array $C, int $weekIndex, array $weekDates): array {
  $q = [];
  $teachers = idx_table('Teachers');
  foreach ($C['subs'] as $r) {
    if ($r['type'] !== 'sub' || !in_array(substr((string)$r['date'], 0, 10), $weekDates, true)) continue;
    $orig = $teachers[$r['original_teacher_id']] ?? null;
    if (!$orig) continue;
    $cap = ot_personal_cap($orig, $C['settings'], $round['start_date'])['cap'];
    $oc = ot_claim_row($round['id'], $orig['id']);
    $used = 0;
    if ($oc && js_true($oc['summary'])) {
      $sm = json_decode($oc['summary'], true);
      $used = $sm['weeks'][$weekIndex]['claimHours'] ?? 0;
    }
    $schemes = ot_teacher_schemes($orig, $C);
    if ($schemes) $cap = min($cap, max(array_map(fn($s) => $s['total_cap'] ?: $cap, $schemes)));
    $q[$r['id']] = max(0, min(num($r['length']), $cap - $used));
  }
  return $q;
}

/** ยอดอ้างอิงแบบวันหยุด = แบบ ปวช. ระดับเดียวที่ครูมี × เพดาน */
function ot_ref_amount(array $teacher, array $C, $cap) {
  foreach (ot_teacher_schemes($teacher, $C) as $s) {
    if ($s['levels']['vc']['enabled'] && !$s['levels']['vs']['enabled'] && !$s['levels']['bd']['enabled']) {
      return min($s['total_cap'] ?: $cap, $cap, $s['levels']['vc']['max'] ?: $cap) * $s['levels']['vc']['rate'];
    }
  }
  return 0;
}

/** ข้อมูลทั้งหมดสำหรับหน้าเลือกชั่วโมงเบิกของครู 1 คน */
function ot_teacher_data(array $me, $roundId, $teacherId): array {
  if ($me['role'] === 'teacher' && (string)$me['teacher_id'] !== str($teacherId)) throw new Exception('ดูได้เฉพาะใบเบิกของตัวเอง');
  $teachers = idx_table('Teachers');
  $t = $teachers[str($teacherId)] ?? null;
  if ($me['role'] === 'dept' && $t && (string)$t['department_id'] !== (string)$me['department_id']) throw new Exception('ดูได้เฉพาะใบเบิกของครูในแผนกตัวเอง');
  $round = idx_table('OtRounds')[str($roundId)] ?? null;
  if (!$round) throw new Exception('ไม่พบรอบการเบิก');
  if (!$t) throw new Exception('ไม่พบครู');
  $C = ot_ctx($round);
  $weeks = ot_hours_for(['settings' => $C['settings'], 'teacher' => $t, 'round' => $round, 'sessions' => $C['sessions'], 'groups' => $C['groups'], 'courses' => $C['courses'], 'absences' => $C['absences'], 'blocked' => $C['blocked'], 'subs' => $C['subs']]);
  foreach ($weeks as &$w) $w['subQuota'] = ot_sub_quota($round, $C, $w['index'], $w['dates']);
  unset($w);
  $row = ot_claim_row($roundId, $teacherId);
  $pc = ot_personal_cap($t, $C['settings'], $round['start_date']);
  $tid = str($teacherId);
  return [
    'round' => $round, 'teacher' => $t, 'weeks' => $weeks, 'cap' => $pc['cap'], 'capNote' => $pc['note'],
    'schemes' => ot_teacher_schemes($t, $C), 'allSchemes' => $C['schemes'], 'perHead' => $C['perHead'],
    'claim' => $row ? ['id' => $row['id'], 'scheme_id' => $row['scheme_id'], 'weeks' => js_true($row['weeks']) ? json_decode($row['weeks']) : new stdClass(), 'status' => js_true($row['status']) ? $row['status'] : 'draft', 'locked_by' => $row['locked_by'], 'locked_at' => $row['locked_at']] : null,
    'absences' => array_values(array_filter($C['absences'], fn($a) => (string)$a['teacher_id'] === $tid || $a['teacher_id'] === '*')),
    'subs' => array_values(array_filter($C['subs'], fn($r) => (string)$r['teacher_id'] === $tid || (string)$r['original_teacher_id'] === $tid)),
    'refAmount' => ot_ref_amount($t, $C, $pc['cap']),
  ];
}

function api_otTeacherData($token = '', $roundId = '', $teacherId = '') {
  $me = auth_user($token);
  $d = ot_teacher_data($me, $roundId, $teacherId);
  foreach ($d['weeks'] as &$w) $w['subQuota'] = (object)$w['subQuota'];
  unset($w);
  return $d;
}

/** คำนวณสรุปทั้งรอบ (ใช้ทั้งตอนบันทึกและตอนล็อก) */
function ot_evaluate(array $data, $schemeId, array $weeksSel): array {
  $sch = null;
  foreach ($data['allSchemes'] as $s) if ((string)$s['id'] === str($schemeId)) { $sch = $s; break; }
  if (!$sch) throw new Exception('เลือกแบบการเบิกก่อน');
  $out = ['weeks' => [], 'hours' => 0, 'amount' => 0, 'errors' => [], 'byLevel' => ['vc' => 0, 'vs' => 0, 'bd' => 0], 'perHeadAmount' => 0];
  $courses = idx_table('Courses');
  foreach ($data['weeks'] as $w) {
    $ws = (array)($weeksSel[$w['index']] ?? []);
    $r = ot_eval_week($w['items'], (array)($ws['sel'] ?? []), (array)($ws['ph'] ?? []), $sch, $data['cap'], ['subQuota' => $w['subQuota'], 'perHead' => $data['perHead'], 'courses' => $courses, 'refAmount' => $data['refAmount']]);
    $out['weeks'][$w['index']] = ['claimHours' => $r['claimHours'], 'amount' => $r['amount'], 'paid' => $r['paid'], 'levels' => $r['levels'], 'errors' => $r['errors'], 'warnings' => $r['warnings']];
    $out['hours'] += $r['claimHours']; $out['amount'] += $r['amount']; $out['perHeadAmount'] += $r['perHeadAmount'];
    foreach (OT_LEVELS as $l) $out['byLevel'][$l] += $r['paid'][$l] ?? 0;
    foreach ($r['errors'] as $e) $out['errors'][] = 'สัปดาห์ที่ ' . $w['index'] . ': ' . $e;
  }
  return $out;
}

/** แปลงค่าที่เลือกเป็น JSON โดยคงรูปแบบ {} ไว้แม้ไม่มีข้อมูล */
function ot_weeks_json(array $weeks): string {
  if (!$weeks) return '{}';
  $o = new stdClass();
  foreach ($weeks as $k => $w) {
    $w = (array)$w;
    foreach (['sel', 'ph'] as $f) if (array_key_exists($f, $w)) $w[$f] = (object)(array)$w[$f];
    $o->{(string)$k} = (object)$w;
  }
  return json_encode($o, JSON_UNESCAPED_UNICODE);
}

function api_otSaveClaim($token = '', $roundId = '', $teacherId = '', $payload = [], $lock = false) {
  $me = auth_user($token, 'dept');
  assert_ot_teacher($me, $teacherId);
  $payload = (array)$payload;
  return with_lock(function () use ($me, $roundId, $teacherId, $payload, $lock) {
    $data = ot_teacher_data($me, $roundId, $teacherId);
    if ($data['claim'] && $data['claim']['status'] !== 'draft') throw new Exception('ใบเบิกนี้ล็อกแล้ว');
    $weeks = (array)($payload['weeks'] ?? []);
    $ev = ot_evaluate($data, $payload['scheme_id'] ?? '', $weeks);
    if (js_true($lock) && $ev['errors']) return ['ok' => false, 'errors' => $ev['errors'], 'summary' => $ev];
    $rows = readTable('OtClaims');
    $i = null;
    foreach ($rows as $k => $x) if ((string)$x['round_id'] === str($roundId) && (string)$x['teacher_id'] === str($teacherId)) { $i = $k; break; }
    if ($i === null) { $rows[] = ['id' => new_id(), 'round_id' => $roundId, 'teacher_id' => $teacherId]; $i = count($rows) - 1; }
    $rows[$i] = array_merge($rows[$i], [
      'scheme_id' => $payload['scheme_id'], 'weeks' => ot_weeks_json($weeks), 'hours' => $ev['hours'], 'amount' => $ev['amount'],
      'summary' => json_encode($ev, JSON_UNESCAPED_UNICODE), 'updated_by' => $me['name'], 'updated_at' => now_dt(), 'status' => js_true($lock) ? 'locked' : 'draft',
    ]);
    if (js_true($lock)) { $rows[$i]['locked_by'] = $me['name']; $rows[$i]['locked_at'] = now_dt(); }
    writeTable('OtClaims', $rows);
    log_action($me, js_true($lock) ? 'ล็อกใบเบิก' : 'บันทึกใบเบิก', ($data['teacher']['name'] ?: $teacherId) . ' · ' . $ev['hours'] . ' ชม. · ' . $ev['amount'] . ' บาท');
    return ['ok' => true, 'errors' => $ev['errors'], 'summary' => $ev];
  });
}

function api_otUnlockClaim($token = '', $roundId = '', $teacherId = '') {
  $me = auth_user($token, 'curriculum');
  return with_lock(function () use ($me, $roundId, $teacherId) {
    $t = idx_table('Teachers')[str($teacherId)] ?? null;
    foreach (readTable('OtSubmissions') as $s) {
      if ((string)$s['round_id'] === str($roundId) && $t && (string)$s['department_id'] === (string)$t['department_id'] && $s['status'] === 'submitted') {
        throw new Exception('ใบเบิกอยู่ในใบสรุปที่เสนอเบิกแล้ว — ยกเลิกการเสนอเบิกก่อน');
      }
    }
    $rows = readTable('OtClaims');
    foreach ($rows as $k => $x) {
      if ((string)$x['round_id'] === str($roundId) && (string)$x['teacher_id'] === str($teacherId)) { $rows[$k]['status'] = 'draft'; writeTable('OtClaims', $rows); break; }
    }
    log_action($me, 'ปลดล็อกใบเบิก', $teacherId);
    return true;
  });
}

// ---------------- สอนแทน / สอนชดเชย (ระเบียบข้อ 8) ----------------
/**
 * - วิชาฝึกงาน/ทวิภาคี ห้ามสอนชดเชย/สอนแทน (ข้อ 6)
 * - สอนแทน: ชั่วโมงต้องไม่เกินสิทธิ์คงเหลือของครูผู้สอนเดิม และรวมกับชั่วโมงเบิกของผู้สอนแทนไม่เกินเพดาน
 * - สอนชดเชยกรณีไปราชการ: ต้องแนบรายงานผลการสอนภายใน 7 วัน
 */
function api_otSaveSub($token = '', $rec = []) {
  $me = auth_user($token);
  $rec = (array)$rec;
  $recId = $rec['id'] ?? '';
  if (js_true($recId)) {
    $all = readTable('OtSubs');
    $i0 = find_row($all, $recId);
    if ($i0 !== null) {
      assert_ot_sub_owner($me, $all[$i0]['original_teacher_id']); // ตรวจจากแถวเดิมก่อนเขียนทับ
      assert_ot_sub_claims_open($me, $all[$i0]['round_id'], [$all[$i0]['original_teacher_id'], $all[$i0]['teacher_id']]);
    }
  }
  $origId = $rec['original_teacher_id'] ?? '';
  assert_ot_sub_owner($me, $origId);
  return with_lock(function () use ($me, $rec, $recId, $origId) {
    $round = idx_table('OtRounds')[str($rec['round_id'] ?? '')] ?? null;
    if (!$round) throw new Exception('ไม่พบรอบการเบิก');
    $s = null;
    foreach (readTable('Sessions') as $x) if ((string)$x['id'] === str($rec['session_id'] ?? '')) { $s = $x; break; }
    if (!$s) throw new Exception('เลือกคาบที่ต้องการสอนแทน/ชดเชย');
    $ctx = sched_ctx_now();
    if (sched_is_workplace($s, $ctx)) throw new Exception('วิชาที่จัดในช่วงฝึกงาน/ทวิภาคี ไม่อนุญาตให้สอนชดเชยหรือสอนแทน (ระเบียบข้อ 6)');
    if (!in_array(str($origId), sched_ids($s['teacher_ids']), true)) throw new Exception('ครูผู้สอนเดิมไม่ได้สอนคาบนี้');
    $od = substr(str(js_true($rec['original_date'] ?? '') ? $rec['original_date'] : ($rec['date'] ?? '')), 0, 10);
    if (ot_dow(ot_parse($od)) !== num($s['day'])) throw new Exception('วันที่ของคาบเดิมไม่ตรงกับวันในตาราง (' . (DAY_NAMES[num($s['day'])] ?? '') . ')');
    if ($od < $round['start_date'] || $od > $round['end_date']) throw new Exception('วันที่อยู่นอกรอบการเบิก');
    $type = ($rec['type'] ?? '') === 'makeup' ? 'makeup' : 'sub';
    $teacherId = $type === 'makeup' ? $origId : ($rec['teacher_id'] ?? '');
    if (!js_true($teacherId)) throw new Exception('เลือกครูผู้สอนแทน');
    if ($type === 'sub' && str($teacherId) === str($origId)) throw new Exception('ครูผู้สอนแทนต้องไม่ใช่คนเดิม');
    assert_ot_sub_claims_open($me, $round['id'], [$origId, $teacherId]);
    $date = substr(str($type === 'makeup' ? ($rec['date'] ?? '') : $od), 0, 10);
    $start = $type === 'makeup' ? num($rec['start'] ?? 0) : num(js_true($rec['start'] ?? '') ? $rec['start'] : $s['start']);
    $length = num(js_true($rec['length'] ?? '') ? $rec['length'] : $s['length']);
    if ($length > num($s['length'])) throw new Exception('จำนวนคาบเกินคาบเดิม');
    $warnings = [];
    if ($type === 'sub') {
      // ตรวจเพดานคงเหลือทั้งสองฝ่าย (Quota Check)
      $C = ot_ctx($round);
      $teachers = idx_table('Teachers');
      $orig = $teachers[str($origId)] ?? null; $subT = $teachers[str($teacherId)] ?? null;
      if (!$orig || !$subT) throw new Exception('ไม่พบครู');
      $w = null;
      foreach (ot_weeks_of($round) as $x) if (in_array($date, $x['dates'], true)) { $w = $x; break; }
      $origCap = ot_personal_cap($orig, $C['settings'], $round['start_date'])['cap'];
      $subCap = ot_personal_cap($subT, $C['settings'], $round['start_date'])['cap'];
      $usedIn = function ($tid) use ($round, $w) {
        $c = ot_claim_row($round['id'], $tid);
        if (!$c || !js_true($c['summary'])) return 0;
        $sm = json_decode($c['summary'], true);
        return $sm['weeks'][$w['index']]['claimHours'] ?? 0;
      };
      $otherSubs = 0;
      foreach ($C['subs'] as $r) {
        if ($r['id'] !== $recId && $r['type'] === 'sub' && (string)$r['teacher_id'] === str($teacherId) && in_array(substr((string)$r['date'], 0, 10), $w['dates'], true) && $r['status'] !== 'cancelled') $otherSubs += num($r['length']);
      }
      $origLeft = $origCap - $usedIn($orig['id']); $subLeft = $subCap - $usedIn($subT['id']) - $otherSubs;
      if ($length > $origLeft) $warnings[] = 'สิทธิ์คงเหลือของครูผู้สอนเดิมในสัปดาห์นี้ ' . max(0, $origLeft) . ' ชม. — เบิกสอนแทนได้ไม่เกินนี้';
      // ครูไม่ควรเห็นตัวเลขสิทธิ์เบิกของครูคนอื่น
      if ($length > $subLeft) $warnings[] = $me['role'] === 'teacher' ? 'ชั่วโมงนี้เกินสิทธิ์คงเหลือของครูผู้สอนแทน กรุณาตรวจสอบกับวิชาการแผนก' : 'ครูผู้สอนแทนมีสิทธิ์คงเหลือ ' . max(0, $subLeft) . ' ชม. (รวมแล้วต้องไม่เกิน ' . $subCap . ' ชม./สัปดาห์)';
    }
    $days = num(readSettings()['ot_makeup_report_days'] ?? 7) ?: 7;
    $due = '';
    if ($type === 'makeup') {
      $due = ot_ymd(ot_parse($date)->modify('+' . $days . ' day'));
      if ($date < $od) throw new Exception('วันสอนชดเชยต้องอยู่หลังวันที่ขาดสอน');
    }
    $rows = readTable('OtSubs');
    $i = js_true($recId) ? find_row($rows, $recId) : null;
    if ($i === null) { $rows[] = ['id' => new_id(), 'created_by' => $me['name'], 'created_at' => now_dt(), 'report_done' => false, 'status' => 'active']; $i = count($rows) - 1; }
    $rows[$i] = array_merge($rows[$i], [
      'round_id' => $rec['round_id'], 'type' => $type, 'reason' => $rec['reason'] ?? '', 'original_teacher_id' => $origId, 'teacher_id' => $teacherId,
      'session_id' => $s['id'], 'group_id' => $s['group_id'], 'course_id' => $s['course_id'], 'original_date' => $od, 'date' => $date,
      'start' => $start, 'length' => $length, 'report_due' => $due, 'note' => $rec['note'] ?? '',
    ]);
    $newId = $rows[$i]['id'];
    writeTable('OtSubs', $rows);
    log_action($me, $type === 'sub' ? 'บันทึกสอนแทน' : 'บันทึกสอนชดเชย', $newId);
    return ['ok' => true, 'warnings' => $warnings, 'id' => $newId];
  });
}

function api_otSubReport($token = '', $id = '', $link = '', $done = true) {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id, $link, $done) {
    $rows = readTable('OtSubs');
    $i = find_row($rows, str($id));
    if ($i === null) throw new Exception('ไม่พบรายการ');
    assert_ot_sub_owner($me, $rows[$i]['original_teacher_id']);
    $rows[$i]['report_link'] = js_true($link) ? $link : $rows[$i]['report_link'];
    $rows[$i]['report_done'] = $done !== false;
    writeTable('OtSubs', $rows);
    return true;
  });
}

function api_otCancelSub($token = '', $id = '') {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id) {
    $rows = readTable('OtSubs');
    $i = find_row($rows, str($id));
    if ($i === null) throw new Exception('ไม่พบรายการ');
    assert_ot_sub_owner($me, $rows[$i]['original_teacher_id']);
    assert_ot_sub_claims_open($me, $rows[$i]['round_id'], [$rows[$i]['original_teacher_id'], $rows[$i]['teacher_id']]);
    $rows[$i]['status'] = 'cancelled';
    writeTable('OtSubs', $rows);
    return true;
  });
}

function api_otListSubs($token = '', $roundId = '') {
  $me = auth_user($token);
  $t = idx_table('Teachers');
  return ot_subs_with_reports(array_values(array_filter(readTable('OtSubs'), function ($r) use ($me, $t, $roundId) {
    if ((string)$r['round_id'] !== str($roundId)) return false;
    if (is_academic_manager($me)) return true;
    if ($me['role'] === 'dept') return isset($t[$r['original_teacher_id']]) && (string)$t[$r['original_teacher_id']]['department_id'] === (string)$me['department_id'];
    return (string)$r['teacher_id'] === (string)$me['teacher_id'] || (string)$r['original_teacher_id'] === (string)$me['teacher_id'];
  })));
}

/** การแจ้งเตือน: สอนชดเชยที่ยังไม่แนบรายงาน / เลยกำหนด */
function api_otAlerts($token = '') {
  $me = auth_user($token);
  $today = date('Y-m-d');
  $t = idx_table('Teachers');
  $courses = idx_table('Courses'); $groups = idx_table('Groups');
  $out = [];
  foreach (ot_subs_with_reports(readTable('OtSubs')) as $r) {
    if ($r['type'] !== 'makeup' || (string)$r['report_done'] === 'true' || in_array($r['status'], ['cancelled', 'deleted'], true)) continue;
    if ($me['role'] === 'dept' && !(isset($t[$r['original_teacher_id']]) && (string)$t[$r['original_teacher_id']]['department_id'] === (string)$me['department_id'])) continue;
    if ($me['role'] === 'teacher' && (string)$r['original_teacher_id'] !== (string)$me['teacher_id']) continue;
    $out[] = ['id' => $r['id'], 'teacher' => $t[$r['original_teacher_id']]['name'] ?? null, 'course' => trim(($courses[$r['course_id']]['code'] ?? '') . ' ' . ($groups[$r['group_id']]['code'] ?? '')), 'round_id' => $r['round_id'], 'date' => $r['date'], 'due' => $r['report_due'], 'overdue' => $r['report_due'] !== '' ? $today > $r['report_due'] : ''];
  }
  return $out;
}

// ---------------- สรุปเสนอเบิกรายแผนก ----------------
function ot_dept_summary($roundId, $deptId): array {
  $teachers = array_filter(readTable('Teachers'), fn($t) => (string)$t['department_id'] === str($deptId) && (string)$t['ot_enabled'] === 'true');
  $claims = [];
  foreach (readTable('OtClaims') as $c) if ((string)$c['round_id'] === str($roundId) && !isset($claims[$c['teacher_id']])) $claims[$c['teacher_id']] = $c;
  $schemes = idx_table('OtSchemes');
  $rows = [];
  foreach ($teachers as $t) {
    $c = $claims[$t['id']] ?? null;
    $sm = ($c && js_true($c['summary'])) ? json_decode($c['summary'], true) : null;
    $rows[] = [
      'teacher_id' => $t['id'], 'name' => $t['name'], 'position' => $t['position'], 'scheme' => $c ? ($schemes[$c['scheme_id']]['name'] ?? null) : '',
      'status' => $c ? $c['status'] : 'none', 'vc' => $sm['byLevel']['vc'] ?? 0, 'vs' => $sm['byLevel']['vs'] ?? 0, 'bd' => $sm['byLevel']['bd'] ?? 0,
      'perHeadAmount' => $sm['perHeadAmount'] ?? 0, 'hours' => $c ? num($c['hours']) : 0, 'amount' => $c ? num($c['amount']) : 0,
    ];
  }
  $total = 0; $notLocked = [];
  foreach ($rows as $r) { if ($r['status'] === 'locked') $total += $r['amount']; else $notLocked[] = $r['name']; }
  $subm = null;
  foreach (readTable('OtSubmissions') as $s) {
    if ((string)$s['round_id'] === str($roundId) && (string)$s['department_id'] === str($deptId) && $s['status'] === 'submitted') { $subm = $s; break; }
  }
  return ['rows' => $rows, 'total' => $total, 'totalText' => ot_baht_text($total), 'notLocked' => $notLocked, 'submission' => $subm];
}

function api_otDeptSummary($token = '', $roundId = '', $deptId = '') {
  $me = auth_user($token, 'dept');
  if ($me['role'] === 'dept' && str($deptId) !== (string)$me['department_id']) throw new Exception('ดูสรุปได้เฉพาะแผนกตัวเอง');
  if (str($deptId) === '__all') {
    $departments = idx_table('Departments');
    foreach (readTable('Teachers') as $t) {
      $id = (string)$t['department_id'];
      if ((string)$t['ot_enabled'] === 'true' && !isset($departments[$id])) $departments[$id] = ['id' => $id, 'name' => 'ไม่ระบุแผนก'];
    }
    $rows = []; $totals = []; $notLocked = []; $total = 0;
    foreach ($departments as $dept) {
      $summary = ot_dept_summary($roundId, $dept['id']);
      $hours = 0; $locked = 0;
      foreach ($summary['rows'] as $row) {
        $rows[] = $row + ['department_id' => $dept['id'], 'department_name' => $dept['name']];
        if ($row['status'] === 'locked') { $hours += $row['hours']; $locked++; }
      }
      foreach ($summary['notLocked'] as $name) $notLocked[] = $name . ' (' . $dept['name'] . ')';
      $total += $summary['total'];
      $totals[] = ['department_id' => $dept['id'], 'department_name' => $dept['name'], 'hours' => $hours, 'amount' => $summary['total'], 'locked_count' => $locked, 'not_locked_count' => count($summary['notLocked'])];
    }
    return ['rows' => $rows, 'total' => $total, 'totalText' => ot_baht_text($total), 'notLocked' => $notLocked, 'submission' => null, 'departments' => $totals];
  }
  return ot_dept_summary($roundId, $deptId);
}

function api_otSubmit($token = '', $roundId = '', $deptId = '', $note = '', $cancel = false) {
  $me = auth_user($token, 'curriculum');
  if (str($deptId) === '__all' || !isset(idx_table('Departments')[str($deptId)])) throw new Exception('กรุณาเลือกแผนกวิชาเพื่อบันทึกเสนอเบิก');
  return with_lock(function () use ($me, $roundId, $deptId, $note, $cancel) {
    $rows = readTable('OtSubmissions');
    $i = null;
    foreach ($rows as $k => $s) if ((string)$s['round_id'] === str($roundId) && (string)$s['department_id'] === str($deptId) && $s['status'] === 'submitted') { $i = $k; break; }
    if (js_true($cancel)) {
      if ($i !== null) $rows[$i]['status'] = 'cancelled';
      writeTable('OtSubmissions', $rows);
      log_action($me, 'ยกเลิกเสนอเบิก', $deptId);
      return true;
    }
    if ($i !== null) throw new Exception('แผนกนี้เสนอเบิกรอบนี้แล้ว');
    $sum = ot_dept_summary($roundId, $deptId);
    $rows[] = ['id' => new_id(), 'round_id' => $roundId, 'department_id' => $deptId, 'status' => 'submitted', 'note' => js_true($note) ? $note : '', 'snapshot' => json_encode($sum, JSON_UNESCAPED_UNICODE), 'created_by' => $me['name'], 'created_at' => now_dt()];
    writeTable('OtSubmissions', $rows);
    log_action($me, 'บันทึกเสนอเบิก', str($deptId) . ' ' . $sum['total'] . ' บาท');
    return true;
  });
}

/** แนะนำแบบการเบิกตามตำแหน่ง/หน้าที่/ระดับที่สอน (ผู้ดูแลตรวจก่อนบันทึก) */
function api_otSuggestSchemes($token = '', $semesterId = '', $apply = false) {
  $me = auth_user($token, 'curriculum');
  $schemes = readTable('OtSchemes');
  $sessions = sem_sessions($semesterId);
  $groups = idx_table('Groups');
  $teachers = readTable('Teachers');
  $settings = readSettings();
  $out = [];
  foreach ($teachers as $t) {
    $lv = [];
    foreach ($sessions as $s) if (in_array((string)$t['id'], sched_ids($s['teacher_ids']), true)) $lv[ot_level_of($groups[$s['group_id']] ?? null)] = 1;
    if (!$lv) { $out[] = ['teacher_id' => $t['id'], 'name' => $t['name'], 'levels' => [], 'group' => '', 'scheme_ids' => [], 'names' => []]; continue; }
    $both = !empty($lv['vc']) && !empty($lv['vs']);
    $only = (!empty($lv['vs']) && empty($lv['vc'])) ? 'vs' : 'vc';
    $head = (string)$t['is_unit_head'] === 'true';
    $grp = $t['employment'] === 'contract' ? ($head ? 'contract_head' : 'contract_teach') : ($head ? 'head' : 'teach');
    $cand = array_values(array_filter($schemes, fn($s) => $s['group'] === $grp));
    $pick = array_values(array_filter($cand, function ($s) use ($both, $only) {
      $vc = (string)$s['vc_enabled'] === 'true'; $vs = (string)$s['vs_enabled'] === 'true';
      return $both ? ($vc && $vs) : ($only === 'vc' ? ($vc && !$vs) : ($vs && !$vc));
    }));
    if (!$pick) $pick = array_values(array_filter($cand, fn($s) => (string)$s['vc_enabled'] === 'true' && (string)$s['vs_enabled'] === 'true'));
    if ($t['employment'] === 'contract' && count($pick) > 1) {
      $t2 = $t; $t2['award_bonus'] = false;
      $capC = ot_personal_cap($t2, $settings)['cap'];
      $byCap = array_values(array_filter($pick, fn($s) => num($s['total_cap']) === $capC));
      if ($byCap) $pick = $byCap;
      else { stable_usort($pick, fn($a, $b) => num($a['total_cap']) <=> num($b['total_cap'])); $pick = array_slice($pick, 0, 1); }
    }
    $out[] = ['teacher_id' => $t['id'], 'name' => $t['name'], 'levels' => array_keys($lv), 'group' => $grp, 'scheme_ids' => array_column($pick, 'id'), 'names' => array_column($pick, 'name')];
  }
  if (js_true($apply)) with_lock(function () use ($me, $out) {
    $map = [];
    foreach ($out as $o) $map[$o['teacher_id']] = $o;
    $rows = readTable('Teachers');
    foreach ($rows as $k => $t) {
      $o = $map[$t['id']] ?? null;
      if ($o && $o['scheme_ids'] && $t['ot_scheme_ids'] === '') { $rows[$k]['ot_scheme_ids'] = implode(',', $o['scheme_ids']); $rows[$k]['ot_enabled'] = true; }
    }
    writeTable('Teachers', $rows);
    log_action($me, 'กำหนดแบบการเบิกตามคำแนะนำ', count($out) . ' คน');
  });
  return $out;
}
