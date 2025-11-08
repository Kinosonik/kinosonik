<?php
// /feedback_submit.php — endpoint autònom per enviar feedback
declare(strict_types=1);

// Preload + sessió (a l’arrel del projecte)
require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Bridge: exposa constants SMTP_* des d'ENV o des del secret
$cfg = $GLOBALS['SECRETS'] ?? [];
foreach (['SMTP_HOST','SMTP_USER','SMTP_PASS','SMTP_PORT'] as $K) {
  if (!defined($K)) {
    $v = $_ENV[$K] ?? getenv($K) ?? ($cfg[$K] ?? null);
    if ($v !== null && $v !== '') {
      define($K, (string)$v);
    }
  }
}
// (opcional) traça mínima
fb_log('SMTP_BRIDGE ' . json_encode([
  'HOST'=>defined('SMTP_HOST'),
  'USER'=>defined('SMTP_USER'),
  'PASS'=>defined('SMTP_PASS'),
  'PORT'=>defined('SMTP_PORT')
]));


// ─── Shim de config SMTP: constants o ENV ───
function smtp_get(string $k, string $fallback = ''): string {
  if (defined($k) && constant($k) !== '') return (string)constant($k);
  $v = $_ENV[$k] ?? getenv($k) ?? $fallback;
  return is_string($v) ? $v : $fallback;
}
$SMTP_HOST = smtp_get('SMTP_HOST');
$SMTP_USER = smtp_get('SMTP_USER');
$SMTP_PASS = smtp_get('SMTP_PASS');
$SMTP_PORT = (int)(smtp_get('SMTP_PORT') ?: '0');

if ($SMTP_HOST === '' || $SMTP_USER === '' || $SMTP_PASS === '' || $SMTP_PORT === 0) {
  fb_log("CFG_MISSING details host=$SMTP_HOST user=$SMTP_USER port=$SMTP_PORT (revisa constants o ENV)");
  json_fail('smtp_config_missing', 500);
}


/* ───────── Headers + helpers JSON ───────── */
@ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_fail(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function json_ok(array $data = []): never {
  echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
function fb_log(string $msg): void {
  static $p = null;
  if ($p === null) {
    $d = '/var/config/logs/riders';
    if (!is_dir($d)) @mkdir($d, 02775, true);
    $p = is_writable($d) ? "$d/feedback.log" : '/tmp/feedback.log';
  }
  @error_log(date('c')." $msg\n", 3, $p);
}

/* ───────── Tall per mètode ───────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  json_fail('method_not_allowed', 405);
}

/* ───────── CSRF + login ───────── */
if (function_exists('csrf_check_or_die')) {
  csrf_check_or_die(); // ja fa fail si toca
} else {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) json_fail('csrf_invalid', 403);
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) json_fail('login_required', 401);

/* ───────── Rate limit 30s ───────── */
$now = time();
$last = (int)($_SESSION['feedback_last_ts'] ?? 0);
if ($now - $last < 30) json_fail('rate_limited', 429);
$_SESSION['feedback_last_ts'] = $now;

/* ───────── Inputs ───────── */
$type = strtolower(trim((string)($_POST['type'] ?? '')));
$msg  = trim((string)($_POST['message'] ?? ''));
$allowed = ['translation_error','suggestion','bug','idea','proposal','other'];
if (!in_array($type, $allowed, true)) json_fail('bad_type', 400);
$len = mb_strlen($msg);
if ($len < 10 || $len > 5000) json_fail('bad_length', 400);

/* ───────── Identitat + meta ───────── */
$email = (string)($_SESSION['email'] ?? '');
$nom   = trim((string)($_SESSION['nom'] ?? ''));
$cogn  = trim((string)($_SESSION['cognoms'] ?? ''));
$nomComplert = trim($nom . ' ' . $cogn);
$rol   = (string)($_SESSION['tipus_usuari'] ?? '');

$ua    = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$ip    = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ref   = (string)($_SERVER['HTTP_REFERER'] ?? '');
$nowEU = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('d/m/Y H:i');

$ctxUrl   = (string)($_POST['context_url'] ?? '');
$ctxTitle = (string)($_POST['context_title'] ?? '');

/* ───────── Cos del correu ───────── */
$body = "Nou feedback de l’usuari\n"
      . "------------------------\n"
      . "Data/Hora: $nowEU (Europe/Madrid)\n"
      . "Usuari ID: $uid\n"
      . "Nom: $nomComplert\n"
      . "Email: $email\n"
      . "Rol: $rol\n"
      . "Tipus: $type\n"
      . "URL: $ctxUrl\n"
      . "Títol: $ctxTitle\n"
      . "Referer: $ref\n"
      . "IP: $ip\n"
      . "User-Agent: $ua\n"
      . "\nMissatge:\n$msg\n";

/* ───────── Composer autoload ───────── */
$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($autoload)) json_fail('autoload_missing', 500);
if (@include_once $autoload === false) json_fail('autoload_include_failed', 500);

// ─── SHIM + DIAGNÒSTIC SMTP ───
function smtp_get_any(string $k): ?string {
  // 1) Constant?
  if (defined($k) && constant($k) !== '') return (string)constant($k);
  // 2) $_ENV / getenv
  $v = $_ENV[$k] ?? getenv($k);
  return (is_string($v) && $v !== '') ? $v : null;
}

// Mira si preload.php ha carregat un $SECRETS usable
$SECRETS_ARR = $GLOBALS['SECRETS'] ?? null;

// DEBUG (sense exposar contrasenyes): treu estat a log
$probe = [
  'has_SECRETS_array' => is_array($SECRETS_ARR),
  'SECRETS_keys'      => is_array($SECRETS_ARR) ? array_slice(array_keys($SECRETS_ARR), 0, 20) : null,
  'consts'            => [
    'SMTP_HOST' => defined('SMTP_HOST'),
    'SMTP_USER' => defined('SMTP_USER'),
    'SMTP_PASS' => defined('SMTP_PASS'),
    'SMTP_PORT' => defined('SMTP_PORT'),
  ],
  'env'               => [
    'SMTP_HOST' => (isset($_ENV['SMTP_HOST']) || getenv('SMTP_HOST') !== false),
    'SMTP_USER' => (isset($_ENV['SMTP_USER']) || getenv('SMTP_USER') !== false),
    'SMTP_PASS' => (isset($_ENV['SMTP_PASS']) || getenv('SMTP_PASS') !== false),
    'SMTP_PORT' => (isset($_ENV['SMTP_PORT']) || getenv('SMTP_PORT') !== false),
  ],
];
fb_log('SMTP_PROBE ' . json_encode($probe));

$SMTP_HOST = smtp_get_any('SMTP_HOST');
$SMTP_USER = smtp_get_any('SMTP_USER');
$SMTP_PASS = smtp_get_any('SMTP_PASS');
$SMTP_PORT = smtp_get_any('SMTP_PORT');

// Si no trobats, intenta llegir-los directament del $SECRETS (array del secret.php)
if ((!$SMTP_HOST || !$SMTP_USER || !$SMTP_PASS || !$SMTP_PORT) && is_array($SECRETS_ARR)) {
  $SMTP_HOST = $SMTP_HOST ?: (string)($SECRETS_ARR['SMTP_HOST'] ?? '');
  $SMTP_USER = $SMTP_USER ?: (string)($SECRETS_ARR['SMTP_USER'] ?? '');
  $SMTP_PASS = $SMTP_PASS ?: (string)($SECRETS_ARR['SMTP_PASS'] ?? '');
  $SMTP_PORT = $SMTP_PORT ?: (string)($SECRETS_ARR['SMTP_PORT'] ?? '');
}

$SMTP_PORT_INT = (int)$SMTP_PORT;
if ($SMTP_HOST === '' || $SMTP_USER === '' || $SMTP_PASS === '' || $SMTP_PORT_INT === 0) {
  fb_log("CFG_MISSING host=" . ($SMTP_HOST!==''?'Y':'N') .
         " user=" . ($SMTP_USER!==''?'Y':'N') .
         " pass=" . ($SMTP_PASS!==''?'Y':'N') .
         " port=" . ($SMTP_PORT_INT>0?'Y':'N'));
  json_fail('smtp_config_missing', 500);
}

/* ───────── Enviament ───────── */
use PHPMailer\PHPMailer\PHPMailer;

try {
  // ✅ Validació de config SMTP abans d'enviar
  foreach (['SMTP_HOST','SMTP_USER','SMTP_PASS','SMTP_PORT'] as $C) {
    if (!defined($C) || constant($C) === '' || constant($C) === null) {
      fb_log("CFG_MISSING $C");
      json_fail('smtp_config_missing', 500);
    }
  }
  if (!extension_loaded('openssl')) {
    fb_log('EXT_MISSING openssl'); // molts SMTP requereixen TLS
  }

$m = new PHPMailer(true);
$m->CharSet   = 'UTF-8';
$m->isSMTP();
$m->Host       = $SMTP_HOST;
$m->SMTPAuth   = true;
$m->Username   = $SMTP_USER;
$m->Password   = $SMTP_PASS;
$m->Port       = $SMTP_PORT_INT;
$m->SMTPSecure = ($SMTP_PORT_INT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
$m->SMTPAutoTLS = ($SMTP_PORT_INT !== 465);



  // 🔐 Ajust automàtic d’encriptació segons port
  if ((int)SMTP_PORT === 465) {
    $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   // SMTPS
  } else {
    $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 587 típic
    $m->SMTPAutoTLS = true;
  }

  // 🕵️ Debug cap al log (no a la sortida)
  $smtpDbg = '';
  $m->SMTPDebug = 2; // 0=off, 2=client+server
  $m->Debugoutput = function($str, $level) use (&$smtpDbg) {
    $smtpDbg .= "[$level] $str\n";
  };

  $m->Timeout = 20;
  $m->SMTPKeepAlive = false;

  // From del teu domini (DMARC-friendly) i Reply-To de l’usuari
  $m->setFrom('riders@kinosonik.com', 'Kinosonik Riders');
  if ($email) $m->addReplyTo($email, $nomComplert ?: $email);
  $m->addAddress('riders@kinosonik.com', 'Riders Feedback');

  $m->Subject = "[Riders] Feedback ($type) — uid:$uid";
  $m->Body    = $m->AltBody = $body;

  $m->send();

  fb_log("FEEDBACK uid=$uid type=$type ip=$ip");
  json_ok();

} catch (\Throwable $e) {
  // 📜 Traça completa al log: missatge, ErrorInfo i diàleg SMTP
  $errInfo = (isset($m) && property_exists($m,'ErrorInfo')) ? $m->ErrorInfo : 'no_mailer';
  fb_log("FEEDBACK_FAIL uid=$uid err=".$e->getMessage()." | info=".$errInfo."\nSMTP:\n".$smtpDbg);
  json_fail('internal_error', 500);
}

