<?php
/**
 * เอกสารที่แก้นอกระบบด้วย Word/Excel
 *  - TeachDocs: รายงานการสอนออนไลน์/รายงานสอนแทน-สอนชดเชยที่แก้แล้วบันทึกเก็บไว้ (เดิมสร้างใหม่จากข้อมูลทุกครั้ง)
 *  - DocFiles:  ไฟล์ .docx/.xlsx ที่อัปโหลดเป็น "ฉบับจริง" ของเอกสาร 1 ไฟล์ต่อเอกสาร
 *    ref = sub:<รหัส SubDocs> | visit:<รหัส SupervisionDocs> | teach:<รหัส TeachDocs>
 *    เก็บเนื้อไฟล์ในฐานข้อมูล (file_data) จึงรวมอยู่ในไฟล์สำรองข้อมูลโดยไม่ต้องแก้ระบบสำรอง
 *  อ่าน/เขียนด้วย SQL ตรงเหมือน SubDocs เพราะเนื้อหาใหญ่ ไม่ควรโหลดทั้งตาราง
 */
const DOC_FILE_MAX_BYTES = 5 * 1024 * 1024;
const DOC_FILE_EXTS = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml'];

function db_row(string $table, string $where, array $args): ?array {
  assert_table($table);
  $st = db()->prepare('SELECT * FROM ' . q($table) . ' WHERE ' . $where . ' ORDER BY `_seq` DESC LIMIT 1');
  $st->execute($args);
  $r = $st->fetch();
  if (!$r) return null;
  unset($r['_seq']);
  return array_map(fn($v) => $v ?? '', $r);
}

// ======================= รายงานการสอนที่บันทึกเก็บไว้ =======================
/** รายงานทุกฉบับในชุดต้องเป็นของครูคนเดียวกัน และผู้ใช้มีสิทธิ์ดูครูคนนั้น */
function teach_doc_scope(array $me, $reportIds): array {
  $ids = array_values(array_unique(array_filter(array_map('str', is_array($reportIds) ? $reportIds : explode(',', str($reportIds))), fn($x) => $x !== '')));
  if (!$ids || count($ids) > 100) throw new Exception('ไม่พบรายงานการสอนของเอกสารนี้');
  sort($ids);
  $all = idx_table('TeachReports');
  $tid = null;
  foreach ($ids as $id) {
    $r = $all[$id] ?? null;
    if (!$r) throw new Exception('ไม่พบรายงานการสอนของเอกสารนี้');
    if ($tid !== null && (string)$r['teacher_id'] !== $tid) throw new Exception('เอกสารหนึ่งฉบับต้องเป็นรายงานของครูคนเดียวกัน');
    $tid = (string)$r['teacher_id'];
  }
  assert_teacher_scope($me, $tid);
  return ['ids' => $ids, 'teacher' => $tid];
}

/** ฉบับที่บันทึกไว้ของชุดรายงานนี้ ไม่มี = null (หน้าเว็บสร้างจากข้อมูลรายงาน) */
function api_teachDocGet($token = '', $reportIds = []) {
  $me = auth_user($token);
  $sc = teach_doc_scope($me, $reportIds);
  $doc = db_row('TeachDocs', '`report_ids` = ?', [implode(',', $sc['ids'])]);
  if (!$doc) return null;
  unset($doc['images']);
  $doc['html'] = sub_doc_inline_images($doc['html']);
  return $doc;
}

/** rec: {report_ids[], title, html} บันทึกทับฉบับเดิมของชุดรายงานเดียวกัน */
function api_teachDocSave($token = '', $rec = []) {
  $me = auth_user($token);
  $rec = (array)$rec;
  $html = is_string($rec['html'] ?? null) ? $rec['html'] : '';
  if (trim($html) === '') throw new Exception('ไม่มีเนื้อหาเอกสาร');
  if (strlen($html) > SUB_DOC_MAX_HTML + SUB_DOC_MAX_IMAGES * 400 * 1024) throw new Exception('เอกสารมีขนาดใหญ่เกินไป');
  if (preg_match(SUB_DOC_UNSAFE, preg_replace('/data:image\/(?:jpeg|png);base64,[A-Za-z0-9+\/=]+/', '', $html))) throw new Exception('เอกสารมีเนื้อหาที่ไม่อนุญาต');
  $sc = teach_doc_scope($me, $rec['report_ids'] ?? []);
  return with_lock(function () use ($me, $rec, $html, $sc) {
    $key = implode(',', $sc['ids']);
    $old = db_row('TeachDocs', '`report_ids` = ?', [$key]);
    $id = $old ? $old['id'] : new_id();
    $oldImages = $old ? report_image_names($old) : [];
    $created = [];
    try {
      [$clean, $images] = sub_doc_store_images($html, $id, $oldImages, $created);
      if (strlen($clean) > SUB_DOC_MAX_HTML) throw new Exception('เอกสารมีขนาดใหญ่เกินไป');
      $title = clean_text($rec['title'] ?? '', 200) ?: ($old['title'] ?? 'รายงานการสอน');
      $now = cell(now_dt());
      if ($old) {
        db()->prepare('UPDATE `TeachDocs` SET `title` = ?, `html` = ?, `images` = ?, `updated_by` = ?, `updated_at` = ? WHERE `id` = ?')
          ->execute([$title, $clean, json_encode($images), $me['name'], $now, $id]);
      } else {
        db()->prepare('INSERT INTO `TeachDocs` (`id`, `report_ids`, `teacher_id`, `title`, `html`, `images`, `created_by`, `created_at`, `updated_by`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([$id, $key, $sc['teacher'], $title, $clean, json_encode($images), $me['name'], $now, $me['name'], $now]);
      }
    } catch (Throwable $e) {
      foreach ($created as $n) @unlink(report_image_dir() . '/' . $n);
      throw $e;
    }
    foreach (array_diff($oldImages, $images) as $n) @unlink(report_image_dir() . '/' . $n);
    unset(Tbl::$cache['TeachDocs']);
    log_action($me, $old ? 'แก้ไขเอกสารรายงานการสอน' : 'บันทึกเอกสารรายงานการสอน', $title);
    return ['id' => $id, 'updated_at' => $now];
  });
}

/** คืนค่ารายงานการสอนเป็นฉบับที่ระบบสร้างจากข้อมูล (ลบฉบับที่บันทึกไว้และไฟล์ฉบับจริง) */
function api_teachDocReset($token = '', $reportIds = []) {
  $me = auth_user($token);
  $sc = teach_doc_scope($me, $reportIds);
  return with_lock(function () use ($me, $sc) {
    $old = db_row('TeachDocs', '`report_ids` = ?', [implode(',', $sc['ids'])]);
    if (!$old) return true;
    db()->prepare('DELETE FROM `TeachDocs` WHERE `id` = ?')->execute([$old['id']]);
    db()->prepare('DELETE FROM `DocFiles` WHERE `ref` = ?')->execute(['teach:' . $old['id']]);
    foreach (report_image_names($old) as $n) @unlink(report_image_dir() . '/' . $n);
    unset(Tbl::$cache['TeachDocs'], Tbl::$cache['DocFiles']);
    log_action($me, 'คืนค่ารายงานการสอนเป็นฉบับของระบบ', $old['title']);
    return true;
  });
}

// ======================= ไฟล์ฉบับจริง =======================
/** ตรวจว่าผู้ใช้เปิดเอกสารที่ไฟล์นี้ผูกอยู่ได้ */
function doc_ref_assert(array $me, $ref): string {
  $ref = str($ref);
  if (!preg_match('/^(sub|visit|teach):([\w-]{1,64})$/', $ref, $m)) throw new Exception('ไม่พบเอกสาร');
  if ($m[1] === 'sub') {
    $doc = sub_doc_find($m[2]);
    if (!$doc) throw new Exception('กรุณาบันทึกเอกสารก่อน');
    sub_doc_scope($me, $doc['sub_ids']);
  } elseif ($m[1] === 'visit') {
    $doc = visit_doc_find($m[2]);
    if (!$doc) throw new Exception('กรุณาบันทึกเอกสารก่อน');
    visit_doc_assert($me, $doc);
  } else {
    $doc = db_row('TeachDocs', '`id` = ?', [$m[2]]);
    if (!$doc) throw new Exception('กรุณาบันทึกเอกสารก่อน');
    teach_doc_scope($me, $doc['report_ids']);
  }
  return $ref;
}

function doc_file_meta(?array $r): ?array {
  return $r ? ['ref' => $r['ref'], 'name' => $r['name'], 'ext' => $r['ext'], 'size' => (int)$r['size'], 'uploaded_by' => $r['uploaded_by'], 'uploaded_at' => $r['uploaded_at']] : null;
}

/** ข้อมูลไฟล์ฉบับจริงของเอกสาร (ไม่รวมเนื้อไฟล์) ไม่มี = null */
function api_docFileGet($token = '', $ref = '') {
  $me = auth_user($token);
  $ref = doc_ref_assert($me, $ref);
  return doc_file_meta(db_row('DocFiles', '`ref` = ?', [$ref]));
}

/** เนื้อไฟล์ฉบับจริงเป็น base64 สำหรับดาวน์โหลดหรือดูตัวอย่าง */
function api_docFileDownload($token = '', $ref = '') {
  $me = auth_user($token);
  $ref = doc_ref_assert($me, $ref);
  $r = db_row('DocFiles', '`ref` = ?', [$ref]);
  if (!$r) throw new Exception('ยังไม่มีไฟล์ฉบับจริง');
  return doc_file_meta($r) + ['data' => $r['file_data']];
}

/** อัปไฟล์ .docx/.xlsx เป็นฉบับจริง แทนไฟล์เดิมของเอกสารนี้ */
function api_docFileSave($token = '', $ref = '', $name = '', $base64 = '') {
  $me = auth_user($token);
  $ref = doc_ref_assert($me, $ref);
  $bytes = base64_decode(str($base64), true);
  if ($bytes === false || $bytes === '') throw new Exception('อ่านไฟล์ไม่สำเร็จ');
  if (strlen($bytes) > DOC_FILE_MAX_BYTES) throw new Exception('ไฟล์ใหญ่เกิน 5 MB กรุณาย่อรูปในไฟล์ก่อน');
  $ext = strtolower(pathinfo(str($name), PATHINFO_EXTENSION));
  if (!isset(DOC_FILE_EXTS[$ext])) throw new Exception('รองรับเฉพาะไฟล์ .docx และ .xlsx');
  // ไฟล์ Office ต้องเป็น zip ที่มีส่วนหลักของชนิดนั้น ไม่เชื่อนามสกุลอย่างเดียว
  if (substr($bytes, 0, 4) !== "PK\x03\x04" || strpos($bytes, DOC_FILE_EXTS[$ext]) === false) throw new Exception('ไฟล์ไม่ใช่ .' . $ext . ' ที่ถูกต้อง');
  $clean = mb_substr(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/u', '', str($name)), 0, 150) ?: 'เอกสาร.' . $ext;
  return with_lock(function () use ($me, $ref, $clean, $ext, $bytes) {
    db()->prepare('DELETE FROM `DocFiles` WHERE `ref` = ?')->execute([$ref]);
    db()->prepare('INSERT INTO `DocFiles` (`id`, `ref`, `name`, `ext`, `size`, `file_data`, `uploaded_by`, `uploaded_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([new_id(), $ref, $clean, $ext, (string)strlen($bytes), base64_encode($bytes), $me['name'], cell(now_dt())]);
    unset(Tbl::$cache['DocFiles']);
    log_action($me, 'อัปไฟล์ฉบับจริงของเอกสาร', $clean);
    return doc_file_meta(db_row('DocFiles', '`ref` = ?', [$ref]));
  });
}

/** เลิกใช้ไฟล์ฉบับจริง กลับไปใช้เอกสารในระบบ */
function api_docFileDelete($token = '', $ref = '') {
  $me = auth_user($token);
  $ref = doc_ref_assert($me, $ref);
  return with_lock(function () use ($me, $ref) {
    db()->prepare('DELETE FROM `DocFiles` WHERE `ref` = ?')->execute([$ref]);
    unset(Tbl::$cache['DocFiles']);
    log_action($me, 'เลิกใช้ไฟล์ฉบับจริงของเอกสาร', $ref);
    return true;
  });
}
