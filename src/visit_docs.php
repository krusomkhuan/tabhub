<?php
/**
 * รายงานการนิเทศนักศึกษาฝึกอาชีพในสถานประกอบการ (แบบรายงานรายสัปดาห์ของวิทยาลัย)
 * บันทึกผลนิเทศทีละรายวิชาใน SupervisionVisits แล้วรวมหลายวิชาของครูคนเดียวเป็นเอกสารฉบับเดียว เก็บไว้ใน SupervisionDocs
 * รูปในเอกสารแยกเก็บเป็นไฟล์เหมือนเอกสารสอนแทน/สอนชดเชย (sub_doc_store_images)
 */

/** รายการนิเทศที่ผู้ใช้เปิดได้ของครูคนนี้ในภาคเรียน พร้อมข้อมูลรายวิชา */
function visit_teacher_rows(array $me, $semesterId, $teacherId): array {
  $units = supervise_units($semesterId);
  $out = [];
  foreach (readTable('SupervisionVisits') as $v) {
    if ((string)$v['semester_id'] !== str($semesterId)) continue;
    $u = $units[$v['unit_key']] ?? null;
    if (!$u || !in_array(str($teacherId), array_map('strval', $u['teacher_ids']), true) || !supervise_can_see($me, $u)) continue;
    $v['course_id'] = $u['course_id']; $v['group_ids'] = $u['group_ids'];
    $out[] = $v;
  }
  stable_usort($out, fn($a, $b) => strcmp($a['visit_date'] . $a['visit_time'], $b['visit_date'] . $b['visit_time']));
  return $out;
}

/** ตรวจสิทธิ์และคืนชุดรายการของเอกสาร: ทุกรายการต้องเป็นวิชาของครูคนนี้ในภาคเรียนเดียวกัน */
function visit_doc_scope(array $me, $semesterId, $teacherId, $visitIds): array {
  if ($me['role'] === 'teacher' && (string)$me['teacher_id'] !== str($teacherId)) throw new Exception('ครูจัดทำรายงานการนิเทศได้เฉพาะของตนเอง');
  $ids = array_values(array_unique(array_filter(array_map('str', is_array($visitIds) ? $visitIds : explode(',', str($visitIds))), fn($x) => $x !== '')));
  if (!$ids || count($ids) > 50) throw new Exception('เลือกรายการนิเทศอย่างน้อย 1 รายการ');
  $mine = [];
  foreach (visit_teacher_rows($me, $semesterId, $teacherId) as $v) $mine[(string)$v['id']] = 1;
  foreach ($ids as $id) if (!isset($mine[$id])) throw new Exception('รายการนิเทศไม่ตรงกับครูนิเทศก์ หรือไม่มีสิทธิ์');
  sort($ids);
  return $ids;
}

function visit_doc_find(string $id): ?array {
  assert_table('SupervisionDocs');
  $st = db()->prepare('SELECT * FROM `SupervisionDocs` WHERE `id` = ?');
  $st->execute([$id]);
  $r = $st->fetch();
  if (!$r) return null;
  unset($r['_seq']);
  return array_map(fn($v) => $v ?? '', $r);
}

/** ผู้จัดการเปิดได้ทุกฉบับ ครูเปิดได้เฉพาะของตนเอง วิชาการแผนกเปิดได้เมื่อมีรายการที่ตนดูได้ */
function visit_doc_assert(array $me, array $doc): void {
  if (is_academic_manager($me)) return;
  if ($me['role'] === 'teacher') {
    if ((string)$me['teacher_id'] !== (string)$doc['teacher_id']) throw new Exception('ไม่มีสิทธิ์เปิดรายงานนี้');
    return;
  }
  $visible = [];
  foreach (visit_teacher_rows($me, $doc['semester_id'], $doc['teacher_id']) as $v) $visible[(string)$v['id']] = 1;
  if (!array_intersect_key(array_flip(explode(',', $doc['visit_ids'])), $visible)) throw new Exception('ไม่มีสิทธิ์เปิดรายงานนี้');
}

function api_visitTeacherList($token = '', $semesterId = '', $teacherId = '') {
  $me = auth_user($token);
  if ($me['role'] === 'teacher' && (string)$me['teacher_id'] !== str($teacherId)) throw new Exception('ครูดูได้เฉพาะรายการนิเทศของตนเอง');
  visit_sites_to_dept_once();
  return visit_teacher_rows($me, $semesterId, $teacherId);
}

function api_visitDocList($token = '', $semesterId = '', $teacherId = '') {
  $me = auth_user($token);
  if ($me['role'] === 'teacher' && (string)$me['teacher_id'] !== str($teacherId)) throw new Exception('ครูดูได้เฉพาะรายงานของตนเอง');
  assert_table('SupervisionDocs');
  $st = db()->prepare('SELECT `id`, `semester_id`, `teacher_id`, `visit_ids`, `title`, `created_by`, `created_at`, `updated_by`, `updated_at` FROM `SupervisionDocs` WHERE `semester_id` = ? AND `teacher_id` = ? ORDER BY `_seq` DESC');
  $st->execute([str($semesterId), str($teacherId)]);
  $out = [];
  foreach ($st->fetchAll() as $r) {
    $r = array_map(fn($v) => $v ?? '', $r);
    try { visit_doc_assert($me, $r); $out[] = $r; } catch (Exception $e) {}
  }
  return $out;
}

function api_visitDocGet($token = '', $id = '') {
  $me = auth_user($token);
  $doc = visit_doc_find(str($id));
  if (!$doc) throw new Exception('ไม่พบรายงาน');
  visit_doc_assert($me, $doc);
  unset($doc['images']);
  $doc['html'] = sub_doc_inline_images($doc['html']);
  return $doc;
}

/** rec: {id?, semester_id, teacher_id, visit_ids[], title, html} — แก้ฉบับเดิมเมื่อส่ง id มา */
function api_visitDocSave($token = '', $rec = []) {
  $me = auth_user($token);
  $rec = (array)$rec;
  $html = is_string($rec['html'] ?? null) ? $rec['html'] : '';
  if (trim($html) === '') throw new Exception('ไม่มีเนื้อหารายงาน');
  if (strlen($html) > SUB_DOC_MAX_HTML + SUB_DOC_MAX_IMAGES * 400 * 1024) throw new Exception('รายงานมีขนาดใหญ่เกินไป');
  // ตรวจเนื้อหาก่อนแยกรูป ข้อมูลรูปเป็น base64 จึงไม่ชนรูปแบบเหล่านี้
  if (preg_match(SUB_DOC_UNSAFE, preg_replace('/data:image\/(?:jpeg|png);base64,[A-Za-z0-9+\/=]+/', '', $html))) throw new Exception('รายงานมีเนื้อหาที่ไม่อนุญาต');
  return with_lock(function () use ($me, $rec, $html) {
    $id = str($rec['id'] ?? '');
    $old = $id !== '' ? visit_doc_find($id) : null;
    if ($id !== '' && !$old) throw new Exception('ไม่พบรายงาน');
    if ($old) { visit_doc_assert($me, $old); $sem = $old['semester_id']; $tid = $old['teacher_id']; }
    else { $sem = str($rec['semester_id'] ?? ''); $tid = str($rec['teacher_id'] ?? ''); $id = new_id(); }
    $ids = visit_doc_scope($me, $sem, $tid, $rec['visit_ids'] ?? ($old['visit_ids'] ?? []));
    $oldImages = $old ? report_image_names($old) : [];
    $created = [];
    try {
      [$clean, $images] = sub_doc_store_images($html, $id, $oldImages, $created);
      if (strlen($clean) > SUB_DOC_MAX_HTML) throw new Exception('รายงานมีขนาดใหญ่เกินไป');
      $title = clean_text($rec['title'] ?? '', 200) ?: ($old['title'] ?? 'รายงานการนิเทศ');
      $now = cell(now_dt());
      if ($old) {
        db()->prepare('UPDATE `SupervisionDocs` SET `visit_ids` = ?, `title` = ?, `html` = ?, `images` = ?, `updated_by` = ?, `updated_at` = ? WHERE `id` = ?')
          ->execute([implode(',', $ids), $title, $clean, json_encode($images), $me['name'], $now, $id]);
      } else {
        db()->prepare('INSERT INTO `SupervisionDocs` (`id`, `semester_id`, `teacher_id`, `visit_ids`, `title`, `html`, `images`, `created_by`, `created_at`, `updated_by`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([$id, $sem, $tid, implode(',', $ids), $title, $clean, json_encode($images), $me['name'], $now, $me['name'], $now]);
      }
    } catch (Throwable $e) {
      foreach ($created as $n) @unlink(report_image_dir() . '/' . $n);
      throw $e;
    }
    foreach (array_diff($oldImages, $images) as $n) @unlink(report_image_dir() . '/' . $n);
    unset(Tbl::$cache['SupervisionDocs']);
    log_action($me, $old ? 'แก้ไขรายงานการนิเทศ' : 'บันทึกรายงานการนิเทศ', $title);
    return ['id' => $id, 'updated_at' => $now];
  });
}

function api_visitDocDelete($token = '', $id = '') {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id) {
    $doc = visit_doc_find(str($id));
    if (!$doc) throw new Exception('ไม่พบรายงาน');
    visit_doc_assert($me, $doc);
    db()->prepare('DELETE FROM `SupervisionDocs` WHERE `id` = ?')->execute([$doc['id']]);
    db()->prepare('DELETE FROM `DocFiles` WHERE `ref` = ?')->execute(['visit:' . $doc['id']]);
    foreach (report_image_names($doc) as $n) @unlink(report_image_dir() . '/' . $n);
    unset(Tbl::$cache['SupervisionDocs']);
    log_action($me, 'ลบรายงานการนิเทศ', $doc['title']);
    return true;
  });
}
