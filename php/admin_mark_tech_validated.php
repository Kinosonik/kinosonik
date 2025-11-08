<?php
// php/admin_mark_tech_validated.php — Admin marca un rider com validat tècnicament i envia correu a l'usuari
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';     // per t()/__()
require_once __DIR__ . '/messages.php'; // opcional
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = db();

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

// Helper i18n amb fallback
function trf(string $key, string $fallback): string {
  $v = __($key);
  return (is_string($v) && $v !== '' && $v !== $key) ? $v : $fallback;
}

function json_out(array $a, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

// Helper URL origen (per enllaç a la fitxa)
if (!function_exists('origin_url')) {
  function origin_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
  }
}

/* ── Validacions ────────────────────────────────────────── */
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { json_out(['ok'=>false, 'error'=>'login_required'], 401); }

$st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$st->execute([$userId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || strcasecmp((string)$row['Tipus_Usuari'], 'admin') !== 0) {
  json_out(['ok'=>false, 'error'=>'forbidden'], 403);
}

$riderUID = trim((string)($_POST['rider_uid'] ?? ''));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $riderUID)) {
  json_out(['ok'=>false, 'error'=>'bad_uid'], 400);
}

/* ── Update i recuperació dades per correu ───────────────── */
try {
  $pdo->beginTransaction();

  // Desmarca pendent validació tècnica
  $stU = $pdo->prepare("
    UPDATE Riders
    SET Validacio_Manual_Solicitada = 0,
       Validacio_Manual_Data = UTC_TIMESTAMP()
    WHERE Rider_UID = :uid
    LIMIT 1
  ");
  $stU->execute([':uid' => $riderUID]);
  if ($stU->rowCount() < 1) {
    $pdo->rollBack();
    json_out(['ok'=>false, 'error'=>'not_found_or_noop'], 404);
  }

  // Email + idioma + texts rider
  $stQ = $pdo->prepare("
    SELECT u.Email_Usuari AS email, COALESCE(NULLIF(u.Idioma,''),'ca') AS lang,
           r.ID_Rider, r.Descripcio, r.Nom_Arxiu
      FROM Riders r
      JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
     WHERE r.Rider_UID = :uid
     LIMIT 1
  ");
  $stQ->execute([':uid' => $riderUID]);
  $info = $stQ->fetch(PDO::FETCH_ASSOC);

  $pdo->commit();

  // Auditoria
  audit_admin(
    $pdo,
    (int)$userId,
    true,
    'tech_validate_ok',
    isset($info['ID_Rider']) ? (int)$info['ID_Rider'] : null,
    (string)$riderUID,
    'admin_riders',
    ['ts_utc' => gmdate('c')], // opcional: marca de temps en UTC per traçabilitat
    'success',
    null
  );

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('admin_mark_tech_validated DB: ' . $e->getMessage());
  try {
    audit_admin(
      $pdo,
      (int)($userId ?? 0),
      true,
      'tech_validate_ok',
      null,
      (string)($riderUID ?? ''),
      'admin_riders',
      ['ts_utc' => gmdate('c')],
      'error',
      substr($e->getMessage(), 0, 250)
    );
  } catch (Throwable $ae) {
    error_log('audit tech_validate_ok error failed: ' . $ae->getMessage());
  }
  json_out(['ok'=>false, 'error'=>'db_error'], 500);
}

if (!$info) {
  // No bloquegem l’OK si no trobem l’email (cas extrem)
  json_out(['ok'=>true, 'warn'=>'no_user_info']);
}

/* ── Missatge localitzat ────────────────────────────────── */
$email = (string)($info['email'] ?? '');
$lang  = strtolower((string)($info['lang'] ?? 'ca'));
$desc  = trim((string)($info['Descripcio'] ?? ''));
$nom   = trim((string)($info['Nom_Arxiu'] ?? ''));
$display = $desc !== '' ? $desc : ($nom !== '' ? $nom : $riderUID);

$absBase = defined('BASE_URL') ? rtrim(BASE_URL, '/') : origin_url();
$viewUrl = $absBase . rtrim((string)BASE_PATH, '/') . '/visualitza.php?ref=' . rawurlencode($riderUID);

$subjects = [
  'ca' => trf('email.tech_validated.subject', 'Validació tècnica completada'),
  'es' => trf('email.tech_validated.subject', 'Validación técnica completada'),
  'en' => trf('email.tech_validated.subject', 'Technical validation completed'),
];

$bodies_html = [
  'ca' => trf('email.tech_validated.html',
    "<p>Hola,</p>
<p>El teu rider <strong>%s</strong> ha estat marcat com <strong>validat tècnicament</strong> per l’equip.</p>
<p>Pots revisar la fitxa aquí:</p>
<p><a href=\"%s\">Obrir fitxa del rider</a></p>
<p>Gràcies!</p>"
  ),
  'es' => trf('email.tech_validated.html',
    "<p>Hola,</p>
<p>Tu rider <strong>%s</strong> ha sido marcado como <strong>validado técnicamente</strong> por el equipo.</p>
<p>Puedes revisar la ficha aquí:</p>
<p><a href=\"%s\">Abrir ficha del rider</a></p>
<p>¡Gracias!</p>"
  ),
  'en' => trf('email.tech_validated.html',
    "<p>Hello,</p>
<p>Your rider <strong>%s</strong> has been marked as <strong>technically validated</strong> by our team.</p>
<p>You can review the rider card here:</p>
<p><a href=\"%s\">Open rider card</a></p>
<p>Thanks!</p>"
  ),
];

$bodies_text = [
  'ca' => trf('email.tech_validated.text',
    "Hola,\n\nEl teu rider \"%s\" ha estat marcat com validat tècnicament.\n\nFitxa del rider:\n%s\n\nGràcies!"
  ),
  'es' => trf('email.tech_validated.text',
    "Hola,\n\nTu rider \"%s\" ha sido marcado como validado técnicamente.\n\nFicha del rider:\n%s\n\n¡Gracias!"
  ),
  'en' => trf('email.tech_validated.text',
    "Hello,\n\nYour rider \"%s\" has been marked as technically validated.\n\nRider card:\n%s\n\nThanks!"
  ),
];

if (!isset($subjects[$lang])) { $lang = 'ca'; }
$subject  = $subjects[$lang];
$htmlBody = sprintf($bodies_html[$lang],
  htmlspecialchars($display, ENT_QUOTES, 'UTF-8'),
  htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8')
);
$textBody = sprintf($bodies_text[$lang], $display, $viewUrl);

/* ── Enviament correu ──────────────────────────────────── */
if ($email !== '') {
  $mail = new PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'quoted-printable';
    $mail->isHTML(true);
    $mail->Host       = $_ENV['SMTP_HOST']      ?? 'smtp.mail.me.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['SMTP_USER']      ?? '';
    $mail->Password   = $_ENV['SMTP_PASS']      ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);

    $from     = $_ENV['SMTP_FROM'] ?: ($_ENV['SMTP_USER'] ?? '');
    $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';
    $mail->setFrom($from, $fromName);
    if (!empty($_ENV['SMTP_USER'])) { $mail->Sender = $_ENV['SMTP_USER']; }
    $mail->addReplyTo($_ENV['SMTP_REPLYTO'] ?? $from, $fromName);
    $mail->addAddress($email);

    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();
  } catch (Exception $e) {
    error_log('Mailer error (tech_validated): ' . $mail->ErrorInfo);
  }
} else {
  error_log("admin_mark_tech_validated: usuari sense email per rider $riderUID");
}

json_out(['ok'=>true]);