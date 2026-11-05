<?php
/**
 * ตรวจการชน + จัดตารางอัตโนมัติ (ฝั่งเซิร์ฟเวอร์)
 * กติกาตรงกับ SchedCore.js ที่หน้าเว็บใช้ตรวจตอนลากวาง
 */

function sched_ids($s): array {
  $out = [];
  foreach (explode(',', (string)($s ?? '')) as $x) { $x = trim($x); if ($x !== '') $out[] = $x; }
  return $out;
}

function sched_ctx(array $o): array {
  $s = $o['settings'];
  $days = $s['days'] ?? '';
  $ctx = [
    'periods' => num($s['periods_per_day'] ?? 12) ?: 12,
    'lunch' => array_map('num', sched_ids($s['lunch_period'] ?? '')),
    'days' => array_map('num', sched_ids($days !== '' ? $days : '0,1,2,3,4')),
    'locked' => [], 'teachers' => [], 'rooms' => [], 'groups' => [], 'courses' => [], 'plans' => [],
  ];
  foreach ($o['locked'] ?? [] as $l) {
    $label = js_true($l['label'] ?? '') ? $l['label'] : 'ล็อก';
    if ((string)$l['day'] === '' || (string)$l['day'] === '*') { foreach ($ctx['days'] as $d) $ctx['locked'][$d . '-' . $l['period']] = $label; }
    else $ctx['locked'][$l['day'] . '-' . $l['period']] = $label;
  }
  foreach ($o['teachers'] ?? [] as $t) {
    $un = [];
    foreach (sched_ids($t['unavailable'] ?? '') as $x) $un[str_replace(':', '-', $x)] = 1; // รูปแบบ "วัน-คาบ"
    // คาบ PLC ถือเป็นเวลาที่ครูไม่ว่าง: คาบรายบุคคลใช้แทนคาบของแผนก
    $own = array_filter($o['plc'] ?? [], fn($x) => (string)($x['teacher_id'] ?? '') === (string)$t['id']);
    $plc = $own ?: array_filter($o['plc'] ?? [], fn($x) => !js_true($x['teacher_id'] ?? '') && (string)($x['department_id'] ?? '') === (string)($t['department_id'] ?? ''));
    foreach ($plc as $x) for ($k = 0, $n = num($x['length'] ?? 1) ?: 1; $k < $n; $k++) $un[$x['day'] . '-' . (num($x['start']) + $k)] = 1;
    $ctx['teachers'][$t['id']] = ['name' => $t['name'], 'max' => num($t['max_per_day'] ?? 0), 'un' => $un];
  }
  foreach ($o['rooms'] ?? [] as $r) $ctx['rooms'][$r['id']] = ['name' => $r['name'], 'shareable' => (string)$r['shareable'] === 'true'];
  foreach ($o['groups'] ?? [] as $g) $ctx['groups'][$g['id']] = $g;
  foreach ($o['courses'] ?? [] as $c) $ctx['courses'][$c['id']] = $c;
  foreach ($o['plans'] ?? [] as $p) $ctx['plans'][$p['id']] = $p;
  return $ctx;
}

/**
 * กันลงคาบเกินแผน ตรวจทุกแผนที่เรียนร่วมกัน เมื่อสร้างคาบใหม่หรือเพิ่มจำนวนคาบ
 * การย้ายหรือลดคาบไม่ถูกบล็อก เพื่อให้แก้ข้อมูลเดิมที่เกินอยู่แล้วได้
 * $sessions และ $moving ต้องใช้คีย์ชุดเดียวกัน คืนรายการปัญหา หรือ null ถ้าไม่เกิน
 */
function sched_over_problem(array $plans, array $sessions, array $moving, int $len, ?array $existing, array $ctx): ?array {
  if ($existing && $len <= num($existing['length'])) return null;
  foreach ($plans as $p) {
    $used = 0;
    foreach ($sessions as $k => $s) if (!isset($moving[$k]) && (string)$s['plan_id'] === (string)$p['id']) $used += num($s['length']);
    $max = sched_plan_periods($p, $ctx);
    if ($used + $len > $max) {
      $group = $ctx['groups'][$p['group_id']]['code'] ?? '';
      return ['type' => 'over', 'msg' => 'ลงคาบเกินจำนวนชั่วโมงในแผน' . ($group !== '' ? ' กลุ่ม ' . $group : '') . ' (ลงแล้ว ' . $used . ' จาก ' . $max . ' คาบ)', 'hard' => true];
    }
  }
  return null;
}

function sched_overlap(array $a, array $b): bool {
  $a1 = num($a['start']); $a2 = $a1 + num($a['length']) - 1;
  $b1 = num($b['start']); $b2 = $b1 + num($b['length']) - 1;
  return (string)$a['day'] === (string)$b['day'] && $a1 <= $b2 && $b1 <= $a2;
}

function sched_joint(array $s, array $ctx): string {
  $p = $ctx['plans'][$s['plan_id'] ?? ''] ?? null;
  return ($p && js_true($p['joint_key'] ?? '')) ? (string)$p['joint_key'] : '';
}

function sched_tname($id, $ctx) { return $ctx['teachers'][$id]['name'] ?? $id; }
function sched_rname($id, $ctx) { return $ctx['rooms'][$id]['name'] ?? $id; }
function sched_glabel($s, $ctx) {
  $g = $ctx['groups'][$s['group_id']] ?? null;
  return $g ? (js_true($g['code'] ?? '') ? $g['code'] : $g['name']) : $s['group_id'];
}
function sched_clabel($s, $ctx) {
  $c = $ctx['courses'][$s['course_id']] ?? null;
  return $c ? $c['code'] . ' ' . $c['name'] : $s['course_id'];
}

/** ตรวจว่าวางคาบได้ไหม: คืน [{type, msg, hard}] โดย hard=true คือห้ามวาง */
function sched_check(array $cand, array $sessions, array $ctx): array {
  $out = [];
  $start = num($cand['start']); $len = num($cand['length']); $day = num($cand['day']);
  if (!in_array($day, $ctx['days'], true)) $out[] = ['type' => 'day', 'msg' => 'วันนี้ไม่ได้เปิดให้จัดตาราง', 'hard' => true];
  if ($start < 1 || $start + $len - 1 > $ctx['periods']) $out[] = ['type' => 'range', 'msg' => 'เกินจำนวนคาบต่อวัน', 'hard' => true];
  for ($p = $start; $p < $start + $len; $p++) {
    if (in_array($p, $ctx['lunch'], true)) { $out[] = ['type' => 'lunch', 'msg' => 'ทับเวลาพักเที่ยง', 'hard' => true]; break; }
    if (isset($ctx['locked'][$day . '-' . $p])) { $out[] = ['type' => 'locked', 'msg' => 'ทับชั่วโมงที่ล็อก: ' . $ctx['locked'][$day . '-' . $p] . ' (คาบ ' . period_no($p) . ')', 'hard' => true]; break; }
  }
  $cj = sched_joint($cand, $ctx);
  $cTeachers = sched_ids($cand['teacher_ids'] ?? '');
  $dayLoad = [];
  foreach ($sessions as $s) {
    if (js_true($s['id'] ?? '') && js_true($cand['id'] ?? '') && (string)$s['id'] === (string)$cand['id']) continue;
    if ((string)$s['day'] === (string)$day) {
      foreach (sched_ids($s['teacher_ids']) as $t) $dayLoad[$t] = ($dayLoad[$t] ?? 0) + num($s['length']);
    }
    if (!sched_overlap($cand, $s)) continue;
    $sameJoint = $cj !== '' && $cj === sched_joint($s, $ctx);
    if ((string)$s['group_id'] === (string)($cand['group_id'] ?? '')) {
      $out[] = ['type' => 'group', 'msg' => 'กลุ่มเรียนมีวิชาอื่นในช่วงนี้แล้ว (' . sched_clabel($s, $ctx) . ')', 'hard' => true, 'with' => $s['id']];
    }
    if (!$sameJoint) {
      foreach (sched_ids($s['teacher_ids']) as $t) {
        if (in_array($t, $cTeachers, true)) $out[] = ['type' => 'teacher', 'msg' => 'ครู ' . sched_tname($t, $ctx) . ' สอน ' . sched_glabel($s, $ctx) . ' อยู่แล้ว', 'hard' => true, 'with' => $s['id']];
      }
      $rid = $cand['room_id'] ?? '';
      if (js_true($rid) && (string)$s['room_id'] === (string)$rid && !($ctx['rooms'][$rid]['shareable'] ?? false)) {
        $out[] = ['type' => 'room', 'msg' => 'ห้อง ' . sched_rname($rid, $ctx) . ' ถูกใช้โดย ' . sched_glabel($s, $ctx), 'hard' => true, 'with' => $s['id']];
      }
    }
  }
  foreach ($cTeachers as $t) {
    $T = $ctx['teachers'][$t] ?? null;
    if (!$T) continue;
    for ($p = $start; $p < $start + $len; $p++) {
      if (isset($T['un'][$day . '-' . $p])) { $out[] = ['type' => 'unavail', 'msg' => 'ครู ' . $T['name'] . ' ไม่ว่างวัน/คาบนี้', 'hard' => true]; break; }
    }
    if ($T['max'] && ($dayLoad[$t] ?? 0) + $len > $T['max']) $out[] = ['type' => 'maxday', 'msg' => 'ครู ' . $T['name'] . ' สอนเกิน ' . $T['max'] . ' คาบในวันนี้', 'hard' => false];
  }
  return $out;
}

/** จำนวนคาบต่อสัปดาห์ของแผน */
function sched_plan_periods(array $p, array $ctx) {
  $n = num($p['periods'] ?? '');
  $c = $ctx['courses'][$p['course_id'] ?? ''] ?? null;
  if (!$n && $c && ($c['course_type'] ?? '') === 'project') return 4; // ระเบียบข้อ 7: โครงงาน 4 ชม./สัปดาห์/ห้อง
  if (!$n) $n = $c ? num($c['theory']) + num($c['practice']) : 0;
  return $n;
}

/** แบ่งเป็นก้อน เช่น "2+2" หรืออัตโนมัติ (ก้อนละไม่เกิน 4 คาบ) */
function sched_split(array $p, array $ctx): array {
  $n = sched_plan_periods($p, $ctx);
  if (!$n) return [];
  $parts = array_values(array_filter(array_map('num', explode('+', (string)($p['split'] ?? ''))), fn($x) => $x > 0));
  if ($parts && array_sum($parts) == $n) return $parts;
  if ($n <= 4) return [$n];
  $k = (int)ceil($n / 4); $base = (int)floor($n / $k); $extra = fmod($n, $k);
  $res = [];
  for ($i = 0; $i < $k; $i++) $res[] = $base + ($i < $extra ? 1 : 0);
  return $res;
}

/** ก้อนที่ยังไม่ได้ลงตารางของแผน */
function sched_remaining(array $p, array $sessions, array $ctx): array {
  $blocks = sched_split($p, $ctx);
  stable_usort($blocks, fn($a, $b) => $b <=> $a);
  $placed = [];
  foreach ($sessions as $s) if ((string)$s['plan_id'] === (string)$p['id']) $placed[] = num($s['length']);
  $left = sched_plan_periods($p, $ctx) - array_sum($placed);
  if ($left <= 0) return [];
  foreach ($placed as $len) {
    $i = array_search($len, $blocks);
    if ($i !== false) { array_splice($blocks, $i, 1); }
  }
  if (array_sum($blocks) != $left) $blocks = sched_split(['periods' => $left, 'split' => ''], $ctx);
  return $blocks;
}

/** ก้อนงาน 1 ก้อนของการจัดอัตโนมัติ */
final class SchedBlock {
  public object $unit; public $length; public ?array $info = null; public $diff = 0;
  public ?array $slot = null; public array $sessions = [];
  public function __construct(object $unit, $length) { $this->unit = $unit; $this->length = $length; }
}

function sched_units(array $plans, array $sessions, array $ctx): array {
  $byKey = []; $units = [];
  foreach ($plans as $p) {
    if (!sched_ids($p['teacher_ids'] ?? '') && !js_true($p['group_id'] ?? '')) continue;
    $key = js_true($p['joint_key'] ?? '') ? 'J:' . $p['joint_key'] : 'P:' . $p['id'];
    if (!isset($byKey[$key])) { $byKey[$key] = (object)['key' => $key, 'plans' => []]; $units[] = $byKey[$key]; }
    $byKey[$key]->plans[] = $p;
  }
  $blocks = [];
  foreach ($units as $u) foreach (sched_remaining($u->plans[0], $sessions, $ctx) as $len) $blocks[] = new SchedBlock($u, $len);
  return $blocks;
}

function sched_teacher_load(array $plans, array $ctx): array {
  $load = [];
  foreach ($plans as $p) foreach (sched_ids($p['teacher_ids'] ?? '') as $t) $load[$t] = ($load[$t] ?? 0) + sched_plan_periods($p, $ctx);
  return $load;
}

/** ดัชนีการใช้ช่องเวลาแบบ bitmask */
final class SchedOcc {
  public array $m = [], $cnt = [], $load = [], $gc = [], $blocked = [], $un = [];
  private array $ctx;
  public function __construct(array $ctx) {
    $this->ctx = $ctx;
    foreach ($ctx['days'] as $d) {
      $b = 0;
      foreach ($ctx['lunch'] as $p) $b |= (1 << $p);
      for ($p = 1; $p <= $ctx['periods']; $p++) if (isset($ctx['locked'][$d . '-' . $p])) $b |= (1 << $p);
      $this->blocked[$d] = $b;
    }
    foreach ($ctx['teachers'] as $t => $T) {
      $u = [];
      foreach ($T['un'] as $k => $_) { $x = explode('-', (string)$k); $u[$x[0]] = ($u[$x[0]] ?? 0) | (1 << num($x[1] ?? 0)); }
      $this->un[$t] = $u;
    }
  }
  public function keys(array $s): array {
    $k = ['g:' . $s['group_id']];
    foreach (sched_ids($s['teacher_ids']) as $t) $k[] = 't:' . $t;
    $rid = $s['room_id'] ?? '';
    if (js_true($rid) && !($this->ctx['rooms'][$rid]['shareable'] ?? false)) $k[] = 'r:' . $rid;
    return $k;
  }
  public static function mask(int $st, int $len): int { return ((1 << $len) - 1) << $st; }
  public function add(array $s, int $sign): void {
    $d = num($s['day']); $start = num($s['start']); $len = num($s['length']);
    foreach ($this->keys($s) as $k) {
      for ($p = $start; $p < $start + $len; $p++) {
        $c = ($this->cnt[$k][$d][$p] ?? 0) + $sign;
        $this->cnt[$k][$d][$p] = $c;
        $cur = $this->m[$k][$d] ?? 0;
        $this->m[$k][$d] = $c > 0 ? ($cur | (1 << $p)) : ($cur & ~(1 << $p));
      }
    }
    $gk = $s['group_id'] . '|' . $s['course_id'];
    $this->gc[$gk][$d] = ($this->gc[$gk][$d] ?? 0) + $sign;
    foreach (sched_ids($s['teacher_ids']) as $t) $this->load[$t][$d] = ($this->load[$t][$d] ?? 0) + $sign * $len;
  }
  public function used(string $k, $d): int { return $this->m[$k][$d] ?? 0; }
}

function sched_popcount(int $x): int { $c = 0; while ($x) { $x &= $x - 1; $c++; } return $c; }

function sched_unit_info(SchedBlock $b, array $ctx): array {
  $t = []; $r = [];
  foreach ($b->unit->plans as $p) {
    foreach (sched_ids($p['teacher_ids'] ?? '') as $x) $t[$x] = 1;
    $rid = $p['room_id'] ?? '';
    if (js_true($rid) && !($ctx['rooms'][$rid]['shareable'] ?? false)) $r[$rid] = 1;
  }
  return [
    'teachers' => array_map('strval', array_keys($t)),
    'rooms' => array_map('strval', array_keys($r)),
    'groups' => array_map(fn($p) => (string)$p['group_id'], $b->unit->plans),
  ];
}

/** ช่องที่วางได้ทั้งหมดของก้อน เรียงจากดีที่สุด */
function sched_slots(SchedBlock $block, SchedOcc $occ, array $ctx, callable $rnd): array {
  $info = $block->info ?? ($block->info = sched_unit_info($block, $ctx));
  $res = []; $len = $block->length; $lunch = $ctx['lunch'][0] ?? 0;
  foreach ($ctx['days'] as $d) {
    for ($st = 1; $st + $len - 1 <= $ctx['periods']; $st++) {
      $mk = SchedOcc::mask($st, $len);
      if (($occ->blocked[$d] ?? 0) & $mk) continue;
      $ok = true; $score = $st * 1.5; $soft = 0;
      foreach ($info['groups'] as $g) { if ($occ->used('g:' . $g, $d) & $mk) { $ok = false; break; } }
      if ($ok) foreach ($info['teachers'] as $t) {
        if ($occ->used('t:' . $t, $d) & $mk) { $ok = false; break; }
        if (($occ->un[$t][$d] ?? 0) & $mk) { $ok = false; break; }
        $T = $ctx['teachers'][$t] ?? null;
        if ($T && $T['max'] && ($occ->load[$t][$d] ?? 0) + $len > $T['max']) $soft++;
      }
      if ($ok) foreach ($info['rooms'] as $r) { if ($occ->used('r:' . $r, $d) & $mk) { $ok = false; break; } }
      if (!$ok) continue;
      $score += $soft * 40;
      foreach ($block->unit->plans as $p) {
        $score += sched_popcount($occ->used('g:' . $p['group_id'], $d)) * 2;
        if (($occ->gc[$p['group_id'] . '|' . $p['course_id']][$d] ?? 0) > 0) $score += 60;
      }
      if ($lunch && $st < $lunch && $st + $len - 1 > $lunch) $score += 1000;
      $score += $rnd() * 3;
      $res[] = ['day' => $d, 'start' => $st, 'score' => $score];
    }
  }
  stable_usort($res, fn($a, $b) => $a['score'] <=> $b['score']);
  return $res;
}

function sched_make_sessions(SchedBlock $b, array $slot): array {
  return array_map(fn($p) => [
    'plan_id' => $p['id'], 'group_id' => $p['group_id'], 'course_id' => $p['course_id'], 'teacher_ids' => $p['teacher_ids'],
    'room_id' => js_true($p['room_id'] ?? '') ? $p['room_id'] : '', 'day' => $slot['day'], 'start' => $slot['start'], 'length' => $b->length,
  ], $b->unit->plans);
}

function sched_imul(int $a, int $b): int {
  $al = $a & 0xFFFF; $ah = ($a >> 16) & 0xFFFF;
  $bl = $b & 0xFFFF; $bh = ($b >> 16) & 0xFFFF;
  return ($al * $bl + ((($ah * $bl + $al * $bh) & 0xFFFF) << 16)) & 0xFFFFFFFF;
}

/** ตัวสุ่มแบบกำหนดค่าเริ่มต้น เพื่อให้จัดตารางซ้ำแล้วได้ผลเดิม */
function sched_rng(int $seed): callable {
  $seed &= 0xFFFFFFFF;
  return function () use (&$seed): float {
    $seed = ($seed + 0x6D2B79F5) & 0xFFFFFFFF;
    $t = sched_imul($seed ^ ($seed >> 15), 1 | $seed);
    $t = (($t + sched_imul($t ^ ($t >> 7), 61 | $t)) & 0xFFFFFFFF) ^ $t;
    return ($t ^ ($t >> 14)) / 4294967296;
  };
}

function sched_blocks_at(SchedBlock $b, int $day, int $st, array $placed): array {
  $info = $b->info; $mkEnd = $st + $b->length - 1; $out = [];
  foreach ($placed as $x) {
    if (num($x->slot['day']) !== $day) continue;
    $e = $x->slot['start'] + $x->length - 1;
    if ($x->slot['start'] > $mkEnd || $e < $st) continue;
    $xi = $x->info;
    if (array_intersect($xi['groups'], $info['groups']) || array_intersect($xi['teachers'], $info['teachers']) || array_intersect($xi['rooms'], $info['rooms'])) $out[] = $x;
  }
  return $out;
}

function sched_repair(array $unplaced, array &$placed, SchedOcc $occ, array $ctx, callable $rnd, float $deadline): array {
  $still = [];
  foreach ($unplaced as $b) {
    if (microtime(true) > $deadline) { $still[] = $b; continue; }
    $done = false;
    $options = [];
    foreach ($ctx['days'] as $d) {
      for ($st = 1; $st + $b->length - 1 <= $ctx['periods']; $st++) {
        $mk = SchedOcc::mask($st, $b->length);
        if (($occ->blocked[$d] ?? 0) & $mk) continue;
        foreach ($b->info['teachers'] as $t) { if (($occ->un[$t][$d] ?? 0) & $mk) continue 2; }
        $vs = sched_blocks_at($b, $d, $st, $placed);
        foreach ($vs as $v) foreach ($v->sessions as $s) $occ->add($s, -1);
        $free = true;
        foreach ($b->info['groups'] as $g) if ($occ->used('g:' . $g, $d) & $mk) $free = false;
        foreach ($b->info['teachers'] as $t) if ($occ->used('t:' . $t, $d) & $mk) $free = false;
        foreach ($b->info['rooms'] as $r) if ($occ->used('r:' . $r, $d) & $mk) $free = false;
        foreach ($vs as $v) foreach ($v->sessions as $s) $occ->add($s, 1);
        if ($free && $vs && count($vs) <= 2) $options[] = ['day' => $d, 'start' => $st, 'victims' => $vs, 'r' => $rnd() + count($vs)];
      }
    }
    stable_usort($options, fn($x, $y) => $x['r'] <=> $y['r']);
    for ($i = 0; $i < count($options) && !$done && $i < 40; $i++) {
      $o = $options[$i];
      foreach ($o['victims'] as $v) foreach ($v->sessions as $s) $occ->add($s, -1);
      $mine = sched_make_sessions($b, $o);
      foreach ($mine as $s) $occ->add($s, 1);
      $moves = []; $ok = true;
      foreach ($o['victims'] as $v) {
        $c = sched_slots($v, $occ, $ctx, $rnd)[0] ?? null;
        if (!$c) { $ok = false; break; }
        $ns = sched_make_sessions($v, $c);
        foreach ($ns as $s) $occ->add($s, 1);
        $moves[] = ['v' => $v, 'slot' => $c, 'sessions' => $ns];
      }
      if ($ok) {
        foreach ($moves as $m) { $m['v']->slot = $m['slot']; $m['v']->sessions = $m['sessions']; }
        $b->slot = ['day' => $o['day'], 'start' => $o['start']]; $b->sessions = $mine; $placed[] = $b;
        $done = true;
      } else {
        foreach ($moves as $m) foreach ($m['sessions'] as $s) $occ->add($s, -1);
        foreach ($mine as $s) $occ->add($s, -1);
        foreach ($o['victims'] as $v) foreach ($v->sessions as $s) $occ->add($s, 1);
      }
    }
    if (!$done) $still[] = $b;
  }
  return $still;
}

/**
 * จัดอัตโนมัติ: fixed = คาบที่มีอยู่แล้ว (ไม่ย้าย), plans = แผนที่ต้องจัด
 * คืน {added: [...], unplaced: [{plan_id, plans, length}]}
 */
function sched_auto(array $plans, array $fixed, array $ctx, array $opt = []): array {
  $attempts = $opt['attempts'] ?? 8;
  $deadline = $opt['deadline'] ?? (microtime(true) + 20);
  $load = sched_teacher_load($plans, $ctx);
  $best = null;
  for ($a = 0; $a < $attempts && microtime(true) < $deadline; $a++) {
    $rnd = sched_rng(1234 + $a * 7919);
    $occ = new SchedOcc($ctx);
    foreach ($fixed as $s) $occ->add($s, 1);
    $blocks = sched_units($plans, $fixed, $ctx);
    foreach ($blocks as $b) {
      $b->info = sched_unit_info($b, $ctx);
      $maxLoad = 0;
      foreach ($b->info['teachers'] as $t) $maxLoad = max($maxLoad, $load[$t] ?? 0);
      $b->diff = $b->length * 10 + count($b->unit->plans) * 15 + $maxLoad + ($b->info['rooms'] ? 8 : 0) + $rnd() * ($a === 0 ? 0 : 15);
    }
    stable_usort($blocks, fn($x, $y) => $y->diff <=> $x->diff);
    $placed = []; $unplaced = [];
    foreach ($blocks as $b) {
      $c = sched_slots($b, $occ, $ctx, $rnd)[0] ?? null;
      if (!$c) { $unplaced[] = $b; continue; }
      $b->slot = $c; $b->sessions = sched_make_sessions($b, $c);
      foreach ($b->sessions as $s) $occ->add($s, 1);
      $placed[] = $b;
    }
    // ซ่อม: ย้ายก้อนที่ขวางอยู่ (ไม่แตะคาบเดิม) แล้ววางก้อนค้าง
    for ($pass = 0; $pass < 3 && $unplaced && microtime(true) < $deadline; $pass++) {
      $unplaced = sched_repair($unplaced, $placed, $occ, $ctx, $rnd, $deadline);
    }
    $added = [];
    foreach ($placed as $b) foreach ($b->sessions as $s) { $s['id'] = 'new' . count($added); $added[] = $s; }
    $res = ['added' => $added, 'unplaced' => array_map(fn($b) => [
      'plan_id' => $b->unit->plans[0]['id'], 'plans' => array_map(fn($p) => $p['id'], $b->unit->plans), 'length' => $b->length,
    ], $unplaced)];
    if (!$best || count($res['unplaced']) < count($best['unplaced'])) $best = $res;
    if (!$best['unplaced']) break;
  }
  return $best ?? ['added' => [], 'unplaced' => []];
}

/** วิชาฝึกงาน/ทวิภาคี/เรียนที่สถานประกอบการ (ระเบียบข้อ 6) */
function sched_is_workplace(array $planOrSession, array $ctx): bool {
  $p = $ctx['plans'][$planOrSession['plan_id'] ?? ''] ?? $planOrSession;
  $c = $ctx['courses'][$p['course_id'] ?? ''] ?? null;
  return (string)($p['workplace'] ?? '') === 'true' || ($c && ($c['course_type'] ?? '') === 'internship');
}
