<?php
/** ลายเซ็นสำหรับพิมพ์ในเอกสาร แต่ละคนมีได้หลายลายเซ็น และเลือกหนึ่งอันเป็น "ลายเซ็นหลัก" ที่ใช้ในเอกสาร
 *  owner = u:<รหัสผู้ใช้> ลายเซ็นที่ผู้ใช้อัปเอง ใช้กับชื่อในบัญชีและชื่อครูที่ผูกไว้
 *  owner = s:<คีย์ผู้ลงนาม> ลายเซ็นที่ผู้ดูแลระบบอัปให้ผู้ลงนามที่ไม่มีบัญชี (เช่น รองผู้อำนวยการ ผู้อำนวยการ)
 *  เอกสารจับคู่ลายเซ็นกับชื่อผู้ลงนาม โดยไม่สนช่องว่าง */

/** แถวละรูปเดียว ต้องพอดีคอลัมน์ TEXT (64 KB หลังแปลงเป็น base64) */
const SIGNATURE_MAX_BYTES = 40000;
const SIGNATURE_MAX_PER_OWNER = 5;
const SIGNATURE_SETTING_KEYS = ['signer_curriculum', 'signer_deputy', 'signer_finance', 'signer_admin_deputy', 'signer_dvt', 'signer_director'];

/** ไม่สนช่องว่าง และถือว่าสระอำแบบพิมพ์แยก (นิคหิต + สระอา) เป็นตัวเดียวกัน ต้องตรงกับ Sig.key ในหน้าเว็บ */
function sig_key($name): string { return str_replace("\u{0E4D}\u{0E32}", "\u{0E33}", preg_replace('/\s+/u', '', (string)$name)); }

function sig_valid_uri($v): string {
  $v = (string)$v;
  return preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $v) ? $v : '';
}

/** ชื่อทั้งหมดของผู้ใช้ที่ลายเซ็นใช้ได้: ชื่อบัญชี และชื่อครูที่ผูกไว้ */
function sig_user_names(array $u, array $teachers): array {
  $names = [trim((string)$u['name'])];
  $t = $teachers[(string)($u['teacher_id'] ?? '')] ?? null;
  if ($t) $names[] = trim((string)$t['name']);
  return array_values(array_unique(array_filter($names)));
}

/** owner ที่ผู้ใช้แก้ได้: ของตัวเอง ส่วนผู้ดูแลระบบแก้ของผู้ใช้ทุกคน (u:<รหัส>) และผู้ลงนามในหน้าตั้งค่า (s:<คีย์>) */
function sig_owner(array $me, $target): string {
  $target = str($target);
  if ($target === '' || $target === 'u:' . $me['id']) return 'u:' . $me['id'];
  if ($me['role'] !== 'admin') throw new Exception('เฉพาะผู้ดูแลระบบจัดการลายเซ็นของผู้อื่นได้');
  if (strpos($target, 's:') === 0 && in_array(substr($target, 2), SIGNATURE_SETTING_KEYS, true)) return $target;
  if (strpos($target, 'u:') === 0 && isset(idx_table('users')[substr($target, 2)])) return $target;
  throw new Exception('ไม่พบผู้ลงนาม');
}

/** ชื่อเจ้าของลายเซ็นตอนนี้ เก็บไว้กับลายเซ็นของผู้ลงนาม เพื่อตรวจว่ายังเป็นคนเดิม */
function sig_owner_name(string $owner, array $me): string {
  if (strpos($owner, 's:') === 0) return trim((string)(readSettings()[substr($owner, 2)] ?? ''));
  return trim((string)(idx_table('users')[substr($owner, 2)]['name'] ?? $me['name']));
}

/** จำนวนลายเซ็นของผู้ใช้แต่ละคน สำหรับหน้าจัดการผู้ใช้ */
function sig_count_by_user(): array {
  $n = [];
  foreach (readTable('Signatures') as $r) if (strpos((string)$r['owner'], 'u:') === 0 && sig_valid_uri($r['image']) !== '') $n[substr($r['owner'], 2)] = ($n[substr($r['owner'], 2)] ?? 0) + 1;
  return $n;
}

/** ลายเซ็นของ owner เรียงตามลำดับที่เพิ่ม ลายเซ็นของผู้ลงนามคนก่อนไม่นับ */
function sig_rows(string $owner, string $name): array {
  $rows = array_values(array_filter(readTable('Signatures'), fn($r) => (string)$r['owner'] === $owner && sig_valid_uri($r['image']) !== ''));
  if (strpos($owner, 's:') === 0) $rows = array_values(array_filter($rows, fn($r) => sig_key($r['name']) === sig_key($name)));
  return $rows;
}

/** ลายเซ็นหลัก: อันที่ติ๊กไว้ ถ้าไม่มีใช้อันแรก */
function sig_primary(array $rows): ?array {
  foreach ($rows as $r) if ((string)($r['is_primary'] ?? '') === 'true') return $r;
  return $rows[0] ?? null;
}

/** ตำแหน่งและขนาดลายเซ็นเทียบกับจุดเริ่มเส้นลงชื่อ: x, y เป็นหน่วย em (ขวา/ลงเป็นบวก) s = เท่าของขนาดปกติ
 *  จำกัดช่วงไว้ไม่ให้ลายเซ็นหลุดออกไปทับส่วนอื่นของเอกสารไกลเกินไป */
function sig_place($raw): array {
  $p = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : []);
  $num = fn($k, $min, $max, $def) => is_numeric($p[$k] ?? null) ? round(max($min, min($max, (float)$p[$k])), 2) : $def;
  return ['x' => $num('x', -6, 12, 0), 'y' => $num('y', -3, 3, 0), 's' => $num('s', 0.5, 2, 1)];
}

/** สิ่งที่หน้าเว็บใช้วางลายเซ็น */
function sig_out(array $r): array { return ['src' => sig_valid_uri($r['image'])] + sig_place($r['place'] ?? ''); }

/** ลายเซ็นหลักทุกคน เป็น {ชื่อไม่มีช่องว่าง: {src, x, y, s}} ให้หน้าเว็บเติมในเอกสาร */
function api_signatureMap($token = '') {
  auth_user($token);
  $settings = readSettings();
  $users = idx_table('users');
  $teachers = idx_table('Teachers');
  $byOwner = [];
  foreach (readTable('Signatures') as $r) if (sig_valid_uri($r['image']) !== '') $byOwner[(string)$r['owner']][] = $r;
  $fromSettings = []; $fromUsers = [];
  foreach ($byOwner as $owner => $rows) {
    if (strpos($owner, 's:') === 0) {
      // ผู้ลงนามเปลี่ยนคนแล้ว ลายเซ็นเดิมต้องไม่ไปขึ้นใต้ชื่อคนใหม่
      $now = sig_key($settings[substr($owner, 2)] ?? '');
      $rows = array_values(array_filter($rows, fn($r) => $now !== '' && sig_key($r['name']) === $now));
      if ($p = sig_primary($rows)) $fromSettings[$now] = sig_out($p);
    } elseif (strpos($owner, 'u:') === 0) {
      $u = $users[substr($owner, 2)] ?? null;
      if (!$u || (string)($u['active'] ?? '') === 'false' || !($p = sig_primary($rows))) continue;
      foreach (sig_user_names($u, $teachers) as $n) $fromUsers[sig_key($n)] = sig_out($p);
    }
  }
  // ลายเซ็นที่เจ้าตัวอัปเองมาก่อนลายเซ็นที่ผู้ดูแลอัปให้
  return (object)($fromUsers + $fromSettings);
}

function api_signatureList($token = '', $target = '') {
  $me = auth_user($token);
  $owner = sig_owner($me, $target);
  $name = sig_owner_name($owner, $me);
  $rows = sig_rows($owner, $name);
  $primary = sig_primary($rows);
  $names = [$name];
  if (strpos($owner, 'u:') === 0) {
    $u = idx_table('users')[substr($owner, 2)] ?? null;
    if ($u) $names = sig_user_names($u, idx_table('Teachers'));
  }
  return [
    'owner' => $owner, 'name' => $name, 'names' => array_values(array_filter($names)), 'max' => SIGNATURE_MAX_PER_OWNER,
    'items' => array_map(fn($r) => ['id' => $r['id'], 'image' => sig_valid_uri($r['image']), 'place' => sig_place($r['place'] ?? ''), 'is_primary' => $primary && $r['id'] === $primary['id'], 'updated_at' => (string)$r['updated_at']], $rows),
  ];
}

/** แก้ลายเซ็นทั้งชุดของ owner ใน lock เดียว แล้วคืนรายการล่าสุด */
function sig_update($token, $target, callable $fn, string $logAction) {
  $me = auth_user($token);
  $owner = sig_owner($me, $target);
  $name = sig_owner_name($owner, $me);
  if ($name === '') throw new Exception('กรุณากรอกชื่อผู้ลงนามในหน้าตั้งค่าและบันทึกก่อน');
  return with_lock(function () use ($me, $token, $target, $owner, $name, $fn, $logAction) {
    $mine = sig_rows($owner, $name);
    // แถวอื่นของ owner นี้ที่ใช้ไม่ได้แล้ว (เช่น ของผู้ลงนามคนก่อน) ล้างทิ้งไปพร้อมกัน
    $others = array_values(array_filter(readTable('Signatures'), fn($r) => (string)$r['owner'] !== $owner));
    $mine = $fn($mine, $me, $owner, $name);
    $p = sig_primary($mine);
    foreach ($mine as &$r) $r['is_primary'] = $p && $r['id'] === $p['id'] ? 'true' : '';
    unset($r);
    writeTable('Signatures', array_merge($others, $mine));
    log_action($me, $logAction, $name);
    return api_signatureList($token, $target);
  });
}

function sig_find(array $rows, $id): int {
  foreach ($rows as $i => $r) if ((string)$r['id'] === str($id)) return $i;
  throw new Exception('ไม่พบลายเซ็นนี้');
}

/** $id ว่าง = เพิ่มลายเซ็นใหม่, $image = base64 ของไฟล์ PNG/JPG ที่หน้าเว็บย่อแล้ว, $place = {x, y, s} ตำแหน่งและขนาดในเอกสาร */
function api_signatureSave($token = '', $target = '', $id = '', $image = '', $place = null) {
  $placeJson = json_encode(sig_place($place));
  $bytes = base64_decode(str($image), true);
  if ($bytes === false || $bytes === '') throw new Exception('อ่านไฟล์ลายเซ็นไม่สำเร็จ');
  if (strlen($bytes) > SIGNATURE_MAX_BYTES) throw new Exception('ไฟล์ลายเซ็นใหญ่เกินไป');
  $ext = report_image_ext($bytes);
  if ($ext === '') throw new Exception('รองรับเฉพาะรูป PNG และ JPG');
  $uri = 'data:image/' . ($ext === 'jpg' ? 'jpeg' : 'png') . ';base64,' . base64_encode($bytes);
  return sig_update($token, $target, function ($rows, $me, $owner, $name) use ($id, $uri, $placeJson) {
    if (str($id) === '') {
      if (count($rows) >= SIGNATURE_MAX_PER_OWNER) throw new Exception('เก็บลายเซ็นได้สูงสุด ' . SIGNATURE_MAX_PER_OWNER . ' ลายเซ็น กรุณาลบอันที่ไม่ใช้ก่อน');
      $rows[] = ['id' => new_id(), 'owner' => $owner, 'name' => $name, 'image' => $uri, 'place' => $placeJson, 'is_primary' => '', 'updated_by' => $me['name'], 'updated_at' => now_dt()];
    } else {
      $i = sig_find($rows, $id);
      $rows[$i] = array_merge($rows[$i], ['image' => $uri, 'place' => $placeJson, 'name' => $name, 'updated_by' => $me['name'], 'updated_at' => now_dt()]);
    }
    return $rows;
  }, str($id) === '' ? 'เพิ่มลายเซ็น' : 'แก้ไขลายเซ็น');
}

function api_signatureDelete($token = '', $target = '', $id = '') {
  return sig_update($token, $target, function ($rows) use ($id) {
    array_splice($rows, sig_find($rows, $id), 1);
    return $rows;
  }, 'ลบลายเซ็น');
}

function api_signaturePrimary($token = '', $target = '', $id = '') {
  return sig_update($token, $target, function ($rows) use ($id) {
    $i = sig_find($rows, $id);
    foreach ($rows as $k => &$r) $r['is_primary'] = $k === $i ? 'true' : '';
    unset($r);
    return $rows;
  }, 'ตั้งลายเซ็นหลัก');
}
