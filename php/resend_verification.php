<?php
// php/resend_verification.php — Reenviar correu de verificació (resposta neutra + throttle)
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// require_once __DIR__ . '/db.php'; // ❌ Duplicat: db() ja ve via preload.php
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

$pdo = db();

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* ---------- Helpers ---------- */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mask_email(?string $email): string {
  if (!$email) return '';
  [$user, $dom] = array_pad(explode('@', $email, 2), 2, '');
  if ($user === '') return (string)$email;
  $u = mb_substr($user, 0, 2, 'UTF-8');
  return $u . str_repeat('*', max(0, mb_strlen($user, 'UTF-8') - 2)) . '@' . $dom;
}
function audit_neutral(PDO $pdo, array $meta, string $status='success', ?string $err=null): void {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),          // pot ser 0 (usuari no loguejat)
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'resend_verify_request',
      null,
      null,
      'account',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit resend_verify_request failed: ' . $e->getMessage());
  }
}

/* ---------- Inputs ---------- */
$emailRaw = (string)($_POST['email'] ?? '');
$email    = filter_var(trim($emailRaw), FILTER_VALIDATE_EMAIL);
$email    = $email ? mb_strtolower($email, 'UTF-8') : '';

$returnRaw  = (string)($_POST['return'] ?? '');
$returnUrl  = sanitize_return_url($returnRaw);
$withReturn = fn(array $p) => ($returnUrl !== '' ? $p + ['return' => $returnUrl] : $p);

/* ---------- Antiabús (throttle: 3 intents/hora per email+IP) ---------- */
$key = hash('sha256', ($email ?: 'noemail') . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
$now = time();
$_SESSION['resend_throttle'] = $_SESSION['resend_throttle'] ?? [];
$rec = $_SESSION['resend_throttle'][$key] ?? ['cnt' => 0, 'window' => 0];

if ($rec['window'] < $now) {
  $rec = ['cnt'=>0, 'window'=>$now + 3600]; // nova finestra d'1 hora
}

$neutralFlash = ($messages['success']['verify_sent'] ?? "Si l’adreça existeix i no està verificada, t’hem tornat a enviar el correu.");

if ($rec['cnt'] >= 3) {
  // Audit (throttle hit)
  audit_neutral($pdo, [
    'email_masked' => mask_email($email),
    'email_domain' => explode('@', (string)$email, 2)[1] ?? '',
    'throttled'    => true,
    'count'        => (int)$rec['cnt'],
  ], 'error', 'throttled');

  // Resposta neutra
  $_SESSION['login_modal'] = ['open'=>true,'flash'=>['type'=>'info','msg'=>$neutralFlash]];
  ks_set_login_modal_cookie($_SESSION['login_modal']);
  redirect_to('index.php', $withReturn(['modal' => 'login', 'success' => 'verify_sent']));
}

/* ---------- Flux principal (resposta neutra sempre) ---------- */
try {
  if ($email === '') {
    audit_neutral($pdo, [
      'email_masked' => '',
      'email_domain' => '',
      'throttled'    => false,
      'count'        => (int)$rec['cnt'],
      'reason'       => 'invalid_email',
    ], 'error', 'invalid_email');

    $_SESSION['login_modal'] = ['open'=>true,'flash'=>['type'=>'info','msg'=>$neutralFlash]];
    $rec['cnt']++;
    $_SESSION['resend_throttle'][$key] = $rec;
    ks_set_login_modal_cookie($_SESSION['login_modal']);
    redirect_to('index.php', $withReturn(['modal' => 'login', 'success' => 'verify_sent']));
  }

  // Cerca usuari
  $st = $pdo->prepare("
    SELECT ID_Usuari, Email_Verificat, COALESCE(Idioma,'ca') AS Idioma
      FROM Usuaris
     WHERE Email_Usuari = :email
     LIMIT 1
  ");
  $st->execute([':email' => $email]);
  $user = $st->fetch(PDO::FETCH_ASSOC);

  $emailMasked = mask_email($email);
  $emailDomain = explode('@', (string)$email, 2)[1] ?? '';

  // Si no existeix o ja verificat, resposta neutra
  if (!$user || (int)$user['Email_Verificat'] === 1) {
    audit_neutral($pdo, [
      'email_masked' => $emailMasked,
      'email_domain' => $emailDomain,
      'found'        => (bool)$user,
      'already_ver'  => $user ? ((int)$user['Email_Verificat'] === 1) : null,
      'throttled'    => false,
      'count'        => (int)$rec['cnt'],
    ], 'success', null);

    $_SESSION['login_modal'] = ['open'=>true,'flash'=>['type'=>'info','msg'=>$neutralFlash]];
    $rec['cnt']++;
    $_SESSION['resend_throttle'][$key] = $rec;
    ks_set_login_modal_cookie($_SESSION['login_modal']);
    redirect_to('index.php', $withReturn(['modal' => 'login', 'success' => 'verify_sent']));
  }

  // Genera nou token
  $rawToken  = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $rawToken);

  // Desa hash + caducitat en **UTC** directament a la BD
  $upd = $pdo->prepare("
    UPDATE Usuaris
       SET Email_Verify_Token_Hash = :th,
           Email_Verify_Expira     = (UTC_TIMESTAMP() + INTERVAL 1 DAY)
     WHERE ID_Usuari = :id
     LIMIT 1
  ");
  $upd->execute([
    ':th' => $tokenHash,
    ':id' => (int)$user['ID_Usuari'],
  ]);

  // Enllaç de verificació
  $verifyLink = url('php/verify_email.php?token=' . urlencode($rawToken));

  // Envia mail (PHPMailer via autoload). L'autoload ja pot estar carregat globalment,
  // però fem require per si aquest script s'executa aïllat.
  try {
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'quoted-printable';
    $mail->isHTML(true);
    $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.mail.me.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['SMTP_USER'] ?? '';
    $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);

    $from     = $_ENV['SMTP_FROM'] ?: ($_ENV['SMTP_USER'] ?? '');
    $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';
    $mail->setFrom($from, $fromName);
    if (!empty($_ENV['SMTP_USER'])) { $mail->Sender = $_ENV['SMTP_USER']; }
    $mail->addReplyTo($_ENV['SMTP_REPLYTO'] ?? $from, $fromName);
    $mail->addAddress($email);

    // idioma de l’usuari a efectes del correu
    $idiomaMail = strtolower((string)($user['Idioma'] ?? 'ca'));
    if (!in_array($idiomaMail, ['ca','es','en'], true)) { $idiomaMail = 'ca'; }
    $L  = i18n_load($idiomaMail);
    $tr = function(string $k, string $fb = '') use ($L) { return isset($L[$k]) ? (string)$L[$k] : ($fb !== '' ? $fb : $k); };

    $subject   = $tr('email.verify_subject', "Verifica el teu correu – Kinosonik Riders");
    $bodyIntro = $tr('email.verify_body_intro', "Per completar el registre, verifica el teu correu fent clic a l’enllaç (caduca en 24 hores):");

    $mail->Subject = $subject;
    $mail->Body    = "<p>{$bodyIntro}</p><p><a href=\"{$verifyLink}\">{$verifyLink}</a></p>";

    try {
      if (!$mail->send()) {
        @file_put_contents('/tmp/riders_mail_fail.log', date('c')." SEND FALSE (resend): ".$mail->ErrorInfo."\n", FILE_APPEND);
      }
    } catch (Throwable $e) {
      @file_put_contents('/tmp/riders_mail_fail.log', date('c')." EX (resend): ".$e->getMessage()."\n", FILE_APPEND);
    }
  } catch (Throwable $e) {
    error_log('Mailer error (resend verify): ' . $e->getMessage());
    // continuem amb resposta neutra igualment
  }

  // Audit èxit (neutral outward)
  audit_neutral($pdo, [
    'email_masked' => $emailMasked,
    'email_domain' => $emailDomain,
    'found'        => true,
    'already_ver'  => false,
    'throttled'    => false,
    'count'        => (int)$rec['cnt'],
    'user_id'      => (int)$user['ID_Usuari'],
    'token_set'    => true,
  ], 'success', null);

  // OK neutre
  $_SESSION['login_modal'] = [
    'open'  => true,
    'flash' => ['type' => 'success', 'msg' => ($messages['success']['verify_sent'] ?? "T’hem enviat un correu per verificar el teu compte. Revisa la bústia.")],
  ];
  $rec['cnt']++;
  $_SESSION['resend_throttle'][$key] = $rec;
  ks_set_login_modal_cookie($_SESSION['login_modal']);
  redirect_to('index.php', $withReturn(['modal' => 'login', 'success' => 'verify_sent']));

} catch (Throwable $e) {
  error_log('resend_verification error: ' . $e->getMessage());

  audit_neutral($pdo, [
    'email_masked' => mask_email($email),
    'email_domain' => explode('@', (string)$email, 2)[1] ?? '',
    'exception'    => true,
  ], 'error', 'server_error');

  // Resposta neutra també en error
  $_SESSION['login_modal'] = [
    'open'  => true,
    'flash' => ['type' => 'info', 'msg' => ($messages['success']['verify_sent'] ?? "Si l’adreça existeix i no està verificada, t’hem tornat a enviar el correu.")],
  ];
  ks_set_login_modal_cookie($_SESSION['login_modal']);
  redirect_to('index.php', $withReturn(['modal' => 'login', 'success' => 'verify_sent']));
}