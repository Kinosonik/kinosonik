<?php
// php/feedback.php — envia feedback a riders@kinosonik.com
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ────────── Headers i JSON helpers ────────── */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_fail(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function json_ok(array $data = []): never {
  echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ────────── Tall ràpid per mètode ────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  json_fail('method_not_allowed', 405);
}

/* ────────── CSRF + login (mateix patró) ────────── */
if (function_exists('csrf_check_or_die')) {
  csrf_check_or_die(); // ja respon en cas d’error
} else {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) json_fail('csrf_invalid', 403);
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) json_fail('login_required', 401);

/* ────────── Inputs ────────── */
$type = strtolower(trim((string)($_POST['type'] ?? '')));
$msg  = trim((string)($_POST['message'] ?? ''));

$allowed = ['translation_error','suggestion','bug','idea','proposal','other'];
if (!in_array($type, $allowed, true)) json_fail('bad_type', 400);
$len = mb_strlen($msg);
if ($len < 10 || $len > 5000) json_fail('bad_length', 400);

/* ────────── Identitat i metadades de sessió ────────── */
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

/* ────────── Cos del correu ────────── */
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

/* ────────── Composer autoload (patró teu) ────────── */
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_readable($autoload)) json_fail('autoload_missing', 500);
$__ok = @include_once $autoload;
if ($__ok === false) json_fail('autoload_include_failed', 500);

/* ────────── Enviament (PHPMailer) ────────── */
use PHPMailer\PHPMailer\PHPMailer;

try {
  // SMTP_* definides al secret (com ja tens)
  $m = new PHPMailer(true);
  $m->CharSet   = 'UTF-8';
  $m->isSMTP();
  $m->Host       = SMTP_HOST;
  $m->SMTPAuth   = true;
  $m->Username   = SMTP_USER;
  $m->Password   = SMTP_PASS;
  $m->Port       = SMTP_PORT;
  $m->SMTPSecure = (SMTP_PORT == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

  // From del teu domini (DMARC-friendly) i Reply-To de l’usuari
  $m->setFrom('riders@kinosonik.com', 'Kinosonik Riders');
  if ($email) $m->addReplyTo($email, $nomComplert ?: $email);
  $m->addAddress('riders@kinosonik.com', 'Riders Feedback');

  $m->Subject = "[Riders] Feedback ($type) — uid:$uid";
  $m->Body    = $m->AltBody = $body;

  $m->send();

  // Log best-effort
  @error_log(date('c')." FEEDBACK uid=$uid type=$type ip=$ip\n", 3, '/var/config/logs/riders/feedback.log');

  json_ok();
} catch (\Throwable $e) {
  @error_log(date('c')." FEEDBACK_FAIL uid=$uid err=".$e->getMessage()."\n", 3, '/var/config/logs/riders/feedback.log');
  json_fail('internal_error', 500);
}
