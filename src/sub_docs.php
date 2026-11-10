<?php
/**
 * เอกสารสอนแทน/สอนชดเชยที่บันทึกเก็บไว้ (ใบขออนุญาต และรายงานการสอน) เพื่อกลับมาแก้ไขได้
 * เก็บ HTML ของเอกสารหลังแก้ไข รูปแยกเป็นไฟล์ในโฟลเดอร์เดียวกับรูปรายงานการสอน เพื่อให้รวมในไฟล์สำรองข้อมูล
 * อ่าน/เขียนตารางนี้ด้วย SQL ตรง เพราะเนื้อเอกสารใหญ่ ไม่ควรโหลดทั้งตารางทุกครั้ง
 */
const SUB_DOC_KINDS = ['makeup_request', 'sub_report'];
const SUB_DOC_MAX_HTML = 2 * 1024 * 1024;
const SUB_DOC_MAX_IMAGES = 80;
const SUB_DOC_UNSAFE = '/<\s*\/?\s*(script|iframe|object|embed|link|meta|style|base|form|input|textarea|button|select|svg|math|template|frame|frameset|noscript|audio|video|source)\b|\son[a-z]+\s*=|javascript:|vbscript:|style="[^"]*(url|expression)\s*\(/i';

/** รายการชุดเดียวกันของเอกสาร: ต้องมีอยู่จริง เป็นครูเดิมและผู้สอนคนเดียวกัน และผู้ใช้มีสิทธิ์ */
function sub_doc_scope(array $me, $subIds): array {
  $ids = array_values(array_unique(array_filter(array_map('str', is_array($subIds) ? $subIds : explode(',', str($subIds))), fn($x) => $x !== '')));
  if (!$ids || count($ids) > 100) throw new Exception('ไม่พบรายการสอนแทน/สอนชดเชยของเอกสารนี้');
  sort($ids);
  $all = idx_table('OtSubs');
  $rows = [];
  foreach ($ids as $id) { if (!isset($all[$id])) throw new Exception('ไม่พบรายการสอนแทน/สอนชดเชยของเอกสารนี้'); $rows[] = $all[$id]; }
  $orig = (string)$rows[0]['original_teacher_id']; $tid = (string)$rows[0]['teacher_id'];
  foreach ($rows as $r) if ((string)$r['original_teacher_id'] !== $orig || (string)$r['teacher_id'] !== $tid) throw new Exception('เอกสารหนึ่งฉบับต้องเป็นรายการของครูชุดเดียวกัน');
  // ครูผู้สอนเดิมหรือผู้สอนแทนเปิดเอกสารของรายการนี้ได้ ผู้อื่นใช้สิทธิ์ตามแผนก
  $mine = $me['role'] === 'teacher' && js_true($me['teacher_id'] ?? '') && in_array((string)$me['teacher_id'], [$orig, $tid], true);
  if (!$mine) assert_ot_sub_owner($me, $orig);
  return ['ids' => $ids, 'orig' => $orig, 'teacher' => $tid, 'round' => (string)$rows[0]['round_id']];
}

/**
 * รายการสอนชดเชยที่มีเอกสารรายงานการสอนบันทึกในระบบแล้ว: [รหัสรายการ => รหัสเอกสาร]
 * ใช้ร่วมกับการแนบลิงก์ เพื่อให้แถบเตือน รายการ และการตรวจใบเบิกเห็นสถานะเดียวกัน
 */
function sub_reported_map(): array {
  assert_table('SubDocs');
  $map = [];
  foreach (db()->query("SELECT `id`, `sub_ids` FROM `SubDocs` WHERE `kind` = 'sub_report' ORDER BY `_seq`")->fetchAll() as $d) {
    foreach (explode(',', (string)$d['sub_ids']) as $s) if ($s !== '' && !isset($map[$s])) $map[$s] = (string)$d['id'];
  }
  return $map;
}

/** ติดสถานะ "รายงานแล้ว" ให้สอนชดเชยที่มีเอกสารรายงานในระบบ ไม่เปลี่ยนข้อมูลที่บันทึกไว้ */
function ot_subs_with_reports(array $rows): array {
  $map = sub_reported_map();
  foreach ($rows as &$r) {
    if (($r['type'] ?? '') !== 'makeup' || !isset($map[$r['id'] ?? ''])) continue;
    $r['report_doc'] = $map[$r['id']];
    $r['report_done'] = 'true';
  }
  unset($r);
  return $rows;
}

function sub_doc_find(string $id): ?array {
  assert_table('SubDocs');
  $st = db()->prepare('SELECT * FROM `SubDocs` WHERE `id` = ?');
  $st->execute([$id]);
  $r = $st->fetch();
  if (!$r) return null;
  unset($r['_seq']);
  return array_map(fn($v) => $v ?? '', $r);
}

/** แยกรูปจากเอกสาร: รูปเดิมคงชื่อไฟล์ รูปใหม่ (data URL) เก็บเป็นไฟล์ รูปซ้ำกันเก็บไฟล์เดียว */
function sub_doc_store_images(string $html, string $docId, array $old, array &$created): array {
  $keep = []; $byHash = []; $count = 0;
  $html = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use (&$keep, &$byHash, &$count, &$created, $old, $docId) {
    $tag = $m[0];
    $alt = preg_match('/\salt="([^"<>]*)"/', $tag, $a) ? ' alt="' . $a[1] . '"' : ' alt=""';
    $cls = preg_match('/\sclass="([\w\s-]*)"/', $tag, $c) ? ' class="' . $c[1] . '"' : '';
    if (preg_match('/\sdata-doc-img="([^"]+)"/', $tag, $n) && in_array($n[1], $old, true)) { $keep[$n[1]] = 1; return '<img data-doc-img="' . $n[1] . '"' . $cls . $alt . '>'; }
    if (preg_match('/\ssrc="data:image\/(?:jpeg|png);base64,([A-Za-z0-9+\/=]+)"/', $tag, $d)) {
      $hash = sha1($d[1]);
      if (!isset($byHash[$hash])) {
        if (++$count > SUB_DOC_MAX_IMAGES) throw new Exception('เอกสารมีรูปมากเกินไป');
        $bytes = base64_decode($d[1], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > REPORT_MAX_IMAGE_BYTES) throw new Exception('รูปในเอกสารไม่ถูกต้องหรือใหญ่เกินไป');
        $p = report_prepare_image($bytes);
        $name = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $docId)) . '-' . bin2hex(random_bytes(6)) . '.' . $p['ext'];
        if (file_put_contents(report_image_dir() . '/' . $name, $p['bytes']) !== strlen($p['bytes'])) throw new Exception('บันทึกรูปไม่สำเร็จ');
        $created[] = $name;
        $byHash[$hash] = $name;
      }
      $keep[$byHash[$hash]] = 1;
      return '<img data-doc-img="' . $byHash[$hash] . '"' . $cls . $alt . '>';
    }
    if (preg_match('/\ssrc="(assets\/[\w.-]+)"/', $tag, $s)) return '<img src="' . $s[1] . '"' . $cls . $alt . '>';
    return '';
  }, $html);
  return [$html, array_keys($keep)];
}

function sub_doc_inline_images(string $html): string {
  return preg_replace_callback('/<img data-doc-img="([a-z0-9]+-[a-f0-9]{12}\.(jpg|png))"/', function ($m) {
    $p = report_image_dir() . '/' . $m[1];
    if (!is_file($p)) return '<img data-doc-img="' . $m[1] . '"';
    return '<img src="data:image/' . ($m[2] === 'png' ? 'png' : 'jpeg') . ';base64,' . base64_encode((string)file_get_contents($p)) . '" data-doc-img="' . $m[1] . '"';
  }, $html);
}

function api_subDocList($token = '', $subIds = [], $kind = '') {
  $me = auth_user($token);
  $sc = sub_doc_scope($me, $subIds);
  if (!in_array($kind, SUB_DOC_KINDS, true)) throw new Exception('ชนิดเอกสารไม่ถูกต้อง');
  assert_table('SubDocs');
  $st = db()->prepare('SELECT `id`, `kind`, `sub_ids`, `title`, `created_by`, `created_at`, `updated_by`, `updated_at` FROM `SubDocs` WHERE `original_teacher_id` = ? AND `kind` = ? ORDER BY `_seq` DESC');
  $st->execute([$sc['orig'], $kind]);
  return array_values(array_filter(array_map(fn($r) => array_map(fn($v) => $v ?? '', $r), $st->fetchAll()), fn($r) => (bool)array_intersect(explode(',', $r['sub_ids']), $sc['ids'])));
}

function api_subDocGet($token = '', $id = '') {
  $me = auth_user($token);
  $doc = sub_doc_find(str($id));
  if (!$doc) throw new Exception('ไม่พบเอกสาร');
  sub_doc_scope($me, $doc['sub_ids']);
  unset($doc['images']);
  $doc['html'] = sub_doc_inline_images($doc['html']);
  return $doc;
}

/** rec: {id?, kind, sub_ids[], title, html} — แก้ฉบับเดิมเมื่อส่ง id มา ชุดรายการของเอกสารเดิมเปลี่ยนไม่ได้ */
function api_subDocSave($token = '', $rec = []) {
  $me = auth_user($token);
  $rec = (array)$rec;
  $html = is_string($rec['html'] ?? null) ? $rec['html'] : '';
  if (trim($html) === '') throw new Exception('ไม่มีเนื้อหาเอกสาร');
  if (strlen($html) > SUB_DOC_MAX_HTML + SUB_DOC_MAX_IMAGES * 400 * 1024) throw new Exception('เอกสารมีขนาดใหญ่เกินไป');
  // ตรวจเนื้อหาก่อนแยกรูป ข้อมูลรูปเป็น base64 จึงไม่ชนรูปแบบเหล่านี้
  if (preg_match(SUB_DOC_UNSAFE, preg_replace('/data:image\/(?:jpeg|png);base64,[A-Za-z0-9+\/=]+/', '', $html))) throw new Exception('เอกสารมีเนื้อหาที่ไม่อนุญาต');
  return with_lock(function () use ($me, $rec, $html) {
    $id = str($rec['id'] ?? '');
    $old = $id !== '' ? sub_doc_find($id) : null;
    if ($id !== '' && !$old) throw new Exception('ไม่พบเอกสาร');
    if ($old) { $sc = sub_doc_scope($me, $old['sub_ids']); $kind = $old['kind']; }
    else {
      $kind = str($rec['kind'] ?? '');
      if (!in_array($kind, SUB_DOC_KINDS, true)) throw new Exception('ชนิดเอกสารไม่ถูกต้อง');
      $sc = sub_doc_scope($me, $rec['sub_ids'] ?? []);
      $id = new_id();
    }
    $oldImages = $old ? report_image_names($old) : [];
    $created = [];
    try {
      [$clean, $images] = sub_doc_store_images($html, $id, $oldImages, $created);
      if (strlen($clean) > SUB_DOC_MAX_HTML) throw new Exception('เอกสารมีขนาดใหญ่เกินไป');
      $title = clean_text($rec['title'] ?? '', 200) ?: ($old['title'] ?? 'เอกสารสอนแทน/สอนชดเชย');
      $now = cell(now_dt());
      if ($old) {
        db()->prepare('UPDATE `SubDocs` SET `title` = ?, `html` = ?, `images` = ?, `updated_by` = ?, `updated_at` = ? WHERE `id` = ?')
          ->execute([$title, $clean, json_encode($images), $me['name'], $now, $id]);
      } else {
        db()->prepare('INSERT INTO `SubDocs` (`id`, `kind`, `sub_ids`, `round_id`, `original_teacher_id`, `teacher_id`, `title`, `html`, `images`, `created_by`, `created_at`, `updated_by`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([$id, $kind, implode(',', $sc['ids']), $sc['round'], $sc['orig'], $sc['teacher'], $title, $clean, json_encode($images), $me['name'], $now, $me['name'], $now]);
      }
    } catch (Throwable $e) {
      foreach ($created as $n) @unlink(report_image_dir() . '/' . $n);
      throw $e;
    }
    foreach (array_diff($oldImages, $images) as $n) @unlink(report_image_dir() . '/' . $n);
    unset(Tbl::$cache['SubDocs']);
    log_action($me, $old ? 'แก้ไขเอกสารสอนแทน/สอนชดเชย' : 'บันทึกเอกสารสอนแทน/สอนชดเชย', $title);
    return ['id' => $id, 'updated_at' => $now];
  });
}

function api_subDocDelete($token = '', $id = '') {
  $me = auth_user($token);
  return with_lock(function () use ($me, $id) {
    $doc = sub_doc_find(str($id));
    if (!$doc) throw new Exception('ไม่พบเอกสาร');
    sub_doc_scope($me, $doc['sub_ids']);
    db()->prepare('DELETE FROM `SubDocs` WHERE `id` = ?')->execute([$doc['id']]);
    foreach (report_image_names($doc) as $n) @unlink(report_image_dir() . '/' . $n);
    unset(Tbl::$cache['SubDocs']);
    log_action($me, 'ลบเอกสารสอนแทน/สอนชดเชย', $doc['title']);
    return true;
  });
}
