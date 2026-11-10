<?php
/**
 * สิทธิ์รายเมนูของผู้ใช้: บทบาทกำหนดเมนูเริ่มต้น แล้วผู้ดูแลระบบเพิ่มหรือถอนรายคนได้
 * เก็บเฉพาะส่วนที่ต่างจากบทบาทใน users.menu_perms = {"grant":[...],"deny":[...]}
 * รูปแบบรายการ: รหัสเมนู (ตรงกับ NAV ในหน้าเว็บ) => [บทบาทเริ่มต้น, คำสั่ง API ของเมนูนั้น]
 */
const MENU_ACCESS = [
  'dashboard' => ['dept', []],
  'mine' => ['teacher', []],
  'schedule' => ['dept', ['placeSession', 'removeSession', 'toggleLock', 'runAutoSchedule', 'clearSchedule']],
  'plans' => ['dept', ['copyPlans']],
  'plancheck' => ['teacher', []],
  't-PlcSlots' => ['dept', []],
  'manual' => ['teacher', []],
  'reports' => ['teacher', ['exportToSheet']],
  'deptview' => ['teacher', []],
  'hours' => ['teacher', []],
  'log' => ['dept', ['getActivityLog']],
  'teach-report' => ['teacher', ['teachReportSlots', 'teachReportSave', 'teachReportDelete', 'teachReportImageAdd', 'teachReportImages', 'teachReportImageDelete']],
  'teach-review' => ['dept', ['teachReportList', 'teachReportReview', 'teachReportSummary', 'teachReportImages']],
  'intern' => ['teacher', ['superviseList', 'visitList', 'visitSave', 'visitDelete', 'visitImageAdd', 'visitImages', 'visitImageDelete', 'visitTeacherList', 'visitDocList', 'visitDocGet', 'visitDocSave', 'visitDocDelete']],
  'intern-summary' => ['dept', ['superviseList']],
  'ot-claims' => ['teacher', ['otAlerts', 'otTeacherData', 'otSaveClaim', 'otUnlockClaim']],
  'ot-subs' => ['teacher', ['otSaveSub', 'otSubReport', 'otCancelSub', 'otListSubs', 'subDocList', 'subDocGet', 'subDocSave', 'subDocDelete']],
  'ot-summary' => ['dept', ['otDeptSummary', 'otSubmit']],
  'ot-cover' => ['dept', ['otCoverSheet']],
  'ot-teachers' => ['curriculum', ['otSuggestSchemes']],
  't-OtSchemes' => ['curriculum', []],
  't-OtPerHead' => ['curriculum', []],
  't-OtRounds' => ['curriculum', []],
  't-OtBlocked' => ['curriculum', []],
  'ot-holidays' => ['curriculum', []],
  't-Semesters' => ['curriculum', []],
  't-Departments' => ['curriculum', []],
  't-Majors' => ['curriculum', []],
  't-Teachers' => ['curriculum', []],
  't-Buildings' => ['curriculum', []],
  't-Rooms' => ['curriculum', []],
  't-Courses' => ['curriculum', []],
  't-Groups' => ['curriculum', []],
  'windows' => ['curriculum', ['windowList', 'windowCreateMissing', 'windowMove', 'windowSave', 'windowOpenNow', 'windowDelete']],
  'import' => ['curriculum', ['listTabSemesters', 'importFromTAB']],
  'issues' => ['teacher', ['issueList', 'issueSave', 'issueDelete']],
  // เมนูระบบ: ให้ผู้อื่นไม่ได้ เพราะการจัดการบัญชีหรือกู้คืนข้อมูลจะข้ามการกำหนดสิทธิ์โดยผู้ดูแลระบบ
  'users' => ['admin', []],
  'colors' => ['admin', []],
  'settings' => ['admin', []],
  'backup' => ['admin', []],
];

/** คำสั่งที่ใช้ได้หลายตาราง จึงตรวจเมนูจากชื่อตารางแทน */
const MENU_TABLE_APIS = ['saveRow', 'deleteRow', 'bulkImport'];
const MENU_TABLES = [
  'Plans' => ['plans'], 'PlcSlots' => ['t-PlcSlots'], 'OtAbsences' => ['ot-claims', 'ot-holidays'], 'Windows' => ['windows'],
  'Teachers' => ['t-Teachers', 'ot-teachers'], 'OtSchemes' => ['t-OtSchemes'], 'OtPerHead' => ['t-OtPerHead'], 'OtRounds' => ['t-OtRounds'], 'OtBlocked' => ['t-OtBlocked'],
  'Semesters' => ['t-Semesters'], 'Departments' => ['t-Departments'], 'Majors' => ['t-Majors'], 'Buildings' => ['t-Buildings'], 'Rooms' => ['t-Rooms'], 'Courses' => ['t-Courses'], 'Groups' => ['t-Groups'],
];

function perm_is_admin_only(string $menu): bool { return (MENU_ACCESS[$menu][0] ?? 'admin') === 'admin'; }

function perm_is_default(string $menu, string $role): bool {
  return !perm_is_admin_only($menu) && (USER_ROLES[$role] ?? 0) >= USER_ROLES[MENU_ACCESS[$menu][0]];
}

/** อ่านค่าที่เก็บไว้ และตัดรหัสเมนูที่ไม่รู้จักหรือให้ผู้อื่นไม่ได้ออก */
function perm_parse($raw): array {
  $o = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
  $clean = fn($k) => array_values(array_filter(is_array($o[$k] ?? null) ? $o[$k] : [], fn($m) => is_string($m) && isset(MENU_ACCESS[$m]) && !perm_is_admin_only($m)));
  return ['grant' => $clean('grant'), 'deny' => $clean('deny')];
}

/** เมนูที่ผู้ใช้เข้าได้จริง ผู้ดูแลระบบเข้าได้ทุกเมนูเสมอ เพื่อไม่ให้ล็อกตัวเองออกจากระบบ */
function perm_effective(array $user): array {
  $role = (string)($user['role'] ?? '');
  if ($role === 'admin') return array_keys(MENU_ACCESS);
  $p = perm_parse($user['menu_perms'] ?? '');
  $out = [];
  foreach (array_keys(MENU_ACCESS) as $m) {
    if (in_array($m, $p['grant'], true) || (perm_is_default($m, $role) && !in_array($m, $p['deny'], true))) $out[] = $m;
  }
  return $out;
}

/** แปลงเมนูที่ผู้ดูแลระบบติ๊กไว้เป็นส่วนต่างจากบทบาท */
function perm_encode(string $role, $selected): string {
  if ($role === 'admin') return '';
  $sel = array_filter((array)$selected, 'is_string');
  $p = ['grant' => [], 'deny' => []];
  foreach (array_keys(MENU_ACCESS) as $m) {
    if (perm_is_admin_only($m)) continue;
    $on = in_array($m, $sel, true); $def = perm_is_default($m, $role);
    if ($on && !$def) $p['grant'][] = $m;
    if (!$on && $def) $p['deny'][] = $m;
  }
  return $p['grant'] || $p['deny'] ? json_encode($p) : '';
}

/** เมนูที่คำขอนี้ต้องมีอย่างน้อยหนึ่งเมนู ว่าง = คำสั่งใช้ร่วมกันหลายหน้า ไม่ผูกกับเมนู */
function perm_request_menus(string $fn, array $args): array {
  if (in_array($fn, MENU_TABLE_APIS, true)) {
    $name = $args[1] ?? '';
    return is_string($name) ? (MENU_TABLES[$name] ?? []) : [];
  }
  $out = [];
  foreach (MENU_ACCESS as $m => $a) if (in_array($fn, $a[1], true)) $out[] = $m;
  return $out;
}

/** ตรวจเมนูของคำขอ และยกระดับบทบาทเฉพาะคำขอนี้ตามเมนูที่ได้รับ เช่น ครูที่ได้เมนูจัดตารางทำงานแบบวิชาการแผนกของตน */
function perm_apply_request(array $u, array $menus): array {
  $need = $GLOBALS['REQ_MENUS'] ?? [];
  if (!$need || $u['role'] === 'admin') return $u;
  $have = array_intersect($need, $menus);
  if (!$have) throw new Exception('ไม่มีสิทธิ์ใช้เมนูนี้ กรุณาติดต่อผู้ดูแลระบบ');
  foreach ($have as $m) if (USER_ROLES[MENU_ACCESS[$m][0]] > USER_ROLES[$u['role']]) $u['role'] = MENU_ACCESS[$m][0];
  return $u;
}

/**
 * บทบาทสำหรับอ่านข้อมูลครู ต้องสูงพอกับเมนูที่ได้รับ มิฉะนั้นฟิลด์ที่ถูกซ่อนจะถูกบันทึกทับเป็นค่าว่าง
 * ข้อมูลส่วนตัวของครูทุกแผนกเปิดเฉพาะผู้ที่ได้เมนูแก้ข้อมูลครูเท่านั้น
 */
function perm_data_view(array $me): array {
  $menus = $me['menus'] ?? [];
  if (USER_ROLES[$me['role']] >= USER_ROLES['curriculum']) return $me;
  if (array_intersect(['t-Teachers', 'ot-teachers'], $menus)) { $me['role'] = 'curriculum'; return $me; }
  foreach ($menus as $m) if (MENU_ACCESS[$m][0] === 'dept') { $me['role'] = 'dept'; break; }
  return $me;
}
