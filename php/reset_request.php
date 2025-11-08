<?php
// php/reset_request.php — Sol·licitar restabliment de contrasenya (resposta neutra + throttle)
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
// require_once __DIR__ . '/db.php'; // ❌ duplicat: db() ja ve per preload
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = db();

// PHPMailer al nivell de fitxer (no dins d'un bloc!)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* ---------- Inputs ---------- */
$emailRaw = (string)($_POST['email'] ?? '');
$email    = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
$email    = $email ? mb_strtolower(trim($email), 'UTF-8') : '';

/* ---------- Antiabús (3 intents/hora per email+IP) ---------- */
$key = hash('sha256', ($email ?: 'noemail') . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
$now = time();
$_SESSION['reset_throttle'] = $_SESSION['reset_throttle'] ?? [];
$rec = $_SESSION['reset_throttle'][$key] ?? ['cnt' => 0, 'window' => 0];

if ($rec['window'] < $now) {
  $rec = ['cnt'=>0, 'window'=>$now + 3600]; // finestra nova d'1 hora
}

$neutralFlash = function(string $type='info') use ($messages) {
  // Text neutre: no revela si el correu existeix
  $msg = $messages['success']['mail_sent']
      ?? "Si l’adreça existeix, t’hem enviat un correu amb instruccions per restablir la contrasenya.";
  $_SESSION['login_modal'] = ['open'=>true, 'flash'=>['type'=>$type, 'msg'=>$msg]];
  ks_set_login_modal_cookie($_SESSION['login_modal']);
};

$doneAndRedirect = function(array $qs=['modal'=>'login','success'=>'mail_sent']) {
  redirect_to('index.php', $qs);
};

$aud = function(string $status, array $meta = []) use ($pdo, $email) {
  // No loguem el correu en clar: només hash i idioma/flags
  $metaBase = [
    'email_hash' => $email ? hash('sha256', $email) : null,
    'lang'       => $_SESSION['lang'] ?? null,
  ];
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'password_reset_request',
      null, null,
      'account',
      $metaBase + $meta,
      $status,
      null
    );
  } catch (Throwable $e) {
    error_log('audit password_reset_request failed: ' . $e->getMessage());
  }
};

if ($rec['cnt'] >= 3) {
  $neutralFlash('info');
  $aud('error', ['reason' => 'throttled', 'count' => (int)$rec['cnt']]);
  $doneAndRedirect();
}

/* ---------- Flux principal: sempre resposta neutra ---------- */
try {
  if ($email === '') {
    $rec['cnt']++;
    $_SESSION['reset_throttle'][$key] = $rec;
    $neutralFlash('info');
    $aud('error', ['reason' => 'invalid_email', 'count' => (int)$rec['cnt']]);
    $doneAndRedirect();
  }

  // Busca usuari (no revelem si existeix)
  $st = $pdo->prepare("
    SELECT ID_Usuari, COALESCE(Idioma,'ca') AS Idioma
      FROM Usuaris
     WHERE Email_Usuari = :e
     LIMIT 1
  ");
  $st->execute([':e'=>$email]);
  $user = $st->fetch(PDO::FETCH_ASSOC);

  // Genera token i guarda'l NOMÉS si existeix l’usuari
  $mailSent = false;
  $resetLink = '';
  if ($user) {
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    // Caducitat a UTC des de SQL per evitar desquadraments de TZ
    $upd = $pdo->prepare("
      UPDATE Usuaris
         SET Password_Reset_Token_Hash = :th,
             Password_Reset_Expira     = UTC_TIMESTAMP() + INTERVAL 1 HOUR
       WHERE ID_Usuari = :id
       LIMIT 1
    ");
    $upd->execute([':th'=>$tokenHash, ':id'=>(int)$user['ID_Usuari']]);

    $resetLink = url('php/reset_password.php?token=' . urlencode($rawToken));
  }

  // ------- Selecció d'idioma per al correu (i18n) -------
  $idiomaMail = 'ca';
  if ($user && !empty($user['Idioma'])) {
    $idiomaMail = strtolower((string)$user['Idioma']);
  } elseif (!empty($_SESSION['lang'])) {
    $idiomaMail = strtolower((string)$_SESSION['lang']);
  }
  if (!in_array($idiomaMail, ['ca','es','en'], true)) { $idiomaMail = 'ca'; }

  $L  = i18n_load($idiomaMail);
  $tr = function(string $k, string $fb = '') use ($L) { return isset($L[$k]) ? (string)$L[$k] : ($fb !== '' ? $fb : $k); };
  
  // Enviament email (neutre si no existeix; simplement no s’envia)
  if ($user) {
    try {
      $mail = new PHPMailer(true);
      $mail->isSMTP();
      $mail->CharSet  = 'UTF-8';
      $mail->Encoding = 'quoted-printable';
      $mail->isHTML(true);
      $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.mail.me.com';
      $mail->SMTPAuth   = true;
      $mail->Username   = $_ENV['SMTP_USER'] ?? '';
      $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);

      $from     = $_ENV['SMTP_FROM'] ?: ($_ENV['SMTP_USER'] ?? '');
      $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';
      $mail->setFrom($from, $fromName);
      if (!empty($_ENV['SMTP_USER'])) { $mail->Sender = $_ENV['SMTP_USER']; }
      $mail->addReplyTo($_ENV['SMTP_REPLYTO'] ?? $from, $fromName);
      $mail->addAddress($email);

      $subject   = $tr('email.reset_subject',    'Restabliment de contrasenya — Kinosonik Riders');
      $bodyIntro = $tr('email.reset_body_intro', 'Per restablir la contrasenya, fes clic en aquest enllaç (caduca en 1 hora):');

      $mail->Subject = $subject;
      $mail->Body    = "<p>{$bodyIntro}</p><p><a href=\"{$resetLink}\">{$resetLink}</a></p>";

      try {
        $mailSent = $mail->send();
        if (!$mailSent) {
          @file_put_contents('/tmp/riders_mail_fail.log', date('c')." SEND FALSE (reset): ".$mail->ErrorInfo."\n", FILE_APPEND);
        }
      } catch (Throwable $e) {
        @file_put_contents('/tmp/riders_mail_fail.log', date('c')." EX (reset): ".$e->getMessage()."\n", FILE_APPEND);
      }

    } catch (Throwable $e) {
      error_log('Mailer error (reset): ' . $e->getMessage());
      // seguim flux neutre igualment
    }
  }

  // OK neutre
  $rec['cnt']++;
  $_SESSION['reset_throttle'][$key] = $rec;
  $neutralFlash('success');
  $aud('success', ['mail_sent' => (bool)$mailSent, 'user_found' => (bool)$user, 'count' => (int)$rec['cnt']]);
  $doneAndRedirect();

} catch (Throwable $e) {
  error_log('reset_request error: ' . $e->getMessage());
  $neutralFlash('info');
  $aud('error', ['exception' => true]);
  $doneAndRedirect();
}