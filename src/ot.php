<?php
/**
 * แกนคำนวณค่าสอนเกินภาระงาน
 * อ้างอิง: ระเบียบวิทยาลัยการอาชีพโพนทอง (บันทึกการประชุม 12 ต.ค. 2569) และกติกาแบบการเบิกของระบบ TAB
 * หน่วยพื้นฐาน = ชั่วโมงสอนจริงหนึ่งคาบในวันหนึ่ง แต่ละชั่วโมงเลือกเป็น '' (ไม่ใช้) / 'L' (ภาระ) / 'C' (เบิก)
 * กติกาต้องตรงกับ OtCore.js ที่หน้าเว็บใช้แสดงผล
 */
const OT_LEVELS = ['vc', 'vs', 'bd'];
const OT_LEVEL_TH = ['vc' => 'ปวช.', 'vs' => 'ปวส.', 'bd' => 'ปตรี.'];

function ot_parse(string $s): DateTimeImmutable {
  $p = explode('-', $s);
  return (new DateTimeImmutable('today'))->setDate((int)($p[0] ?? 0), (int)($p[1] ?? 1), (int)($p[2] ?? 1));
}
function ot_ymd(DateTimeInterface $d): string { return $d->format('Y-m-d'); }
/** 0=จันทร์ ... 6=อาทิตย์ */
function ot_dow(DateTimeInterface $d): int { return ((int)$d->format('w') + 6) % 7; }

function ot_level_of(?array $group): string {
  $v = $group ? (js_true($group['level'] ?? '') ? $group['level'] : ($group['year_level'] ?? '')) : '';
  $s = (string)$v;
  if (preg_match('/ปวส|vs/iu', $s)) return 'vs';
  if (preg_match('/ปตรี|ป\.ตรี|ปริญญา|bd/iu', $s)) return 'bd';
  return 'vc';
}

function ot_kind_of(?array $course): string {
  if (!$course) return 'theory';
  $k = $course['kind'] ?? '';
  if ($k === 'theory' || $k === 'practice') return $k;
  return num($course['practice'] ?? 0) > num($course['theory'] ?? 0) ? 'practice' : 'theory';
}

function ot_scheme(array $row): array {
  $s = ['id' => $row['id'], 'name' => $row['name'], 'total_cap' => num($row['total_cap'] ?? 0),
        'cap_method' => js_true($row['cap_method'] ?? '') ? $row['cap_method'] : 'combined_trim', 'levels' => []];
  foreach (OT_LEVELS as $l) {
    $min = $row[$l . '_min'] ?? '';
    $s['levels'][$l] = [
      'enabled' => (string)($row[$l . '_enabled'] ?? '') === 'true',
      'load' => num($row[$l . '_load'] ?? 0), 'min' => $min === '' ? 1 : num($min),
      'max' => num($row[$l . '_max'] ?? 0), 'rate' => num($row[$l . '_rate'] ?? 0),
    ];
  }
  return $s;
}

function ot_service_years($start, ?string $atDate): ?int {
  if (!js_true($start)) return null;
  $a = ot_parse(substr((string)$start, 0, 10));
  $b = $atDate ? ot_parse($atDate) : new DateTimeImmutable('today');
  $y = (int)$b->format('Y') - (int)$a->format('Y');
  $bm = (int)$b->format('n'); $am = (int)$a->format('n');
  if ($bm < $am || ($bm === $am && (int)$b->format('j') < (int)$a->format('j'))) $y--;
  return max(0, $y);
}

/**
 * เพดานรายบุคคล (ระเบียบข้อ 5)
 * ครูอัตราจ้างตามอายุงาน (ตาราง ot_contract_caps) และสิทธิ์เพิ่ม 1 ชม. ข้อ 5.6 รวมไม่เกินเพดานวิทยาลัย
 */
function ot_personal_cap(array $teacher, array $settings, ?string $atDate = null): array {
  $collegeCap = num($settings['ot_max_cap'] ?? 0) ?: 12;
  $cap = $collegeCap; $note = [];
  if (($teacher['employment'] ?? '') === 'contract') {
    $yrs = ot_service_years($teacher['service_start'] ?? '', $atDate);
    $src = js_true($settings['ot_contract_caps'] ?? '') ? $settings['ot_contract_caps'] : '0-3:6,4-6:7,7-9:8,10-99:8';
    $table = array_map(function ($x) {
      $a = explode(':', $x); $r = explode('-', $a[0]);
      return ['from' => num($r[0]), 'to' => num($r[1] ?? 0), 'cap' => num($a[1] ?? 0)];
    }, explode(',', (string)$src));
    if ($yrs === null) { $cap = $table[0]['cap']; $note[] = 'ไม่ได้ระบุวันเริ่มงาน ใช้เพดานอายุงานต่ำสุด ' . $cap . ' ชม.'; }
    else {
      $hit = null;
      foreach ($table as $t) if ($yrs >= $t['from'] && $yrs <= $t['to']) { $hit = $t; break; }
      $hit ??= $table[count($table) - 1];
      $cap = $hit['cap']; $note[] = 'อัตราจ้าง อายุงาน ' . $yrs . ' ปี เพดาน ' . $cap . ' ชม.';
    }
  }
  $bonus = award_hours($teacher['award_bonus'] ?? ''); // 1–3 ชม. ค่าเดิม true = 1 (ต้องตรงกับ OtCore.js)
  if ($bonus) { $cap = min($collegeCap, $cap + $bonus); $note[] = 'สิทธิ์เพิ่ม ' . $bonus . ' ชม. (รางวัลระดับชาติ ข้อ 5.6)'; }
  // ผู้ดูแลระบบ/งานหลักสูตรฯ กำหนดเพดานรายบุคคลได้ ใช้แทนค่าที่คำนวณ แต่ไม่เกินเพดานวิทยาลัย (ต้องตรงกับ OtCore.js)
  if (trim((string)($teacher['ot_cap'] ?? '')) !== '' && is_numeric($teacher['ot_cap'])) {
    $cap = max(0, min($collegeCap, num($teacher['ot_cap'])));
    $note = ['กำหนดเพดานรายบุคคล ' . $cap . ' ชม.'];
  }
  return ['cap' => $cap, 'note' => implode(' · ', $note)];
}

/** วันในรอบการเบิก แบ่งเป็นสัปดาห์ (จันทร์–อาทิตย์) */
function ot_weeks_of(array $round): array {
  $a = ot_parse((string)$round['start_date']); $b = ot_parse((string)$round['end_date']);
  $weeks = []; $cur = -1;
  for ($d = $a; $d <= $b; $d = $d->modify('+1 day')) {
    if ($cur < 0 || ot_dow($d) === 0) { $weeks[] = ['index' => count($weeks) + 1, 'dates' => []]; $cur = count($weeks) - 1; }
    $weeks[$cur]['dates'][] = ot_ymd($d);
  }
  return $weeks;
}

function ot_blocked_at(array $blocked, string $date, int $day, int $period): ?array {
  foreach ($blocked as $b) {
    if (js_true($b['start_date'] ?? '') && $date < substr((string)$b['start_date'], 0, 10)) continue;
    if (js_true($b['end_date'] ?? '') && $date > substr((string)$b['end_date'], 0, 10)) continue;
    if (($b['day'] ?? '') !== '' && (string)$b['day'] !== (string)$day) continue;
    $f = num($b['period_from'] ?? 0) ?: 1;
    $t = num($b['period_to'] ?? 0) ?: (js_true($b['period_from'] ?? '') ? $f : 99);
    if ($period >= $f && $period <= $t) return $b;
  }
  return null;
}

function ot_absent_at(array $absences, string $tid, string $date, int $period): ?array {
  foreach ($absences as $a) {
    if ((string)$a['teacher_id'] !== $tid && $a['teacher_id'] !== '*') continue;
    if (substr((string)$a['date'], 0, 10) !== $date) continue;
    $f = num($a['period_from'] ?? 0) ?: 1; $t = num($a['period_to'] ?? 0) ?: 99;
    if ($period >= $f && $period <= $t) return $a;
  }
  return null;
}

function ot_item(array $D, array $s, string $date, int $day, int $p, string $tid, ?array $sub): array {
  $g = $D['groups'][$s['group_id'] ?? ''] ?? []; $c = $D['courses'][$s['course_id'] ?? ''] ?? [];
  $it = [
    'key' => $date . '|' . $s['id'] . '|' . $p . ($sub ? '|' . $sub['id'] : ''), 'date' => $date, 'day' => $day, 'period' => $p, 'session_id' => $s['id'],
    'group_id' => $s['group_id'], 'course_id' => $s['course_id'], 'level' => ot_level_of($g), 'kind' => ot_kind_of($c ?: null), 'course_type' => $c['course_type'] ?? '',
    'students' => num($g['students'] ?? 0), 'blocked' => '', 'absent' => '', 'sub' => $sub ? $sub['type'] : '', 'sub_id' => $sub ? $sub['id'] : '',
  ];
  $b = ot_blocked_at($D['blocked'], $date, $day, $p);
  if ($b) $it['blocked'] = js_true($b['name'] ?? '') ? $b['name'] : (js_true($b['label'] ?? '') ? $b['label'] : 'ห้ามเบิก');
  if (in_array($s['workplace'] ?? false, [true, 'true'], true) || ($c['course_type'] ?? '') === 'internship') $it['no_claim'] = 'คาบสถานประกอบการ — นับภาระงานได้ แต่เบิกเงินไม่ได้';
  if (!$sub) {
    $a = ot_absent_at($D['absences'], $tid, $date, $p);
    if ($a) $it['absent'] = js_true($a['type'] ?? '') ? $a['type'] : 'ลา';
    // ครูผู้สอนเดิมที่มีผู้สอนแทน/ชดเชยในวันนั้น = ไม่ได้สอนจริง
    // ยกเว้นสอนชดเชยที่รายงานผลแล้ว: นับชั่วโมงที่คาบเดิมตามตารางสอน แทนวันที่สอนชดเชย
    $madeUp = null; $other = false;
    foreach ($D['subs'] as $r) {
      $od = js_true($r['original_date'] ?? '') ? $r['original_date'] : $r['date'];
      if ((string)$r['original_teacher_id'] === $tid && (string)$r['session_id'] === (string)$s['id'] && substr((string)$od, 0, 10) === $date && !in_array((string)$r['status'], ['cancelled', 'deleted'], true)) {
        if (ot_makeup_reported($r, $tid)) { $madeUp = $r; continue; }
        $other = true;
        if (!js_true($it['absent'])) $it['absent'] = $r['type'] === 'makeup' ? 'สอนชดเชยวันอื่น' : 'มีผู้สอนแทน';
      }
    }
    if ($madeUp && !$other) { $it['absent'] = ''; $it['made_up'] = substr((string)$madeUp['date'], 0, 10); }
  } elseif ($sub['type'] === 'sub' && ot_own_session_at($D['sessions'], $tid, $day, $p, (string)$s['id'])) {
    // ระเบียบข้อ 8: ครูผู้สอนแทนเบิกได้เฉพาะคาบที่ตารางสอนของตนเองว่าง
    $it['absent'] = 'ดูแลแทนขณะมีคาบสอนของตนเอง — เบิกไม่ได้';
  } elseif (ot_makeup_reported($sub, $tid)) {
    // นับไว้ที่คาบเดิมแล้ว วันสอนชดเชยจึงไม่นับซ้ำ
    $od = js_true($sub['original_date'] ?? '') ? $sub['original_date'] : $sub['date'];
    $it['absent'] = 'นับที่คาบเดิม ' . substr((string)$od, 0, 10);
  } elseif ($sub['type'] === 'makeup' && (string)$sub['report_done'] !== 'true') {
    $it['pending'] = 'รอแนบรายงานผลการสอนชดเชย';
  }
  return $it;
}

/** ครูมีคาบสอนของตนเองตามตารางในวันและคาบนี้หรือไม่ (ไม่นับคาบที่กำลังสอนแทน) */
function ot_own_session_at(array $sessions, string $tid, int $day, int $p, string $exceptId = ''): bool {
  foreach ($sessions as $x) {
    if ((string)$x['id'] === $exceptId || num($x['day']) !== $day || !in_array($tid, explode(',', (string)$x['teacher_ids']), true)) continue;
    if ($p >= num($x['start']) && $p < num($x['start']) + num($x['length'])) return true;
  }
  return false;
}

/** สอนชดเชยโดยครูคนเดิมที่รายงานผลแล้ว จึงนับชั่วโมงที่คาบเดิมได้ */
function ot_makeup_reported(array $r, string $tid): bool {
  return ($r['type'] ?? '') === 'makeup' && (string)($r['report_done'] ?? '') === 'true'
    && (string)$r['original_teacher_id'] === $tid && (string)$r['teacher_id'] === $tid;
}

/** ชั่วโมงสอนจริงของครูในรอบ (รวมสอนแทน/สอนชดเชย) */
function ot_hours_for(array $D): array {
  $src = $D['settings']['days'] ?? '';
  $days = array_map('num', explode(',', (string)(js_true($src) ? $src : '0,1,2,3,4')));
  $tid = (string)$D['teacher']['id'];
  $mine = array_filter($D['sessions'], fn($s) => in_array($tid, explode(',', (string)$s['teacher_ids']), true));
  $weeks = ot_weeks_of($D['round']);
  foreach ($weeks as &$w) {
    $w['items'] = [];
    foreach ($w['dates'] as $date) {
      $day = ot_dow(ot_parse($date));
      if (!in_array($day, $days, true)) continue;
      foreach ($mine as $s) {
        if (num($s['day']) !== $day) continue;
        $st = num($s['start']); $len = num($s['length']);
        for ($p = $st; $p < $st + $len; $p++) $w['items'][] = ot_item($D, $s, $date, $day, $p, $tid, null);
      }
    }
    // สอนแทน / สอนชดเชย ที่ครูคนนี้เป็นผู้สอน
    foreach ($D['subs'] as $r) {
      $date = substr((string)$r['date'], 0, 10);
      if ((string)$r['teacher_id'] !== $tid || !in_array($date, $w['dates'], true) || (string)$r['status'] === 'cancelled') continue;
      $s = null;
      foreach ($D['sessions'] as $x) if ((string)$x['id'] === (string)$r['session_id']) { $s = $x; break; }
      $s ??= ['id' => $r['session_id'], 'group_id' => $r['group_id'], 'course_id' => $r['course_id'], 'teacher_ids' => $r['original_teacher_id']];
      $day = ot_dow(ot_parse($date));
      $st = num($r['start']); $len = num($r['length']);
      for ($p = $st; $p < $st + $len; $p++) $w['items'][] = ot_item($D, $s, $date, $day, $p, $tid, $r);
    }
    stable_usort($w['items'], fn($a, $b) => $a['date'] < $b['date'] ? -1 : ($a['date'] > $b['date'] ? 1 : $a['period'] - $b['period']));
  }
  unset($w);
  return $weeks;
}

/** หากฎเบิกรายหัวที่ใช้กับชั่วโมงนี้ */
function ot_per_head_rule(array $it, array $rules, ?array $course): ?array {
  if (!$it['students']) return null;
  $sg = $course['subject_group'] ?? '';
  $match = array_values(array_filter($rules, fn($r) => $r['track'] === $it['level'] && $r['course_kind'] === $it['kind'] && (!js_true($r['subject_group'] ?? '') || !js_true($sg) || $r['subject_group'] === $sg) && $it['students'] < num($r['below_students'])));
  // ใช้ช่วงจำนวนคนที่แคบที่สุดก่อน และเกณฑ์ของกลุ่มวิชานั้นก่อนเกณฑ์ทุกกลุ่ม (ตรงกับ Ot.perHeadRule)
  stable_usort($match, fn($a, $b) => [num($a['below_students']), js_true($a['subject_group'] ?? '') ? 0 : 1] <=> [num($b['below_students']), js_true($b['subject_group'] ?? '') ? 0 : 1]);
  return $match[0] ?? null;
}

/**
 * ตรวจและคิดเงินของ 1 สัปดาห์
 * sel: {key: 'L'|'C'}, ph: {course_id|group_id: true} (เปิดเบิกรายหัว)
 */
function ot_eval_week(array $items, array $sel, array $ph, array $sch, $cap, array $ctx): array {
  $res = ['byLevel' => [], 'errors' => [], 'warnings' => [], 'amount' => 0, 'claimHours' => 0, 'perHeadAmount' => 0, 'paid' => []];
  foreach (OT_LEVELS as $l) $res['byLevel'][$l] = ['taught' => 0, 'load' => 0, 'claim' => 0, 'perhead' => 0];
  $subUsed = []; $phByLevel = [];
  foreach ($items as $it) {
    $v = $sel[$it['key']] ?? '';
    $lv = $it['level'];
    if (!js_true($it['absent'])) $res['byLevel'][$lv]['taught']++;
    if (!js_true($v)) continue;
    if ($v === 'C' && js_true($it['no_claim'] ?? '')) { $res['errors'][] = $it['date'] . ' คาบ ' . period_no($it['period']) . ': ' . $it['no_claim']; continue; }
    if (js_true($it['blocked'])) { $res['errors'][] = $it['date'] . ' คาบ ' . period_no($it['period']) . ': ' . $it['blocked'] . ' — เลือกไม่ได้'; continue; }
    if (js_true($it['absent'])) { $res['errors'][] = $it['date'] . ' คาบ ' . period_no($it['period']) . ': ' . $it['absent'] . ' — ไม่นับเป็นชั่วโมงสอน'; continue; }
    if ($v === 'L') $res['byLevel'][$lv]['load']++;
    if ($v === 'C') {
      if (js_true($it['pending'] ?? '')) $res['errors'][] = $it['date'] . ' คาบ ' . period_no($it['period']) . ': ' . $it['pending'] . ' (ระเบียบข้อ 8)';
      if ($it['sub'] === 'sub') $subUsed[$it['sub_id']] = ($subUsed[$it['sub_id']] ?? 0) + 1;
      $rule = !empty($ph[$it['course_id'] . '|' . $it['group_id']]) ? ot_per_head_rule($it, $ctx['perHead'] ?? [], $ctx['courses'][$it['course_id']] ?? null) : null;
      if ($rule) { $res['byLevel'][$lv]['perhead']++; $phAmount = num($rule['rate_per_head']); /* อัตราในเกณฑ์เป็นยอดต่อชั่วโมงของทั้งกลุ่มแล้ว ไม่คูณจำนวนคนซ้ำ */ $res['perHeadAmount'] += $phAmount; $phByLevel[$lv] = ($phByLevel[$lv] ?? 0) + $phAmount; }
      else $res['byLevel'][$lv]['claim']++;
    }
  }
  foreach ($subUsed as $sid => $used) {
    $q = $ctx['subQuota'][$sid] ?? null;
    if ($q !== null && $used > $q) $res['errors'][] = 'สอนแทนเบิก ' . $used . ' ชม. เกินสิทธิ์คงเหลือของครูผู้สอนเดิม (' . $q . ' ชม.) — ระเบียบข้อ 8';
  }
  $method = $sch['cap_method'];
  $free = $method === 'free';
  $totalCap = $method === 'none' ? 999 : min($sch['total_cap'] ?: 999, $cap);
  if ($method === 'none' && $cap < 12) $totalCap = $cap;
  $levelsClaimed = 0;
  foreach (OT_LEVELS as $l) {
    $B = $res['byLevel'][$l]; $L = $sch['levels'][$l]; $claim = $B['claim'] + $B['perhead'];
    if (!$claim && !$B['load']) continue;
    if (!$L['enabled']) { $res['errors'][] = 'แบบการเบิกนี้ไม่ได้เปิดใช้ระดับ ' . OT_LEVEL_TH[$l]; continue; }
    if ($claim) $levelsClaimed++;
    if ($free) continue;
    if ($claim && $B['load'] < $L['load']) $res['errors'][] = OT_LEVEL_TH[$l] . ': ภาระยังไม่ครบ (' . $B['load'] . '/' . $L['load'] . ' ชม.) — ต้องครบก่อนจึงเบิกได้';
    if ($L['max'] && $claim > $L['max']) $res['errors'][] = OT_LEVEL_TH[$l] . ': เบิกเกินสูงสุดของระดับ (' . $claim . '/' . $L['max'] . ' ชม.)';
  }
  if (!$free && $levelsClaimed > 1) foreach (OT_LEVELS as $l) {
    $B = $res['byLevel'][$l]; $L = $sch['levels'][$l]; $claim = $B['claim'] + $B['perhead'];
    if ($claim && $L['min'] && $claim < $L['min']) $res['errors'][] = OT_LEVEL_TH[$l] . ': เบิกน้อยกว่าขั้นต่ำ ' . $L['min'] . ' ชม.';
  }
  if ($method === 'fill_first') foreach (OT_LEVELS as $l) {
    $L = $sch['levels'][$l]; $B = $res['byLevel'][$l];
    if ($L['enabled'] && $B['taught'] && $B['load'] < $L['load']) $res['errors'][] = OT_LEVEL_TH[$l] . ': แบบวันหยุดต้องครบภาระทุกระดับก่อน';
  }
  // ชั่วโมงที่จ่ายจริงต่อระดับ
  $paid = []; $phHours = 0;
  foreach (OT_LEVELS as $l) { $paid[$l] = $res['byLevel'][$l]['claim']; $phHours += $res['byLevel'][$l]['perhead']; }
  $total = array_sum($paid) + $phHours;
  if ($total > $totalCap) {
    if ($method === 'combined_trim') {
      $over = $total - $totalCap;
      $order = OT_LEVELS;
      stable_usort($order, fn($a, $b) => $paid[$b] <=> $paid[$a]); // ฝั่งมากถูกตัด
      foreach ($order as $l) { $cut = min($over, $paid[$l]); $paid[$l] -= $cut; $over -= $cut; }
      $res['warnings'][] = 'เกินเพดานรวม ' . $totalCap . ' ชม. — ตัดฝั่งที่เบิกมากกว่า (รวมแล้วตัด)';
    } else {
      $res['errors'][] = 'ชั่วโมงเบิกรวม ' . $total . ' เกินเพดานรวม ' . $totalCap . ' ชม.';
    }
  }
  if ($method === 'fill_first' && !empty($ctx['refAmount'])) {
    $amt0 = 0;
    foreach (OT_LEVELS as $l) $amt0 += $paid[$l] * $sch['levels'][$l]['rate'];
    $vs = $sch['levels']['vs']['rate'] ?: 0;
    if ($amt0 > $ctx['refAmount'] + $vs) $res['errors'][] = 'แบบวันหยุด: ยอดเงินเกินยอดอ้างอิงแบบระดับเดียว (' . $ctx['refAmount'] . ' บาท) เกิน 1 ชั่วโมงสุดท้าย';
  }
  foreach (OT_LEVELS as $l) $res['amount'] += $paid[$l] * ($sch['levels'][$l]['rate'] ?: 0);
  $res['amount'] += $res['perHeadAmount'];
  $res['paid'] = $paid;
  // ยอดแยกระดับสำหรับงบหน้า ซึ่งทำแยกใบ ปวช. / ปวส.
  $res['levels'] = [];
  foreach (OT_LEVELS as $l) $res['levels'][$l] = ['hours' => $paid[$l] + $res['byLevel'][$l]['perhead'], 'amount' => $paid[$l] * ($sch['levels'][$l]['rate'] ?: 0) + ($phByLevel[$l] ?? 0)];
  $res['claimHours'] = array_sum($paid) + $phHours;
  $res['cap'] = $totalCap;
  if ($free) $res['warnings'][] = 'แบบอิสระ: ไม่ตรวจภาระ/เพดานรายระดับ — ผู้ตรวจต้องตรวจเอง';
  return $res;
}

/** จำนวนเงินเป็นตัวอักษรไทย */
function ot_baht_text($num): string {
  $num = round((float)$num * 100) / 100;
  $D = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
  $P = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'];
  $read = function (string $s) use (&$read, $D, $P): string {
    $out = ''; $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
      $d = (int)$s[$i]; $pos = $len - $i - 1;
      if ($pos >= 6) return $read(substr($s, 0, $len - 6)) . 'ล้าน' . $read(substr($s, $len - 6));
      if (!$d) continue;
      if ($pos === 0 && $d === 1 && $len > 1) $out .= 'เอ็ด';
      elseif ($pos === 1 && $d === 2) $out .= 'ยี่';
      elseif ($pos === 1 && $d === 1) $out .= '';
      else $out .= $D[$d];
      $out .= $P[$pos];
    }
    return $out;
  };
  [$bs, $ss] = explode('.', number_format($num, 2, '.', ''));
  $b = (int)$bs; $s = (int)$ss;
  if (!$b && !$s) return 'ศูนย์บาทถ้วน';
  return ($b ? $read((string)$b) . 'บาท' : '') . ($s ? $read((string)$s) . 'สตางค์' : 'ถ้วน');
}
