<?php
/** รายงานการสอนออนไลน์, นิเทศวิชาที่สอนในสถานประกอบการ และงบหน้าแผนกวิชา */

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
  if (is_academic_manager($me)) return $t;
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
      $slots[] = ['date' => $date, 'session_id' => $r['session_id'], 'group_id' => $r['group_id'], 'course_id' => $r['course_id'], 'room_id' => $s['room_id'] ?? '', 'start' => num($r['start']), 'length' => num($r['length']), 'kind' => $r['type'], 'replaced' => false, 'sub' => sub_summary($r, $s)];
    }
  }
  stable_usort($slots, fn($a, $b) => [$a['date'], $a['start'], (string)$a['session_id']] <=> [$b['date'], $b['start'], (string)$b['session_id']]);
  // คาบที่เรียนด้วยกัน (วิชา ห้อง เวลาเดียวกัน) รายงานใบเดียว ตามแบบฟอร์มของวิทยาลัย
  $merged = [];
  foreach ($slots as $s) {
    $k = implode('|', [$s['date'], $s['course_id'], $s['room_id'], $s['start'], $s['length'], $s['kind'], (int)$s['replaced']]);
    if (!isset($merged[$k])) { $s['group_ids'] = [$s['group_id']]; $merged[$k] = $s; }
    elseif (!in_array($s['group_id'], $merged[$k]['group_ids'], true)) $merged[$k]['group_ids'][] = $s['group_id'];
  }
  return array_values($merged);
}

/** ข้อมูลของรายการสอนแทน/ชดเชย ที่เอกสารรายงานต้องใช้ */
function sub_summary(array $r, ?array $s): array {
  return ['id' => $r['id'], 'reason' => $r['reason'], 'note' => $r['note'] ?? '', 'original_teacher_id' => $r['original_teacher_id'], 'original_date' => substr((string)$r['original_date'], 0, 10), 'original_start' => $s ? num($s['start']) : '', 'original_length' => $s ? num($s['length']) : ''];
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

/** rec: {semester_id, teacher_id, session_id, date, start, topic, activity, media, evaluation, assignment, attendance:[{group_id,total,present,absent,numbers}], memo_no, memo_date, memo_reason, problems, attachment_link} */
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
  if (js_true($rec['memo_date'] ?? '') && !valid_date($rec['memo_date'])) throw new Exception('วันที่ของบันทึกข้อความไม่ถูกต้อง');
  $sem = $rec['semester_id'] ?? '';
  return with_lock(function () use ($me, $rec, $tid, $date, $topic, $link, $sem, $submit) {
    $slot = null;
    foreach (teacher_slots($sem, $tid, $date, $date) as $s) {
      if ((string)$s['session_id'] === str($rec['session_id'] ?? '') && (string)$s['start'] === str($rec['start'] ?? '') && !$s['replaced']) { $slot = $s; break; }
    }
    if (!$slot) throw new Exception('คาบนี้ไม่อยู่ในตารางสอนของครูในวันที่เลือก');
    // จำนวนผู้เรียนแยกรายห้อง รับเฉพาะกลุ่มที่อยู่ในคาบนี้
    $attendance = []; $present = 0; $absent = 0;
    foreach ((array)($rec['attendance'] ?? []) as $a) {
      $a = (array)$a;
      if (!in_array(str($a['group_id'] ?? ''), array_map('strval', $slot['group_ids']), true)) continue;
      $t = num($a['total'] ?? 0); $p = num($a['present'] ?? 0); $b = num($a['absent'] ?? 0); $l = num($a['leave'] ?? 0);
      if ($t < 0 || $p < 0 || $b < 0 || $l < 0) throw new Exception('จำนวนผู้เรียนต้องไม่ติดลบ');
      if ($t > 0 && $p + $b + $l > $t) throw new Exception('จำนวนเข้าเรียน ลา และขาดเรียนรวมกันเกินจำนวนผู้เรียนทั้งหมด');
      $attendance[] = ['group_id' => str($a['group_id']), 'total' => $t, 'present' => $p, 'leave' => $l, 'absent' => $b, 'numbers' => clean_text($a['numbers'] ?? '', 500)];
      $present += $p; $absent += $b;
    }
    $rows = readTable('TeachReports');
    $i = null;
    foreach ($rows as $k => $r) {
      if ((string)$r['semester_id'] === str($sem) && (string)$r['teacher_id'] === $tid && $r['date'] === $date && (string)$r['session_id'] === (string)$slot['session_id'] && (string)$r['start'] === (string)$slot['start']) { $i = $k; break; }
    }
    if ($i !== null && $rows[$i]['status'] === 'approved' && !is_academic_manager($me)) throw new Exception('รายงานนี้อนุมัติแล้ว แก้ไขไม่ได้');
    if ($i === null) { $rows[] = ['id' => new_id(), 'created_at' => now_dt(), 'reviewed_by' => '', 'reviewed_at' => '', 'review_note' => '', 'submitted_at' => '', 'status' => 'draft']; $i = count($rows) - 1; }
    $rows[$i] = array_merge($rows[$i], [
      'semester_id' => $sem, 'teacher_id' => $tid, 'session_id' => $slot['session_id'], 'group_id' => $slot['group_id'], 'course_id' => $slot['course_id'],
      'date' => $date, 'start' => $slot['start'], 'length' => $slot['length'], 'kind' => $slot['kind'], 'topic' => $topic, 'activity' => clean_text($rec['activity'] ?? ''),
      'students_present' => $present, 'students_absent' => $absent, 'problems' => clean_text($rec['problems'] ?? ''), 'attachment_link' => $link,
      'group_ids' => implode(',', $slot['group_ids']), 'media' => clean_text($rec['media'] ?? ''), 'evaluation' => clean_text($rec['evaluation'] ?? ''), 'assignment' => clean_text($rec['assignment'] ?? ''),
      'attendance' => json_encode($attendance, JSON_UNESCAPED_UNICODE), 'memo_no' => clean_text($rec['memo_no'] ?? '', 50), 'memo_date' => js_true($rec['memo_date'] ?? '') ? $rec['memo_date'] : date('Y-m-d'), 'memo_reason' => clean_text($rec['memo_reason'] ?? '', 3000),
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
  stable_usort($rows, fn($a, $b) => [$b['date'], $a['start']] <=> [$a['date'], $b['start']]);
  // รายงานสอนแทน/ชดเชย ต้องรู้เหตุผลและครูผู้สอนเดิมเพื่อออกเอกสาร
  $subs = array_filter(readTable('OtSubs'), fn($x) => $x['status'] !== 'cancelled');
  $sessions = idx_table('Sessions');
  foreach ($rows as &$r) {
    if (($r['kind'] ?? 'normal') === 'normal') continue;
    foreach ($subs as $x) {
      if ((string)$x['teacher_id'] === (string)$r['teacher_id'] && substr((string)$x['date'], 0, 10) === $r['date'] && (string)$x['session_id'] === (string)$r['session_id'] && (string)$x['start'] === (string)$r['start']) { $r['sub'] = sub_summary($x, $sessions[$x['session_id']] ?? null); break; }
    }
  }
  unset($r);
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
    if ($rows[$i]['status'] === 'approved' && !is_academic_manager($me)) throw new Exception('รายงานนี้อนุมัติแล้ว ลบไม่ได้');
    foreach (report_image_names($rows[$i]) as $n) @unlink(report_image_dir() . '/' . $n);
    array_splice($rows, $i, 1);
    writeTable('TeachReports', $rows);
    log_action($me, 'ลบรายงานการสอน', $id);
    return true;
  });
}

// ----- รูปประกอบรายงาน: เห็นหน้าและชื่อนักเรียน จึงเก็บใน data/ ซึ่งเปิดตรงจากเว็บไม่ได้ และส่งผ่าน API ที่ตรวจสิทธิ์ -----
const REPORT_MAX_IMAGES = 4;
const REPORT_MAX_IMAGE_BYTES = 4 * 1024 * 1024;
const REPORT_STORED_IMAGE_BYTES = 200 * 1024;
const REPORT_IMAGE_MAX_SIDE = 1280;
const REPORT_IMAGE_NAME = '/^[a-z0-9]+-[a-f0-9]{12}\.(jpg|png)$/';

function report_image_dir(): string {
  $d = APP_ROOT . '/data/teach_images';
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}
function report_image_names(array $r): array {
  $list = json_decode((string)($r['images'] ?? ''), true);
  return is_array($list) ? array_values(array_filter($list, fn($n) => is_string($n) && preg_match(REPORT_IMAGE_NAME, $n))) : [];
}
/** ชนิดรูปจากเนื้อไฟล์จริง ไม่เชื่อชื่อไฟล์หรือชนิดที่ส่งมา คืนค่าว่างถ้าไม่ใช่ JPG/PNG */
function report_image_ext(string $bytes): string {
  $info = @getimagesizefromstring($bytes);
  return [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'][$info[2] ?? 0] ?? '';
}
/** ภาพใหม่ต้องเล็กก่อนเก็บ แม้ส่งมาจากหน้าเว็บรุ่นเก่า */
function report_prepare_image(string $bytes): array {
  $info = @getimagesizefromstring($bytes);
  $ext = report_image_ext($bytes);
  if (!$info || $ext === '') throw new Exception('รองรับเฉพาะรูป JPG และ PNG');
  $w = (int)$info[0]; $h = (int)$info[1];
  if ($w < 1 || $h < 1 || $w * $h > 20000000) throw new Exception('รูปมีความละเอียดสูงเกินไป กรุณาย่อรูปก่อนอัปโหลด');
  if (strlen($bytes) <= REPORT_STORED_IMAGE_BYTES && max($w, $h) <= REPORT_IMAGE_MAX_SIDE) return ['bytes' => $bytes, 'ext' => $ext];
  if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) throw new Exception('กรุณารีเฟรชหน้าเว็บแล้วเลือกภาพใหม่ ระบบจะย่อรูปไม่เกิน 200 KB ก่อนส่ง');
  $source = @imagecreatefromstring($bytes);
  if (!$source) throw new Exception('อ่านไฟล์รูปไม่สำเร็จ');
  try {
    $scale = min(1, REPORT_IMAGE_MAX_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    while (true) {
      $canvas = imagecreatetruecolor($nw, $nh);
      if (!$canvas) throw new Exception('เตรียมภาพไม่สำเร็จ');
      try {
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
        foreach ([78, 68, 58] as $quality) {
          ob_start();
          try { $ok = imagejpeg($canvas, null, $quality); $out = (string)ob_get_contents(); }
          finally { ob_end_clean(); }
          if ($ok && strlen($out) <= REPORT_STORED_IMAGE_BYTES) return ['bytes' => $out, 'ext' => 'jpg'];
        }
      } finally { imagedestroy($canvas); }
      if (max($nw, $nh) <= 480) throw new Exception('ย่อรูปไม่สำเร็จ กรุณาเลือกภาพใหม่');
      $ratio = max(480 / max($nw, $nh), 0.8);
      $nw = max(1, (int)floor($nw * $ratio)); $nh = max(1, (int)floor($nh * $ratio));
    }
  } finally { imagedestroy($source); }
}
function report_row(array $me, $id, array &$rows, bool $forEdit): int {
  $rows = readTable('TeachReports');
  $i = find_row($rows, str($id));
  if ($i === null) throw new Exception('ไม่พบรายงาน');
  assert_teacher_scope($me, $rows[$i]['teacher_id']);
  if ($forEdit && $rows[$i]['status'] === 'approved' && !is_academic_manager($me)) throw new Exception('รายงานนี้อนุมัติแล้ว แก้ไขไม่ได้');
  return $i;
}

function api_teachReportImageAdd($token = '', $id = '', $base64 = '') {
  $me = auth_user($token);
  $bytes = base64_decode(str($base64), true);
  if ($bytes === false || $bytes === '') throw new Exception('อ่านไฟล์รูปไม่สำเร็จ');
  if (strlen($bytes) > REPORT_MAX_IMAGE_BYTES) throw new Exception('รูปมีขนาดใหญ่เกิน 4 MB');
  $ext = report_image_ext($bytes);
  if ($ext === '') throw new Exception('รองรับเฉพาะรูป JPG และ PNG');
  return with_lock(function () use ($me, $id, $bytes, $ext) {
    $rows = [];
    $i = report_row($me, $id, $rows, true);
    $names = report_image_names($rows[$i]);
    if (count($names) >= REPORT_MAX_IMAGES) throw new Exception('แนบรูปได้ไม่เกิน ' . REPORT_MAX_IMAGES . ' รูปต่อรายงาน');
    $prepared = report_prepare_image($bytes);
    $bytes = $prepared['bytes']; $ext = $prepared['ext'];
    $name = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$rows[$i]['id'])) . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (file_put_contents(report_image_dir() . '/' . $name, $bytes) !== strlen($bytes)) throw new Exception('บันทึกรูปไม่สำเร็จ');
    $names[] = $name;
    $rows[$i]['images'] = json_encode($names);
    writeTable('TeachReports', $rows);
    log_action($me, 'แนบรูปรายงานการสอน', (string)$rows[$i]['id']);
    return $name;
  });
}

function api_teachReportImages($token = '', $id = '') {
  $me = auth_user($token);
  $rows = [];
  $i = report_row($me, $id, $rows, false);
  $out = [];
  foreach (report_image_names($rows[$i]) as $n) {
    $p = report_image_dir() . '/' . $n;
    if (is_file($p)) $out[] = ['name' => $n, 'url' => 'data:image/' . (str_ends_with($n, '.png') ? 'png' : 'jpeg') . ';base64,' . base64_encode((string)file_get_contents($p))];
  }
  return $out;
}

function api_teachReportImageDelete($token = '', $id = '', $name = '') {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id, $name) {
    $rows = [];
    $i = report_row($me, $id, $rows, true);
    $names = report_image_names($rows[$i]);
    if (!in_array(str($name), $names, true)) throw new Exception('ไม่พบรูป');
    @unlink(report_image_dir() . '/' . str($name));
    $rows[$i]['images'] = json_encode(array_values(array_diff($names, [str($name)])));
    writeTable('TeachReports', $rows);
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

// ======================= นิเทศออนไลน์: วิชาที่สอนในสถานประกอบการ =======================
/** เกณฑ์เดียวกับสีแดงในตาราง: ตั้งค่าในแผนการเรียน ประเภทวิชา หรือมีคำว่า สถานประกอบการ ในชื่อวิชา/ห้อง */
function supervise_is_workplace(array $plan, array $sessions, array $ctx): bool {
  if (sched_is_workplace($plan, $ctx)) return true;
  $word = 'สถานประกอบการ';
  if (mb_strpos((string)($ctx['courses'][$plan['course_id']]['name'] ?? ''), $word) !== false) return true;
  $rooms = array_merge([$plan['room_id'] ?? ''], array_column($sessions, 'room_id'));
  foreach ($rooms as $r) if (mb_strpos((string)($ctx['rooms'][$r]['name'] ?? ''), $word) !== false) return true;
  return false;
}

/** รายการที่ต้องนิเทศของภาคเรียน วิชาเรียนร่วมนับเป็นรายการเดียว */
function supervise_units($semesterId): array {
  $ctx = sched_ctx_now();
  $byPlan = [];
  foreach (sem_sessions($semesterId) as $s) $byPlan[$s['plan_id']][] = $s;
  $units = [];
  foreach (readTable('Plans') as $p) {
    if ((string)$p['semester_id'] !== str($semesterId)) continue;
    $sessions = $byPlan[$p['id']] ?? [];
    if (!supervise_is_workplace($p, $sessions, $ctx)) continue;
    $key = js_true($p['joint_key'] ?? '') ? 'J:' . $p['joint_key'] : 'P:' . $p['id'];
    $u = &$units[$key];
    $u ??= ['key' => $key, 'course_id' => $p['course_id'], 'group_ids' => [], 'teacher_ids' => [], 'slots' => [], 'hours' => 0];
    if (!in_array($p['group_id'], $u['group_ids'], true)) $u['group_ids'][] = $p['group_id'];
    foreach (sched_ids($p['teacher_ids'] ?? '') as $t) if (!in_array($t, $u['teacher_ids'], true)) $u['teacher_ids'][] = $t;
    foreach ($sessions as $s) {
      $slot = $s['day'] . '-' . $s['start'] . '-' . $s['length'];
      if (isset($u['slots'][$slot])) continue;
      $u['hours'] += num($s['length']);
      $a = period_no($s['start']); $b = period_no(num($s['start']) + num($s['length']) - 1);
      $u['slots'][$slot] = (DAY_NAMES[num($s['day'])] ?? '') . ' คาบ ' . ($a === $b ? $a : $a . '–' . $b);
    }
    unset($u);
  }
  return $units;
}

function supervise_can_see(array $me, array $u): bool {
  if (is_academic_manager($me)) return true;
  if ($me['role'] === 'teacher') return in_array((string)$me['teacher_id'], array_map('strval', $u['teacher_ids']), true);
  $dept = (string)$me['department_id'];
  $groups = idx_table('Groups'); $teachers = idx_table('Teachers');
  foreach ($u['group_ids'] as $g) if ((string)($groups[$g]['department_id'] ?? '') === $dept) return true;
  foreach ($u['teacher_ids'] as $t) if ((string)($teachers[$t]['department_id'] ?? '') === $dept) return true;
  return false;
}

function supervise_unit(array $me, $semesterId, $key): array {
  $u = supervise_units($semesterId)[str($key)] ?? null;
  if (!$u) throw new Exception('ไม่พบวิชาที่สอนในสถานประกอบการรายการนี้');
  if (!supervise_can_see($me, $u)) throw new Exception('ดูได้เฉพาะวิชาที่ครูสอน หรือวิชาของแผนกตัวเอง');
  return $u;
}

function api_superviseList($token = '', $semesterId = '') {
  $me = auth_user($token);
  visit_sites_to_dept_once();
  $counts = [];
  foreach (readTable('SupervisionVisits') as $v) {
    if ((string)$v['semester_id'] !== str($semesterId)) continue;
    $c = &$counts[$v['unit_key']];
    $c ??= ['done' => 0, 'planned' => 0, 'last' => ''];
    if ($v['status'] === 'done') { $c['done']++; if ($v['visit_date'] > $c['last']) $c['last'] = $v['visit_date']; } else $c['planned']++;
    unset($c);
  }
  $out = [];
  foreach (supervise_units($semesterId) as $key => $u) {
    if (!supervise_can_see($me, $u)) continue;
    $c = $counts[$key] ?? ['done' => 0, 'planned' => 0, 'last' => ''];
    $u['slots'] = array_values($u['slots']);
    $out[] = $u + ['visits_done' => $c['done'], 'visits_planned' => $c['planned'], 'last_visit' => $c['last']];
  }
  return $out;
}

function visit_image_row(array $me, $id, array &$rows, bool $forEdit): int {
  $rows = readTable('SupervisionVisits');
  $i = find_row($rows, str($id));
  if ($i === null) throw new Exception('ไม่พบรายการนิเทศ');
  supervise_unit($me, $rows[$i]['semester_id'], $rows[$i]['unit_key']);
  return $i;
}

function api_visitImageAdd($token = '', $id = '', $base64 = '') {
  $me = auth_user($token);
  $bytes = base64_decode(str($base64), true);
  if ($bytes === false || $bytes === '') throw new Exception('อ่านไฟล์รูปไม่สำเร็จ');
  if (strlen($bytes) > REPORT_MAX_IMAGE_BYTES) throw new Exception('รูปมีขนาดใหญ่เกิน 4 MB');
  $ext = report_image_ext($bytes);
  if ($ext === '') throw new Exception('รองรับเฉพาะรูป JPG และ PNG');
  return with_lock(function () use ($me, $id, $bytes, $ext) {
    $rows = [];
    $i = visit_image_row($me, $id, $rows, true);
    $names = report_image_names($rows[$i]);
    if (count($names) >= REPORT_MAX_IMAGES) throw new Exception('แนบรูปได้ไม่เกิน ' . REPORT_MAX_IMAGES . ' รูปต่อรายงาน');
    $prepared = report_prepare_image($bytes);
    $bytes = $prepared['bytes']; $ext = $prepared['ext'];
    $name = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$rows[$i]['id'])) . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (file_put_contents(report_image_dir() . '/' . $name, $bytes) !== strlen($bytes)) throw new Exception('บันทึกรูปไม่สำเร็จ');
    $names[] = $name;
    $rows[$i]['images'] = json_encode($names);
    writeTable('SupervisionVisits', $rows);
    log_action($me, 'แนบหลักฐานการนิเทศ', (string)$rows[$i]['id']);
    return $name;
  });
}

function api_visitImages($token = '', $id = '') {
  $me = auth_user($token);
  $rows = [];
  $i = visit_image_row($me, $id, $rows, false);
  $out = [];
  foreach (report_image_names($rows[$i]) as $n) {
    $p = report_image_dir() . '/' . $n;
    if (is_file($p)) $out[] = ['name' => $n, 'url' => 'data:image/' . (str_ends_with($n, '.png') ? 'png' : 'jpeg') . ';base64,' . base64_encode((string)file_get_contents($p))];
  }
  return $out;
}

function api_visitImageDelete($token = '', $id = '', $name = '') {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id, $name) {
    $rows = [];
    $i = visit_image_row($me, $id, $rows, true);
    $names = report_image_names($rows[$i]);
    if (!in_array(str($name), $names, true)) throw new Exception('ไม่พบรูป');
    @unlink(report_image_dir() . '/' . str($name));
    $rows[$i]['images'] = json_encode(array_values(array_diff($names, [str($name)])));
    writeTable('SupervisionVisits', $rows);
    return true;
  });
}

function api_visitList($token = '', $semesterId = '', $key = '') {
  $me = auth_user($token);
  visit_sites_to_dept_once();
  supervise_unit($me, $semesterId, $key);
  $rows = array_values(array_filter(readTable('SupervisionVisits'), fn($v) => (string)$v['semester_id'] === str($semesterId) && (string)$v['unit_key'] === str($key)));
  stable_usort($rows, fn($a, $b) => strcmp($b['visit_date'], $a['visit_date']));
  return $rows;
}

/** ชื่อแผนกวิชาของกลุ่มเรียนในวิชาที่นิเทศ (ใช้ในช่อง "แผนกวิชา" ของตารางสถานประกอบการ) */
function supervise_dept_names(array $u): string {
  $groups = idx_table('Groups'); $depts = idx_table('Departments'); $names = [];
  foreach ($u['group_ids'] as $g) {
    $n = trim(str($depts[$groups[$g]['department_id'] ?? '']['name'] ?? ''));
    if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
  }
  return implode(', ', $names);
}

/** ครั้งเดียว: เปลี่ยนช่อง "สาขางาน" ที่ครูกรอกไว้แล้ว ให้เป็นชื่อแผนกวิชาตามแบบฟอร์ม */
function visit_sites_to_dept_once(): void {
  $flag = 'migrate:visit_sites_dept';
  if (kvGet($flag) !== null) return;
  with_lock(function () use ($flag) {
    if (kvGet($flag) !== null) return;
    $rows = readTable('SupervisionVisits'); $units = []; $changed = false;
    foreach ($rows as &$v) {
      $sem = str($v['semester_id']);
      $units[$sem] ??= supervise_units($sem);
      $u = $units[$sem][str($v['unit_key'])] ?? null;
      if (!$u || ($dept = supervise_dept_names($u)) === '') continue;
      $sites = json_decode(str($v['sites'] ?? ''), true);
      if (!is_array($sites) || !$sites) {
        if (str($v['company_name'] ?? '') === '') continue;
        $sites = [['type' => $v['visit_type'] === 'onsite' ? 'onsite' : 'online', 'company' => $v['company_name'], 'major' => '', 'students' => '']];
      }
      foreach ($sites as &$x) if (is_array($x)) $x['major'] = $dept;
      unset($x);
      $json = json_encode(array_values($sites), JSON_UNESCAPED_UNICODE);
      if ($json !== str($v['sites'] ?? '')) { $v['sites'] = $json; $changed = true; }
    }
    unset($v);
    if ($changed) writeTable('SupervisionVisits', $rows);
    kvPut($flag, date('c'));
  });
}

/** ตารางสถานประกอบการในแบบรายงานนิเทศ: [{type: onsite|online, company, major, students}] */
function visit_clean_sites($sites): array {
  if (is_string($sites)) $sites = json_decode($sites, true);
  if (!is_array($sites)) return [];
  $out = [];
  foreach (array_slice(array_values($sites), 0, 20) as $x) {
    if (!is_array($x)) continue;
    $n = trim(str($x['students'] ?? ''));
    $row = ['type' => ($x['type'] ?? '') === 'onsite' ? 'onsite' : 'online', 'company' => clean_text($x['company'] ?? '', 300), 'major' => clean_text($x['major'] ?? '', 200),
      'students' => preg_match('/^\d{1,4}$/', $n) ? $n : ''];
    if ($row['company'] === '' && $row['major'] === '' && $row['students'] === '') continue;
    $out[] = $row;
  }
  return $out;
}

function api_visitSave($token = '', $rec = []) {
  $me = auth_user($token);
  $rec = (array)$rec;
  supervise_unit($me, $rec['semester_id'] ?? '', $rec['unit_key'] ?? '');
  if (!valid_date($rec['visit_date'] ?? '')) throw new Exception('กรุณาระบุวันที่นิเทศ');
  if (js_true($rec['next_visit_date'] ?? '') && !valid_date($rec['next_visit_date'])) throw new Exception('วันที่นิเทศครั้งถัดไปไม่ถูกต้อง');
  $type = ($rec['visit_type'] ?? '') === 'onsite' ? 'onsite' : 'online';
  $status = ($rec['status'] ?? '') === 'planned' ? 'planned' : 'done';
  if ($status === 'done' && $rec['visit_date'] > date('Y-m-d')) throw new Exception('บันทึกผลนิเทศล่วงหน้าไม่ได้ ถ้ายังไม่ถึงวันให้เลือก "นัดหมาย"');
  $meet = clean_link($rec['meeting_link'] ?? ''); $evid = array_key_exists('evidence_link', $rec) ? clean_link($rec['evidence_link']) : null;
  if ($status === 'done' && clean_text($rec['findings'] ?? '') === '') throw new Exception('กรุณาบันทึกงานที่นักเรียนนักศึกษาได้ปฏิบัติ');
  $time = trim(str($rec['visit_time'] ?? ''));
  if ($time !== '' && !preg_match('/^([01]\d|2[0-3])[:.][0-5]\d$/', $time)) throw new Exception('เวลานิเทศไม่ถูกต้อง');
  $week = trim(str($rec['week_no'] ?? ''));
  if ($week !== '' && (!preg_match('/^[0-9]{1,2}$/', $week) || (int)$week < 1 || (int)$week > 40)) throw new Exception('สัปดาห์ที่นิเทศต้องเป็นตัวเลข 1–40');
  $sites = visit_clean_sites($rec['sites'] ?? null);
  if ($sites) { $type = $sites[0]['type']; $rec['company_name'] = $sites[0]['company']; }
  return with_lock(function () use ($me, $rec, $type, $status, $meet, $evid, $time, $week, $sites) {
    $rows = readTable('SupervisionVisits');
    $id = $rec['id'] ?? '';
    $i = js_true($id) ? find_row($rows, $id) : null;
    if ($i !== null && ((string)$rows[$i]['unit_key'] !== str($rec['unit_key']) || (string)$rows[$i]['semester_id'] !== str($rec['semester_id']))) throw new Exception('รายการนิเทศไม่ตรงกับวิชา');
    if ($i === null) { $rows[] = ['id' => new_id(), 'created_by' => $me['name'], 'created_at' => now_dt()]; $i = count($rows) - 1; }
    $rows[$i] = array_merge($rows[$i], [
      'semester_id' => $rec['semester_id'], 'unit_key' => $rec['unit_key'], 'company_name' => clean_text($rec['company_name'] ?? '', 300), 'visit_date' => $rec['visit_date'], 'visit_type' => $type, 'meeting_link' => $meet, 'topic' => clean_text($rec['topic'] ?? '', 500),
      'findings' => clean_text($rec['findings'] ?? ''), 'suggestions' => clean_text($rec['suggestions'] ?? ''), 'evidence_link' => $evid === null ? ($rows[$i]['evidence_link'] ?? '') : $evid,
      'next_visit_date' => $rec['next_visit_date'] ?? '', 'status' => $status,
      'visit_time' => str_replace('.', ':', $time), 'week_no' => $week, 'sites' => $sites ? json_encode($sites, JSON_UNESCAPED_UNICODE) : '', 'tasks_assigned' => clean_text($rec['tasks_assigned'] ?? ''),
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
    supervise_unit($me, $rows[$i]['semester_id'], $rows[$i]['unit_key']);
    foreach (report_image_names($rows[$i]) as $name) @unlink(report_image_dir() . '/' . $name);
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
  stable_usort($keyed, fn($a, $b) => [$a['s'], $a['i']] <=> [$b['s'], $b['i']]);
  return array_map(fn($k) => $k['w'], $keyed);
}

function api_windowList($token = '', $semesterId = '') {
  auth_user($token, 'curriculum');
  return windows_of($semesterId);
}

/** เพิ่มคิวให้แผนกที่ยังไม่มีในภาคเรียนนี้ โดยยังไม่กำหนดวันที่ */
function api_windowCreateMissing($token = '', $semesterId = '') {
  $me = auth_user($token, 'curriculum');
  if (find_row(readTable('Semesters'), str($semesterId)) === null) throw new Exception('ไม่พบภาคเรียน');
  return with_lock(function () use ($me, $semesterId) {
    $cur = windows_of($semesterId);
    $has = array_column($cur, 'department_id');
    $depts = readTable('Departments');
    stable_usort($depts, fn($a, $b) => [num($a['sort']), $a['name']] <=> [num($b['sort']), $b['name']]);
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
  $me = auth_user($token, 'curriculum');
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
  $me = auth_user($token, 'curriculum');
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
  $me = auth_user($token, 'curriculum');
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
  $me = auth_user($token, 'curriculum');
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
/** งบหน้าทำทั้งภาคเรียนแยกตามระดับ นับจากวันเปิดภาคเรียน ไม่ขึ้นกับช่วงของรอบการเบิก
 *  ค่าเริ่มต้น ปวช. 18 สัปดาห์ ปวส. 15 สัปดาห์ และเลือก 15 หรือ 18 สัปดาห์เองได้ทุกระดับ */
const OT_COVER_WEEKS = ['vc' => 18, 'vs' => 15];
const OT_COVER_WEEK_CHOICES = [15, 18];

function api_otCoverSheet($token = '', $semesterId = '', $deptId = '', $level = 'vc', $weeks = '') {
  $me = auth_user($token, 'dept');
  if ($me['role'] === 'dept' && str($deptId) !== (string)$me['department_id']) throw new Exception('ดูงบหน้าได้เฉพาะแผนกตัวเอง');
  if (!isset(OT_COVER_WEEKS[str($level)])) throw new Exception('ระดับไม่ถูกต้อง');
  $count = in_array((int)$weeks, OT_COVER_WEEK_CHOICES, true) ? (int)$weeks : OT_COVER_WEEKS[str($level)];
  $sem = idx_table('Semesters')[str($semesterId)] ?? null;
  if (!$sem) throw new Exception('ไม่พบภาคเรียน');
  $rounds = array_values(array_filter(readTable('OtRounds'), fn($r) => (string)$r['semester_id'] === str($semesterId)));
  stable_usort($rounds, fn($a, $b) => strcmp((string)$a['start_date'], (string)$b['start_date']));
  // ยังไม่ตั้งวันเปิดภาคเรียน: นับจากรอบการเบิกแรกไปก่อน และแจ้งให้ตั้งค่า
  $start = substr((string)($sem['start_date'] ?? ''), 0, 10);
  $startSet = $start !== '';
  if (!$startSet) $start = $rounds ? substr((string)$rounds[0]['start_date'], 0, 10) : '';
  if ($start === '') return ['semester' => $sem, 'level' => str($level), 'week_count' => $count, 'noDates' => true, 'weeks' => [], 'rows' => [], 'notLocked' => []];
  $until = ot_parse($start)->modify('+' . (($count + 1) * 7) . ' days');
  $weeks = array_slice(ot_weeks_of(['start_date' => $start, 'end_date' => ot_ymd($until)]), 0, $count);
  $weekOf = [];
  foreach ($weeks as $w) foreach ($w['dates'] as $d) $weekOf[$d] = $w['index'];
  $weekTotals = array_fill_keys(array_column($weeks, 'index'), 0);

  $byTeacher = []; $notLocked = []; $outside = 0;
  $claims = readTable('OtClaims');
  foreach ($rounds as $round) {
    $summary = [];
    foreach ($claims as $c) if ((string)$c['round_id'] === (string)$round['id']) $summary[$c['teacher_id']] ??= json_decode((string)$c['summary'], true) ?: [];
    foreach (ot_dept_summary($round['id'], $deptId)['rows'] as $r) {
      if ($r['status'] !== 'locked') { $notLocked[$r['name']] = true; continue; }
      foreach (ot_weeks_of($round) as $w) {
        $lv = $summary[$r['teacher_id']]['weeks'][$w['index']]['levels'][str($level)] ?? null;
        if (!$lv || (!$lv['amount'] && !$lv['hours'])) continue;
        $idx = $weekOf[$w['dates'][0]] ?? null;
        if ($idx === null) { $outside += $lv['amount']; continue; } // เกินจำนวนสัปดาห์ของระดับนี้ ไม่นำมาเบิก
        $t = &$byTeacher[$r['teacher_id']];
        $t ??= ['teacher_id' => $r['teacher_id'], 'name' => $r['name'], 'position' => $r['position'], 'week_amounts' => [], 'hours' => 0, 'amount' => 0];
        $t['week_amounts'][$idx] = ($t['week_amounts'][$idx] ?? 0) + $lv['amount'];
        $t['hours'] += $lv['hours']; $t['amount'] += $lv['amount'];
        $weekTotals[$idx] += $lv['amount'];
        unset($t);
      }
    }
  }
  $rows = array_values($byTeacher);
  foreach ($rows as &$x) $x['week_amounts'] = (object)$x['week_amounts'];
  unset($x);
  $total = array_sum(array_column($rows, 'amount'));
  return [
    'semester' => $sem, 'level' => str($level), 'week_count' => $count, 'startSet' => $startSet, 'start' => $start, 'noRounds' => !$rounds,
    'weeks' => array_map(fn($w) => ['index' => $w['index'], 'from' => $w['dates'][0], 'to' => $w['dates'][count($w['dates']) - 1]], $weeks),
    'rows' => $rows, 'totals' => ['hours' => array_sum(array_column($rows, 'hours')), 'amount' => $total], 'weekTotals' => (object)$weekTotals,
    'totalText' => ot_baht_text($total), 'notLocked' => array_keys($notLocked), 'outside' => $outside,
  ];
}

// ---------------- งบหน้าประมาณการ ----------------
/**
 * เลือกชั่วโมงเบิกอัตโนมัติของ 1 สัปดาห์ตามแบบการเบิก สำหรับงบหน้าประมาณการเท่านั้น (ไม่บันทึกลงใบเบิก)
 * ใช้คาบสถานประกอบการนับภาระก่อน ครบภาระแล้วที่เหลือเป็นชั่วโมงเบิก ไม่เกินสูงสุดของระดับ
 * เกินเพดานรวม: แบบรวมแล้วตัดให้ ot_eval_week ตัดตามระเบียบ แบบอื่นตัดระดับที่อัตราต่ำก่อน
 */
function ot_sim_select(array $items, array $sch, $cap): array {
  $by = [];
  foreach ($items as $it) $by[$it['level']][] = $it;
  $sel = []; $claim = []; $short = false;
  foreach ($by as $lv => $list) {
    $L = $sch['levels'][$lv] ?? null;
    if (!$L || !$L['enabled']) continue;
    stable_usort($list, fn($a, $b) => (int)js_true($b['no_claim'] ?? '') <=> (int)js_true($a['no_claim'] ?? ''));
    $need = (int)$L['load']; $load = []; $rest = [];
    foreach ($list as $it) {
      if (count($load) < $need) $load[] = $it['key'];
      elseif (!js_true($it['no_claim'] ?? '')) $rest[] = $it['key'];
    }
    if (count($load) < $need) { $short = true; continue; } // ภาระไม่ครบ ระดับนี้เบิกไม่ได้
    foreach ($load as $k) $sel[$k] = 'L';
    $claim[$lv] = $L['max'] ? array_slice($rest, 0, (int)$L['max']) : $rest;
  }
  $method = $sch['cap_method'];
  if ($method === 'fill_first' && $short) $claim = []; // แบบวันหยุดต้องครบภาระทุกระดับก่อน
  $rate = fn($l) => $sch['levels'][$l]['rate'];
  $lowFirst = array_keys($claim);
  stable_usort($lowFirst, fn($a, $b) => $rate($a) <=> $rate($b));
  if ($method !== 'free') {
    // หลายระดับ: ระดับที่เบิกน้อยกว่าขั้นต่ำให้ตัดออก
    foreach ($lowFirst as $l) {
      $active = count(array_filter($claim, 'count'));
      if ($active > 1 && $claim[$l] && $sch['levels'][$l]['min'] && count($claim[$l]) < $sch['levels'][$l]['min']) $claim[$l] = [];
    }
  }
  if ($method !== 'combined_trim') {
    $totalCap = $method === 'none' ? 999 : min($sch['total_cap'] ?: 999, $cap);
    if ($method === 'none' && $cap < 12) $totalCap = $cap;
    $over = array_sum(array_map('count', $claim)) - $totalCap;
    foreach ($lowFirst as $l) {
      if ($over <= 0) break;
      $cut = min($over, count($claim[$l]));
      $claim[$l] = array_slice($claim[$l], 0, count($claim[$l]) - $cut);
      $over -= $cut;
    }
  }
  foreach ($claim as $keys) foreach ($keys as $k) $sel[$k] = 'C';
  return $sel;
}

/** ยอดประมาณการของครูแต่ละคนจากตารางสอนและแบบการเบิกที่กำหนดให้ (ไม่หักวันหยุด/วันลา) */
function ot_sim_teachers(array $teachers, array $round, string $level, array $weekOf, array $X): array {
  $rows = []; $noScheme = []; $warn = [];
  foreach ($teachers as $t) {
    $schemes = ot_teacher_schemes($t, $X);
    if (!$schemes) { $noScheme[] = $t['name']; continue; }
    $weeks = ot_hours_for(['settings' => $X['settings'], 'teacher' => $t, 'round' => $round, 'sessions' => $X['sessions'], 'groups' => $X['groups'], 'courses' => $X['courses'], 'absences' => [], 'blocked' => [], 'subs' => []]);
    $cap = ot_personal_cap($t, $X['settings'], $round['start_date'])['cap'];
    $ctx = ['subQuota' => [], 'perHead' => [], 'courses' => $X['courses'], 'refAmount' => ot_ref_amount($t, $X, $cap)];
    $best = null;
    foreach ($schemes as $sch) { // ครูมีหลายแบบ: ใช้แบบที่ได้ยอดรวมทั้งภาคเรียนสูงสุด
      $res = ['scheme' => $sch['name'], 'all' => 0, 'weeks' => [], 'errors' => []];
      foreach ($weeks as $w) {
        if (!$w['items']) continue;
        $r = ot_eval_week($w['items'], ot_sim_select($w['items'], $sch, $cap), [], $sch, $cap, $ctx);
        $res['all'] += $r['amount'];
        $res['weeks'][$w['index']] = $r['levels'][$level] ?? ['hours' => 0, 'amount' => 0];
        foreach ($r['errors'] as $e) $res['errors'][$e] = true;
      }
      if (!$best || $res['all'] > $best['all']) $best = $res;
    }
    if ($best['errors']) $warn[] = $t['name'] . ' (' . $best['scheme'] . '): ' . implode(' · ', array_slice(array_keys($best['errors']), 0, 2));
    $row = ['teacher_id' => $t['id'], 'name' => $t['name'], 'position' => $t['position'], 'department_id' => $t['department_id'], 'scheme' => $best['scheme'], 'week_amounts' => [], 'hours' => 0, 'amount' => 0];
    foreach ($best['weeks'] as $i => $lv) {
      if (!$lv['amount'] && !$lv['hours']) continue;
      $idx = $weekOf[$i] ?? null;
      if ($idx === null) continue;
      $row['week_amounts'][$idx] = ($row['week_amounts'][$idx] ?? 0) + $lv['amount'];
      $row['hours'] += $lv['hours']; $row['amount'] += $lv['amount'];
    }
    if ($row['hours'] || $row['amount']) $rows[] = $row;
  }
  return ['rows' => $rows, 'noScheme' => $noScheme, 'warnings' => $warn];
}

/**
 * งบหน้าประมาณการ: ประมาณการค่าสอนเกินภาระงานทั้งภาคเรียนจากตารางสอนและแบบการเบิกที่กำหนดให้ครู
 * คำนวณสดและอ่านอย่างเดียว ไม่เขียนข้อมูลใด ๆ จึงไม่กระทบใบเบิกและงบหน้าจริง
 * แผนกดูได้เฉพาะแผนกตัวเอง งานพัฒนาหลักสูตรฯ/ผู้ดูแลเลือก __all เพื่อสรุปรวมรายแผนกได้
 */
/** ผู้ทำงบหน้าประมาณการได้: วิชาการแผนก งานพัฒนาหลักสูตรฯ และผู้ดูแลระบบ (ตามบทบาทจริงของบัญชี) */
const OT_SIM_ROLES = ['dept', 'curriculum', 'admin'];

function api_otCoverSim($token = '', $semesterId = '', $deptId = '', $level = 'vc', $weeks = '') {
  $me = auth_user($token, 'dept');
  if (!in_array($me['base_role'] ?? '', OT_SIM_ROLES, true)) throw new Exception('งบหน้าประมาณการใช้ได้เฉพาะวิชาการแผนก งานพัฒนาหลักสูตรฯ และผู้ดูแลระบบ');
  $all = str($deptId) === '__all';
  if ($me['role'] === 'dept' && ($all || str($deptId) !== (string)$me['department_id'])) throw new Exception('ดูงบหน้าได้เฉพาะแผนกตัวเอง');
  if (!isset(OT_COVER_WEEKS[str($level)])) throw new Exception('ระดับไม่ถูกต้อง');
  $count = in_array((int)$weeks, OT_COVER_WEEK_CHOICES, true) ? (int)$weeks : OT_COVER_WEEKS[str($level)];
  $sem = idx_table('Semesters')[str($semesterId)] ?? null;
  if (!$sem) throw new Exception('ไม่พบภาคเรียน');
  $start = substr((string)($sem['start_date'] ?? ''), 0, 10);
  $startSet = $start !== '';
  if (!$startSet) {
    $rounds = array_values(array_filter(readTable('OtRounds'), fn($r) => (string)$r['semester_id'] === str($semesterId)));
    stable_usort($rounds, fn($a, $b) => strcmp((string)$a['start_date'], (string)$b['start_date']));
    $start = $rounds ? substr((string)$rounds[0]['start_date'], 0, 10) : '';
  }
  if ($start === '') return ['sim' => true, 'semester' => $sem, 'level' => str($level), 'week_count' => $count, 'noDates' => true, 'weeks' => [], 'rows' => []];
  $until = ot_parse($start)->modify('+' . (($count + 1) * 7) . ' days');
  $wk = array_slice(ot_weeks_of(['start_date' => $start, 'end_date' => ot_ymd($until)]), 0, $count);
  $last = $wk[count($wk) - 1]['dates'];
  $round = ['id' => '', 'semester_id' => str($semesterId), 'start_date' => $start, 'end_date' => $last[count($last) - 1]];
  $weekOf = array_combine(array_column($wk, 'index'), array_column($wk, 'index'));

  $plans = idx_table('Plans'); $courses = idx_table('Courses');
  $X = [
    'settings' => readSettings(), 'groups' => idx_table('Groups'), 'courses' => $courses,
    'schemes' => array_map('ot_scheme', readTable('OtSchemes')),
    'sessions' => array_map(function ($s) use ($plans, $courses) {
      $s['workplace'] = sched_is_workplace($s, ['plans' => $plans, 'courses' => $courses]);
      return $s;
    }, sem_sessions($semesterId)),
  ];
  $teachers = array_values(array_filter(readTable('Teachers'), fn($t) => (string)$t['ot_enabled'] === 'true' && ($all || (string)$t['department_id'] === str($deptId))));
  $sim = ot_sim_teachers($teachers, $round, str($level), $weekOf, $X);
  $rows = $sim['rows'];
  if ($all) { // สรุปรวมรายแผนก: 1 แถวต่อแผนก
    $depts = idx_table('Departments'); $by = [];
    foreach ($rows as $r) {
      $id = (string)$r['department_id'];
      $d = &$by[$id];
      $d ??= ['department_id' => $id, 'name' => $depts[$id]['name'] ?? 'ไม่ระบุแผนก', 'position' => '', 'people' => 0, 'week_amounts' => [], 'hours' => 0, 'amount' => 0];
      $d['people']++; $d['hours'] += $r['hours']; $d['amount'] += $r['amount'];
      foreach ($r['week_amounts'] as $i => $a) $d['week_amounts'][$i] = ($d['week_amounts'][$i] ?? 0) + $a;
      unset($d);
    }
    $rows = array_values($by);
    stable_usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
  }
  $weekTotals = array_fill_keys(array_column($wk, 'index'), 0);
  foreach ($rows as $r) foreach ($r['week_amounts'] as $i => $a) $weekTotals[$i] += $a;
  foreach ($rows as &$x) $x['week_amounts'] = (object)$x['week_amounts'];
  unset($x);
  $total = array_sum(array_column($rows, 'amount'));
  return [
    'sim' => true, 'all' => $all, 'semester' => $sem, 'level' => str($level), 'week_count' => $count, 'startSet' => $startSet, 'start' => $start,
    'weeks' => array_map(fn($w) => ['index' => $w['index'], 'from' => $w['dates'][0], 'to' => $w['dates'][count($w['dates']) - 1]], $wk),
    'rows' => $rows, 'people' => count($sim['rows']), 'totals' => ['hours' => array_sum(array_column($rows, 'hours')), 'amount' => $total], 'weekTotals' => (object)$weekTotals,
    'totalText' => ot_baht_text($total), 'noScheme' => $sim['noScheme'], 'warnings' => $sim['warnings'], 'notLocked' => [], 'outside' => 0,
  ];
}

/**
 * งบหน้าประกอบการขออนุมัติเวลาทำงานของสถานศึกษา (แบบฟอร์ม 12 ข้อ) ของ 1 แผนก จากข้อมูลประมาณการ
 * คิดจากสัปดาห์เต็ม 1 สัปดาห์ตามตารางสอน: สอนทั้งหมด = คาบสอนต่อสัปดาห์, สอนพิเศษ = ชม.เบิกต่อสัปดาห์,
 * ในเวลาราชการ = ส่วนที่เหลือ และเงิน = ชม.เบิก × อัตรา × จำนวนสัปดาห์ของระดับ (ปวช. 18 / ปวส. 15)
 * อ่านอย่างเดียว ไม่บันทึกข้อมูล
 */
function api_otCoverApproval($token = '', $semesterId = '', $deptId = '') {
  $me = auth_user($token, 'dept');
  if (!in_array($me['base_role'] ?? '', OT_SIM_ROLES, true)) throw new Exception('งบหน้าประมาณการใช้ได้เฉพาะวิชาการแผนก งานพัฒนาหลักสูตรฯ และผู้ดูแลระบบ');
  if ($me['role'] === 'dept' && str($deptId) !== (string)$me['department_id']) throw new Exception('ดูงบหน้าได้เฉพาะแผนกตัวเอง');
  $dept = idx_table('Departments')[str($deptId)] ?? null;
  if (!$dept) throw new Exception('กรุณาเลือกแผนกวิชา');
  $sem = idx_table('Semesters')[str($semesterId)] ?? null;
  if (!$sem) throw new Exception('ไม่พบภาคเรียน');
  $start = substr((string)($sem['start_date'] ?? ''), 0, 10);
  if ($start === '') return ['noDates' => true, 'semester' => $sem];
  $mon = ot_parse($start)->modify('-' . ot_dow(ot_parse($start)) . ' days');
  $round = ['id' => '', 'semester_id' => str($semesterId), 'start_date' => ot_ymd($mon), 'end_date' => ot_ymd($mon->modify('+6 days'))];

  $plans = idx_table('Plans'); $courses = idx_table('Courses'); $groups = idx_table('Groups');
  $sessions = array_map(function ($s) use ($plans, $courses) {
    $s['workplace'] = sched_is_workplace($s, ['plans' => $plans, 'courses' => $courses]);
    return $s;
  }, sem_sessions($semesterId));
  $X = ['settings' => readSettings(), 'groups' => $groups, 'courses' => $courses, 'sessions' => $sessions, 'schemes' => array_map('ot_scheme', readTable('OtSchemes'))];
  $weeksOf = OT_COVER_WEEKS + ['bd' => OT_COVER_WEEKS['vs']];

  $rows = []; $costs = []; $warn = []; $noScheme = [];
  foreach (readTable('Teachers') as $t) {
    if ((string)$t['department_id'] !== str($deptId)) continue;
    $week = ot_hours_for(['settings' => $X['settings'], 'teacher' => $t, 'round' => $round, 'sessions' => $sessions, 'groups' => $groups, 'courses' => $courses, 'absences' => [], 'blocked' => [], 'subs' => []])[0] ?? ['items' => []];
    $taught = count($week['items']);
    if (!$taught) continue; // ไม่มีคาบสอนในภาคเรียนนี้
    $best = null;
    if ((string)$t['ot_enabled'] === 'true' && $week['items']) {
      $cap = ot_personal_cap($t, $X['settings'], $start)['cap'];
      $ctx = ['subQuota' => [], 'perHead' => [], 'courses' => $courses, 'refAmount' => ot_ref_amount($t, $X, $cap)];
      foreach (ot_teacher_schemes($t, $X) as $sch) { // หลายแบบ: ใช้แบบที่ได้เงินทั้งภาคเรียนสูงสุด
        $r = ot_eval_week($week['items'], ot_sim_select($week['items'], $sch, $cap), [], $sch, $cap, $ctx);
        $lv = []; $sum = 0;
        foreach (OT_LEVELS as $l) {
          $h = $r['levels'][$l]['hours'] ?? 0;
          if (!$h) continue;
          $rate = $sch['levels'][$l]['rate'];
          $lv[$l] = ['hours' => $h, 'rate' => $rate, 'weeks' => $weeksOf[$l], 'amount' => $h * $rate * $weeksOf[$l]];
          $sum += $lv[$l]['amount'];
        }
        if (!$best || $sum > $best['amount']) $best = ['scheme' => $sch['name'], 'levels' => $lv, 'amount' => $sum, 'errors' => $r['errors']];
      }
    }
    $extra = $best ? array_sum(array_column($best['levels'], 'hours')) : 0;
    if (!$best) $noScheme[] = $t['name'];
    if ($best && $best['errors']) $warn[] = $t['name'] . ' (' . $best['scheme'] . '): ' . implode(' · ', array_slice(array_unique($best['errors']), 0, 2));
    $rows[] = ['teacher_id' => $t['id'], 'name' => $t['name'], 'position' => $t['position'], 'is_dept_head' => (string)$t['is_dept_head'] === 'true',
      'taught' => $taught, 'inside' => max(0, $taught - $extra), 'extra' => $extra, 'amount' => $best['amount'] ?? 0, 'levels' => (object)($best['levels'] ?? [])];
    foreach ($best['levels'] ?? [] as $l => $x) {
      $k = $l . '|' . $x['rate'];
      $c = &$costs[$k];
      $c ??= ['level' => $l, 'rate' => $x['rate'], 'weeks' => $x['weeks'], 'hours' => 0, 'amount' => 0];
      $c['hours'] += $x['hours']; $c['amount'] += $x['amount'];
      unset($c);
    }
  }
  $costs = array_values($costs);
  stable_usort($costs, fn($a, $b) => [array_search($a['level'], OT_LEVELS), $b['rate']] <=> [array_search($b['level'], OT_LEVELS), $a['rate']]);

  // ห้องเรียน/กลุ่มเรียนของแผนกที่มีคาบสอนในภาคเรียนนี้ แยกตามระดับและชั้นปี
  $used = array_flip(array_map(fn($s) => (string)$s['group_id'], $sessions));
  $classes = [];
  foreach ($groups as $g) {
    if ((string)$g['department_id'] !== str($deptId) || !isset($used[(string)$g['id']])) continue;
    $l = ot_level_of($g);
    $y = trim((string)($g['year_level'] ?? '')) ?: (string)$g['level'];
    $c = &$classes[$l]['years'][$y];
    $c ??= ['year' => $y, 'codes' => [], 'students' => 0];
    $c['codes'][] = $g['code']; $c['students'] += num($g['students'] ?? 0);
    unset($c);
  }
  $levels = [];
  foreach (OT_LEVELS as $l) {
    if (empty($classes[$l])) continue;
    $years = array_values($classes[$l]['years']);
    stable_usort($years, fn($a, $b) => strnatcmp($a['year'], $b['year']));
    foreach ($years as &$y) natsort($y['codes']);
    unset($y);
    $levels[] = ['level' => $l, 'label' => OT_LEVEL_TH[$l], 'years' => array_map(fn($y) => ['year' => $y['year'], 'codes' => array_values($y['codes']), 'students' => $y['students']], $years),
      'rooms' => array_sum(array_map(fn($y) => count($y['codes']), $years)), 'students' => array_sum(array_column($years, 'students'))];
  }
  $total = array_sum(array_column($costs, 'amount'));
  return [
    'semester' => $sem, 'department' => ['id' => $dept['id'], 'name' => $dept['name']], 'week' => ['from' => $round['start_date'], 'to' => $round['end_date']],
    'rows' => $rows, 'costs' => $costs, 'levels' => $levels, 'warnings' => $warn, 'noScheme' => $noScheme,
    'totals' => ['taught' => array_sum(array_column($rows, 'taught')), 'inside' => array_sum(array_column($rows, 'inside')), 'extra' => array_sum(array_column($rows, 'extra')), 'amount' => $total],
    'totalText' => ot_baht_text($total),
  ];
}

// ======================= แจ้งปัญหาการใช้งาน =======================
const ISSUE_STATUS = ['open', 'doing', 'done'];

/** ผู้ดูแลระบบเห็นทุกเรื่อง ผู้ใช้อื่นเห็นเฉพาะเรื่องที่ตัวเองแจ้ง */
function api_issueList($token = '') {
  $me = auth_user($token);
  $rows = readTable('IssueReports');
  if ($me['role'] !== 'admin') $rows = array_filter($rows, fn($r) => (string)$r['reporter_id'] === (string)$me['id']);
  $rows = array_values($rows);
  stable_usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
  return $rows;
}

function api_issueSave($token = '', $rec = []) {
  $me = auth_user($token);
  $rec = (array)$rec;
  $title = clean_text($rec['title'] ?? '', 200); $detail = clean_text($rec['detail'] ?? '', 5000);
  if ($title === '') throw new Exception('กรุณาระบุหัวข้อ');
  if ($detail === '') throw new Exception('กรุณาอธิบายปัญหา');
  return with_lock(function () use ($me, $rec, $title, $detail) {
    $id = new_id();
    appendRows('IssueReports', [['id' => $id, 'title' => $title, 'category' => clean_text($rec['category'] ?? '', 50), 'detail' => $detail, 'status' => 'open', 'reply' => '', 'replied_by' => '', 'reporter_id' => $me['id'], 'reporter_name' => $me['name'], 'created_at' => now_dt(), 'updated_at' => now_dt()]]);
    log_action($me, 'แจ้งปัญหา', $id);
    return $id;
  });
}

function api_issueReply($token = '', $id = '', $status = '', $reply = '') {
  $me = auth_user($token, 'admin');
  if (!in_array($status, ISSUE_STATUS, true)) throw new Exception('สถานะไม่ถูกต้อง');
  return with_lock(function () use ($me, $id, $status, $reply) {
    $rows = readTable('IssueReports');
    $i = find_row($rows, str($id));
    if ($i === null) throw new Exception('ไม่พบเรื่องที่แจ้ง');
    $reply = clean_text($reply, 5000);
    // ผู้ดูแลตอบใหม่แต่ยังไม่ได้เปลี่ยนสถานะ (ยังเป็น "รอดำเนินการ") ให้เป็น "กำลังดำเนินการ" ให้ผู้แจ้งรู้ว่ามีคนรับเรื่องแล้ว
    if ($status === 'open' && $reply !== '' && $reply !== (string)($rows[$i]['reply'] ?? '')) $status = 'doing';
    $rows[$i] = array_merge($rows[$i], ['status' => $status, 'reply' => $reply, 'replied_by' => $me['name'], 'updated_at' => now_dt()]);
    writeTable('IssueReports', $rows);
    log_action($me, 'ตอบเรื่องแจ้งปัญหา', str($id));
    return true;
  });
}

function api_issueDelete($token = '', $id = '') {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id) {
    $rows = readTable('IssueReports');
    $i = find_row($rows, str($id));
    if ($i === null) throw new Exception('ไม่พบเรื่องที่แจ้ง');
    // ผู้แจ้งลบได้เฉพาะเรื่องของตัวเองที่ยังไม่เริ่มดำเนินการ
    if ($me['role'] !== 'admin' && ((string)$rows[$i]['reporter_id'] !== (string)$me['id'] || $rows[$i]['status'] !== 'open')) throw new Exception('ลบได้เฉพาะเรื่องของตัวเองที่ยังไม่เริ่มดำเนินการ');
    array_splice($rows, $i, 1);
    writeTable('IssueReports', $rows);
    log_action($me, 'ลบเรื่องแจ้งปัญหา', str($id));
    return true;
  });
}
