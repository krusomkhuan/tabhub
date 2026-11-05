<?php
/** ตัวรับคำขอเว็บ: หน้าเว็บ, API และไฟล์ดาวน์โหลด */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/sched.php';
require_once __DIR__ . '/ot.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/api_core.php';
require_once __DIR__ . '/api_sched.php';
require_once __DIR__ . '/api_ot.php';
require_once __DIR__ . '/tab_import.php';
require_once __DIR__ . '/api_extra.php';

// เรียกจากเว็บได้เฉพาะรายการนี้เท่านั้น
const API_ALLOW = [
  'login', 'logout', 'getCollegeName', 'getWebAppUrl', 'getPublicData',
  'changePassword', 'listUsers', 'saveUser', 'deleteUser', 'bulkCreateTeacherUsers',
  'bootstrap', 'listTable', 'saveRow', 'deleteRow', 'bulkImport', 'saveSettings', 'copyPlans',
  'getSchedule', 'placeSession', 'removeSession', 'toggleLock', 'checkPlacement', 'runAutoSchedule', 'clearSchedule', 'getActivityLog',
  'teachingHours', 'progress', 'exportToSheet',
  'otTeacherData', 'otSaveClaim', 'otUnlockClaim', 'otSaveSub', 'otSubReport', 'otCancelSub', 'otListSubs', 'otAlerts', 'otDeptSummary', 'otSubmit', 'otSuggestSchemes',
  'importFromTAB', 'listTabSemesters',
  'teachReportSlots', 'teachReportSave', 'teachReportList', 'teachReportReview', 'teachReportDelete', 'teachReportSummary',
  'internList', 'internSave', 'internBulkSave', 'internDelete', 'visitList', 'visitSave', 'visitDelete', 'otCoverSheet',
];
const LOGIN_MAX_FAILS = 5;
const LOGIN_WINDOW_SEC = 900;

function send_headers(int $code, string $type, array $extra = []): void {
  http_response_code($code);
  header('Content-Type: ' . $type);
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: same-origin');
  header('Cache-Control: no-store');
  foreach ($extra as $h) header($h);
}

function send_json($obj, int $code = 200): void {
  send_headers($code, 'application/json; charset=utf-8');
  echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function client_ip(): string {
  // เชื่อ X-Forwarded-For เฉพาะเมื่อมี reverse proxy ของเราอยู่ข้างหน้า (TRUST_PROXY=true)
  if (cfg('TRUST_PROXY') === 'true') {
    $x = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    if ($x !== '') return $x;
  }
  return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function page_url(string $base): string {
  $public = cfg('PUBLIC_URL');
  if ($public !== '') return $public;
  $hdr = substr((string)($_SERVER['HTTP_X_PAGE_URL'] ?? ''), 0, 300);
  if ($hdr !== '') return $hdr;
  $proto = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
  if ($proto === '') $proto = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
  return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/';
}

function show_error_message(Throwable $e, string $fn): string {
  $m = $e->getMessage();
  if (preg_match('/[\x{0E00}-\x{0E7F}]|SESSION_EXPIRED/u', $m) && !($e instanceof PDOException)) return $m;
  error_log('[api ' . $fn . '] ' . $e);
  return 'เกิดข้อผิดพลาดในเซิร์ฟเวอร์ กรุณาลองใหม่อีกครั้ง';
}

function handle_api(string $fn, string $base): void {
  if (!in_array($fn, API_ALLOW, true)) { send_json(['error' => 'ไม่พบคำสั่ง ' . mb_substr($fn, 0, 60)], 404); return; }
  $body = json_decode((string)file_get_contents('php://input'), true);
  if (json_last_error() !== JSON_ERROR_NONE && file_get_contents('php://input') !== '') { send_json(['error' => 'คำขอไม่ถูกต้อง']); return; }
  $args = (is_array($body) && isset($body['args']) && is_array($body['args'])) ? array_values($body['args']) : [];
  $GLOBALS['REQ_URL'] = page_url($base);

  $loginKey = null;
  try {
    if ($fn === 'login') {
      $user = strtolower(trim(mb_substr(str($args[0] ?? ''), 0, 100)));
      $loginKey = 'loginfail:' . sha1(client_ip() . '|' . $user);
      $raw = kvGet($loginKey);
      $f = $raw ? json_decode($raw, true) : null;
      if ($f && ($f['n'] ?? 0) >= LOGIN_MAX_FAILS) {
        send_json(['error' => 'เข้าระบบผิดหลายครั้ง กรุณารออีก ' . max(1, (int)ceil(($f['until'] - time()) / 60)) . ' นาทีแล้วลองใหม่']);
        return;
      }
    }
    // ถ้าคำขอล้มเหลวกลางคัน transaction จะย้อนข้อมูลกลับทั้งหมด
    db()->beginTransaction();
    $result = ('api_' . $fn)(...$args);
    db()->commit();
    release_lock();
    if ($loginKey) kvDel($loginKey);
    if (random_int(0, 99) === 0) kvPurgeExpired();
    send_json(['result' => $result]);
  } catch (Throwable $e) {
    try { if (db()->inTransaction()) db()->rollBack(); release_lock(); } catch (Throwable $_) {}
    $msg = show_error_message($e, $fn);
    if ($loginKey) {
      try {
        $raw = kvGet($loginKey);
        $f = $raw ? json_decode($raw, true) : null;
        $until = $f['until'] ?? time() + LOGIN_WINDOW_SEC;
        kvPut($loginKey, json_encode(['n' => ($f['n'] ?? 0) + 1, 'until' => $until]), max(1, $until - time()));
      } catch (Throwable $_) {}
      usleep(800000); // หน่วงเมื่อรหัสผ่านผิด
    }
    send_json(['error' => $msg]);
  }
}

function handle_download(string $id): void {
  $file = APP_ROOT . '/data/exports/' . $id . '.xlsx';
  if (!is_file($file) || filemtime($file) < time() - 900) { send_headers(404, 'text/plain; charset=utf-8'); echo 'ไฟล์หมดอายุแล้ว กรุณาส่งออกใหม่'; return; }
  $name = (string)@file_get_contents(APP_ROOT . '/data/exports/' . $id . '.name');
  send_headers(200, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ["Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name !== '' ? $name : 'export.xlsx')]);
  readfile($file);
}

/** ประกอบหน้า HTML จากไฟล์ใน views/ */
function view_src(string $name): string {
  if (is_file(APP_ROOT . '/views/' . $name . '.js')) return "<script>\n" . file_get_contents(APP_ROOT . '/views/' . $name . '.js') . "\n</script>";
  return (string)file_get_contents(APP_ROOT . '/views/' . $name . '.html');
}

function render_page(string $page, array $params, string $title): string {
  $html = view_src($page);
  $html = preg_replace_callback("/<\?!= *include\('(\w+)'\); *\?>/", fn($m) => view_src($m[1]), $html);
  // JSON_HEX_TAG แปลง < และ > เพื่อไม่ให้ค่าจาก URL ปิดแท็ก script ได้
  $p = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_HEX_TAG | JSON_HEX_AMP);
  $html = preg_replace_callback('/<\?!= *params *\?>/', fn() => $p, $html);
  $html = str_replace('<base target="_top">', "<base target=\"_self\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n  <title>" . htmlspecialchars($title) . '</title>', $html);
  return str_replace('<meta charset="utf-8">', "<meta charset=\"utf-8\">\n" . file_get_contents(APP_ROOT . '/views/ClientShim.html'), $html);
}

function handle_request(): void {
  // ตำแหน่งของระบบหาจากที่อยู่ของ index.php เช่น /tabhub หรือกำหนดเองด้วย BASE_PATH
  $auto = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
  $base = rtrim(cfg('BASE_PATH', $auto), '/');
  $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($base !== '') {
    if ($path === '/' || $path === $base) { send_headers(302, 'text/plain', ['Location: ' . $base . '/']); return; }
    if (!str_starts_with($path, $base . '/')) { send_headers(404, 'text/plain; charset=utf-8'); echo 'ไม่พบหน้า'; return; }
    $path = substr($path, strlen($base));
  }
  if ($method === 'POST' && str_starts_with($path, '/api/')) { handle_api(rawurldecode(substr($path, 5)), $base); return; }
  if ($method !== 'GET' && $method !== 'HEAD') { send_headers(405, 'text/plain'); echo 'Method Not Allowed'; return; }
  if ($path === '/' || $path === '/index.php' || $path === '/index.html') {
    $params = array_map('strval', array_filter($_GET, 'is_string'));
    $pub = ($params['page'] ?? '') === 'public';
    send_headers(200, 'text/html; charset=utf-8');
    echo render_page($pub ? 'Public' : 'Index', $params, $pub ? 'ตารางเรียน / ตารางสอน' : 'ระบบจัดตารางเรียน–ตารางสอน');
    return;
  }
  if ($path === '/healthz') { send_json(['ok' => true]); return; }
  if (preg_match('#^/download/([a-f0-9]{24})$#', $path, $m)) { handle_download($m[1]); return; }
  send_headers(404, 'text/plain; charset=utf-8');
  echo 'ไม่พบหน้า';
}
