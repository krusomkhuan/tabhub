<?php
/**
 * หน้าติดตั้งระบบ เปิดที่ /tabhub/install.php
 * กำหนดฐานข้อมูลและบัญชีผู้ดูแลระบบ เมื่อติดตั้งเสร็จหน้านี้จะล็อกตัวเอง
 * จึงตั้งค่าทับหรือสร้างผู้ดูแลเพิ่มจากเว็บไม่ได้
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require __DIR__ . '/src/app.php';

const INSTALL_LOCK = APP_ROOT . '/data/install.lock';
const ENV_FILE = APP_ROOT . '/.env';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function has_admin(PDO $pdo): bool {
  if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) return false;
  $n = $pdo->query("SELECT COUNT(*) FROM `users` WHERE role = 'admin' AND pass_hash <> '' AND (active IS NULL OR active <> 'false')")->fetchColumn();
  return (int)$n > 0;
}

function write_lock(): void {
  if (!is_dir(APP_ROOT . '/data')) @mkdir(APP_ROOT . '/data', 0775, true);
  @file_put_contents(INSTALL_LOCK, date('c') . "\n");
}

/** locked = ติดตั้งแล้ว, broken = มีค่าตั้งค่าแต่เชื่อมฐานข้อมูลไม่ได้, open = ติดตั้งได้ */
function installer_state(): string {
  if (is_file(INSTALL_LOCK)) return 'locked';
  if (!is_file(ENV_FILE)) return 'open';
  try {
    if (has_admin(db_connect())) { write_lock(); return 'locked'; }
    return 'open';
  } catch (Throwable $e) {
    error_log('[install] ' . $e->getMessage());
    return 'broken';
  }
}

function requirements(): array {
  $writable = fn(string $d) => is_dir($d) ? is_writable($d) : is_writable(dirname($d));
  return [
    'PHP 7.4 ขึ้นไป' => PHP_VERSION_ID >= 70400,
    'ส่วนขยาย pdo_mysql' => extension_loaded('pdo_mysql'),
    'ส่วนขยาย mbstring' => extension_loaded('mbstring'),
    'ส่วนขยาย zlib' => extension_loaded('zlib'),
    'เขียนไฟล์ .env ได้' => is_file(ENV_FILE) ? is_writable(ENV_FILE) : is_writable(APP_ROOT),
    'เขียนโฟลเดอร์ data ได้' => $writable(APP_ROOT . '/data'),
  ];
}

function db_error_text(PDOException $e): string {
  $code = (int)($e->errorInfo[1] ?? $e->getCode());
  error_log('[install] ' . $e->getMessage());
  return [
    1045 => 'ชื่อผู้ใช้หรือรหัสผ่านฐานข้อมูลไม่ถูกต้อง',
    1044 => 'ผู้ใช้นี้ไม่มีสิทธิ์ใช้ฐานข้อมูลนี้ และสร้างฐานข้อมูลใหม่ไม่ได้',
    1049 => 'ไม่พบฐานข้อมูลนี้ และผู้ใช้นี้สร้างฐานข้อมูลใหม่ไม่ได้',
    2002 => 'เชื่อมต่อเซิร์ฟเวอร์ฐานข้อมูลไม่ได้ ตรวจว่าเปิด MySQL แล้ว และที่อยู่กับพอร์ตถูกต้อง',
  ][$code] ?? 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ (รหัส ' . $code . ')';
}

/** บันทึกค่าฐานข้อมูลลง .env โดยคงค่าอื่นในไฟล์เดิมไว้ */
function save_env(array $vals): void {
  $lines = is_file(ENV_FILE) ? preg_split('/\r?\n/', (string)file_get_contents(ENV_FILE)) : [];
  $lines = array_values(array_filter($lines, fn($l) => !preg_match('/^\s*(' . implode('|', array_keys($vals)) . ')\s*=/', $l)));
  while ($lines && trim(end($lines)) === '') array_pop($lines);
  if ($lines) $lines[] = '';
  foreach ($vals as $k => $v) $lines[] = $k . '="' . $v . '"';
  $text = implode("\n", $lines) . "\n";
  $tmp = ENV_FILE . '.tmp';
  if (file_put_contents($tmp, $text) !== strlen($text) || !rename($tmp, ENV_FILE)) { @unlink($tmp); throw new RuntimeException('เขียนไฟล์ .env ไม่สำเร็จ ตรวจสิทธิ์ของโฟลเดอร์'); }
  env_file(true);
}

function run_install(array $f): string {
  $dsn = 'mysql:host=' . $f['db_host'] . ';port=' . $f['db_port'] . ';charset=utf8mb4';
  $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
  // สร้างฐานข้อมูลถ้ายังไม่มี บนโฮสติ้งบางแห่งสร้างเองไม่ได้ จึงลองเชื่อมต่อตรงอีกครั้ง
  $server = new PDO($dsn, $f['db_user'], $f['db_password'], $opt);
  try { $server->exec('CREATE DATABASE IF NOT EXISTS `' . $f['db_name'] . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); }
  catch (PDOException $e) { /* ใช้ฐานข้อมูลที่มีอยู่แล้ว */ }
  $check = new PDO($dsn . ';dbname=' . $f['db_name'], $f['db_user'], $f['db_password'], $opt);
  $existingAdmin = has_admin($check);
  $check = null; $server = null;

  save_env(['DB_HOST' => $f['db_host'], 'DB_PORT' => $f['db_port'], 'DB_USER' => $f['db_user'], 'DB_PASSWORD' => $f['db_password'], 'DB_NAME' => $f['db_name']]);
  db_migrate();
  $s = readSettings();
  foreach (DEFAULT_SETTINGS as $k => $v) if (!array_key_exists($k, $s)) appendRows('Settings', [['key' => $k, 'value' => $v]]);
  if ($existingAdmin) { schema_mark_synced(); write_lock(); return 'พบบัญชีผู้ดูแลในฐานข้อมูลนี้อยู่แล้ว ระบบใช้ข้อมูลเดิมและไม่ได้สร้างบัญชีใหม่ เข้าระบบด้วยบัญชีเดิมได้เลย'; }
  saveSetting('college_name', $f['college']);
  appendRows('users', [['id' => new_id(), 'username' => $f['admin_user'], 'name' => $f['admin_name'], 'role' => 'admin', 'department_id' => '', 'teacher_id' => '', 'salt' => uuid(), 'pass_hash' => hash_password($f['admin_pass']), 'active' => true]]);
  schema_mark_synced();
  write_lock();
  return 'เข้าระบบด้วยชื่อผู้ใช้ ' . $f['admin_user'] . ' และรหัสผ่านที่เพิ่งตั้งได้เลย';
}

$state = installer_state();
$errors = [];
$done = '';
$reqs = requirements();
$reqsOk = !in_array(false, $reqs, true);

session_name('tabhub_install');
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$f = ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_user' => 'root', 'db_password' => '', 'db_name' => 'tabhub', 'college' => DEFAULT_SETTINGS['college_name'], 'admin_name' => 'ผู้ดูแลระบบ', 'admin_user' => 'admin', 'admin_pass' => ''];

if ($state === 'open' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  foreach ($f as $k => $v) if (isset($_POST[$k]) && is_string($_POST[$k])) $f[$k] = in_array($k, ['db_password', 'admin_pass'], true) ? $_POST[$k] : trim($_POST[$k]);
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) $errors[] = 'หน้าหมดอายุ กรุณาลองใหม่อีกครั้ง';
  if (!$reqsOk) $errors[] = 'เครื่องยังไม่พร้อมติดตั้ง ตรวจรายการด้านล่าง';
  if ($f['db_host'] === '' || !preg_match('/^[A-Za-z0-9._:-]+$/', $f['db_host'])) $errors[] = 'ที่อยู่เซิร์ฟเวอร์ฐานข้อมูลไม่ถูกต้อง';
  if (!ctype_digit($f['db_port']) || (int)$f['db_port'] < 1 || (int)$f['db_port'] > 65535) $errors[] = 'พอร์ตฐานข้อมูลไม่ถูกต้อง';
  if ($f['db_user'] === '' || preg_match('/[\r\n"]/', $f['db_user'])) $errors[] = 'กรุณาระบุชื่อผู้ใช้ฐานข้อมูล';
  if (preg_match('/[\r\n]/', $f['db_password'])) $errors[] = 'รหัสผ่านฐานข้อมูลขึ้นบรรทัดใหม่ไม่ได้';
  if (!preg_match('/^[A-Za-z0-9_]+$/', $f['db_name'])) $errors[] = 'ชื่อฐานข้อมูลใช้ได้เฉพาะ A-Z a-z 0-9 และ _';
  if ($f['college'] === '') $errors[] = 'กรุณาระบุชื่อวิทยาลัย';
  if ($f['admin_name'] === '') $errors[] = 'กรุณาระบุชื่อผู้ดูแลระบบ';
  if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $f['admin_user'])) $errors[] = 'ชื่อผู้ใช้ผู้ดูแลต้องมี 3 ตัวขึ้นไป ใช้ A-Z a-z 0-9 . _ -';
  if (mb_strlen($f['admin_pass']) < 8) $errors[] = 'รหัสผ่านผู้ดูแลต้องมีอย่างน้อย 8 ตัวอักษร';
  elseif ($f['admin_pass'] !== (string)($_POST['admin_pass2'] ?? '')) $errors[] = 'ยืนยันรหัสผ่านผู้ดูแลไม่ตรงกัน';
  if (!$errors) {
    try { $done = run_install($f); $state = 'done'; unset($_SESSION['csrf']); }
    catch (PDOException $e) { $errors[] = db_error_text($e); }
    catch (Throwable $e) { error_log('[install] ' . $e); $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'ติดตั้งไม่สำเร็จ ดูรายละเอียดใน error log ของเซิร์ฟเวอร์'; }
  }
}

$field = function (string $k, string $label, string $type = 'text', string $hint = '', string $auto = 'off') use ($f) {
  $val = $type === 'password' ? '' : ' value="' . h($f[$k] ?? '') . '"';
  return '<label for="' . $k . '">' . h($label) . '</label><input id="' . $k . '" name="' . $k . '" type="' . $type . '"' . $val . ' autocomplete="' . $auto . '">' . ($hint ? '<small>' . h($hint) . '</small>' : '');
};
send_headers($state === 'locked' ? 403 : 200, 'text/html; charset=utf-8', ['X-Frame-Options: DENY']);
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ติดตั้งระบบจัดตารางเรียน</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; padding: 24px 16px 40px; font-family: Sarabun, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif; font-size: 15px; color: #182430; background: #eef2f5; }
  .wrap { max-width: 620px; margin: 0 auto; }
  header { display: flex; align-items: center; gap: 14px; padding: 18px 20px; margin-bottom: 14px; border-radius: 16px; color: #fff; background: linear-gradient(115deg, #17406b, #235789 50%, #2e8bc0); }
  header img { border-radius: 12px; flex-shrink: 0; }
  h1 { margin: 0; font-size: 20px; } header p { margin: 2px 0 0; font-size: 13px; opacity: .9; }
  .card { background: #fff; border: 1px solid #d3dbe2; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; }
  h2 { margin: 0 0 10px; font-size: 16px; color: #235789; }
  label { display: block; margin: 12px 0 4px; font-size: 13px; font-weight: 600; }
  input { width: 100%; height: 42px; padding: 8px 10px; font: inherit; font-size: 16px; border: 1px solid #d3dbe2; border-radius: 7px; background: #fff; }
  input:focus { outline: 2px solid #cfe2f3; border-color: #235789; }
  small { display: block; margin-top: 3px; font-size: 12px; color: #62758a; }
  .two { display: grid; grid-template-columns: 1fr 120px; gap: 0 10px; }
  button, .btn { display: block; width: 100%; padding: 12px; font: inherit; font-weight: 700; text-align: center; text-decoration: none; color: #fff; background: #235789; border: 0; border-radius: 8px; cursor: pointer; }
  button:hover, .btn:hover { background: #1b466f; }
  button:disabled { opacity: .5; cursor: default; }
  .err { margin-bottom: 14px; padding: 12px 16px; color: #8f1d1d; background: #fde8e8; border-radius: 10px; }
  .err ul { margin: 0; padding-left: 18px; }
  .ok { margin-bottom: 14px; padding: 12px 16px; color: #17693d; background: #dff1e6; border-radius: 10px; }
  ul.req { list-style: none; margin: 0; padding: 0; } ul.req li { padding: 3px 0; } .yes { color: #17693d; } .no { color: #b4233a; font-weight: 700; }
  footer { text-align: center; font-size: 12px; color: #62758a; }
  code { background: #eef2f5; padding: 1px 5px; border-radius: 4px; }
  @media (max-width: 420px) { .two { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <header><img src="assets/icon-192.png" alt="" width="52" height="52"><div><h1>ติดตั้งระบบจัดตารางเรียน–ตารางสอน</h1><p>กำหนดฐานข้อมูลและผู้ดูแลระบบ</p></div></header>

<?php if ($state === 'locked'): ?>
  <div class="card"><h2>ติดตั้งระบบแล้ว</h2><p>หน้าติดตั้งถูกล็อกเพื่อความปลอดภัย จึงแก้ค่าจากหน้านี้ไม่ได้</p><p><a class="btn" href="./">ไปหน้าเข้าสู่ระบบ</a></p></div>
<?php elseif ($state === 'broken'): ?>
  <div class="card"><h2>เชื่อมต่อฐานข้อมูลไม่ได้</h2><p>เครื่องนี้มีไฟล์ <code>.env</code> อยู่แล้ว แต่เชื่อมต่อฐานข้อมูลไม่ได้ ตรวจว่าเปิด MySQL ใน XAMPP แล้วโหลดหน้านี้ใหม่</p><p>ถ้าต้องการติดตั้งใหม่ ให้ลบไฟล์ <code>.env</code> บนเครื่องเซิร์ฟเวอร์ก่อน หน้าเว็บจะไม่ยอมเขียนทับค่าเดิมเพื่อความปลอดภัย</p></div>
<?php elseif ($state === 'done'): ?>
  <div class="ok"><b>ติดตั้งเรียบร้อย</b><br><?= h($done) ?></div>
  <div class="card"><p>หน้าติดตั้งถูกล็อกแล้ว ค่าฐานข้อมูลเก็บอยู่ในไฟล์ <code>.env</code></p><a class="btn" href="./">ไปหน้าเข้าสู่ระบบ</a></div>
<?php else: ?>
  <?php if ($errors): ?><div class="err" role="alert"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if (!$reqsOk): ?>
  <div class="card"><h2>ตรวจความพร้อมของเครื่อง</h2><ul class="req"><?php foreach ($reqs as $name => $ok): ?><li class="<?= $ok ? 'yes' : 'no' ?>"><?= $ok ? '✓' : '✗' ?> <?= h($name) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <div class="card"><h2>1. ฐานข้อมูล</h2>
      <div class="two"><div><?= $field('db_host', 'ที่อยู่เซิร์ฟเวอร์') ?></div><div><?= $field('db_port', 'พอร์ต') ?></div></div>
      <?= $field('db_name', 'ชื่อฐานข้อมูล', 'text', 'ถ้ายังไม่มี ระบบจะสร้างให้ ถ้ามีข้อมูลอยู่แล้วจะไม่ลบข้อมูลเดิม') ?>
      <?= $field('db_user', 'ชื่อผู้ใช้ฐานข้อมูล', 'text', 'XAMPP ค่าเริ่มต้นคือ root') ?>
      <?= $field('db_password', 'รหัสผ่านฐานข้อมูล', 'password', 'XAMPP ค่าเริ่มต้นไม่มีรหัสผ่าน เว้นว่างได้', 'new-password') ?>
    </div>
    <div class="card"><h2>2. วิทยาลัยและผู้ดูแลระบบ</h2>
      <?= $field('college', 'ชื่อวิทยาลัย') ?>
      <?= $field('admin_name', 'ชื่อผู้ดูแลระบบ') ?>
      <?= $field('admin_user', 'ชื่อผู้ใช้สำหรับเข้าระบบ', 'text', '', 'username') ?>
      <?= $field('admin_pass', 'รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)', 'password', '', 'new-password') ?>
      <label for="admin_pass2">ยืนยันรหัสผ่าน</label><input id="admin_pass2" name="admin_pass2" type="password" autocomplete="new-password">
    </div>
    <button type="submit"<?= $reqsOk ? '' : ' disabled' ?>>ติดตั้งระบบ</button>
  </form>
<?php endif; ?>
  <footer><p>เวอร์ชัน <?= h(APP_VERSION) ?></p></footer>
</div>
</body>
</html>
