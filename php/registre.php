<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';

// PDO
$pdo = db();

// Mètode obligat
if (!is_post()) { http_response_code(405); exit; }

// CSRF tolerant (redirigeix amb missatge, evita pàgina en blanc)
$postCsrf = (string)($_POST['csrf'] ?? '');
$sessCsrf = (string)($_SESSION['csrf'] ?? '');
if ($postCsrf === '' || $sessCsrf === '' || !hash_equals($sessCsrf, $postCsrf)) {
  $_SESSION['login_modal'] = [
    'open'  => true,
    'flash' => ['type' => 'danger', 'msg' => ($messages['error']['csrf'] ?? 'Sessió caducada. Torna-ho a provar.')]
  ];
  ks_set_login_modal_cookie($_SESSION['login_modal']);
  redirect_to('index.php', ['modal'=>'login','error'=>'csrf']);
}

/* Helpers */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function mask_email(?string $email): string {
  if (!$email) return '';
  [$user, $dom] = array_pad(explode('@', $email, 2), 2, '');
  if ($user === '') return (string)$email;
  $u = mb_substr($user, 0, 2, 'UTF-8');
  return $u . str_repeat('*', max(0, mb_strlen($user, 'UTF-8') - 2)) . '@' . $dom;
}
function real_client_ip(): string {
  $h = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($h !== '') { $parts = array_map('trim', explode(',', $h)); if ($parts) return $parts[0]; }
  return $_SERVER['REMOTE_ADDR'] ?? '';
}

/* Inputs */
$nom      = trim((string)($_POST['nom'] ?? ''));
$cognoms  = trim((string)($_POST['cognoms'] ?? ''));
$telefon  = trim((string)($_POST['telefon'] ?? ''));
$tipusRaw = (string)($_POST['tipus_usuari'] ?? '');
$tipus    = strtolower($tipusRaw);
$p1       = (string)($_POST['password']  ?? '');
$p2       = (string)($_POST['password2'] ?? '');
$politica = isset($_POST['politica']) ? 1 : 0;

// Email des de $_POST i validació amb filter_var
$emailPost = trim((string)($_POST['email'] ?? ''));
$email     = $emailPost !== '' ? mb_strtolower($emailPost, 'UTF-8') : '';

// Metadades
$politicaVersio = (string)($_POST['politica_versio'] ?? '');
$consentAt      = gmdate('Y-m-d H:i:s'); // UTC
$consentIP      = real_client_ip();
$idioma         = normalize_lang((string)($_SESSION['lang'] ?? 'ca'));

/* Return URL (opcional) */
$returnRaw  = (string)($_POST['return'] ?? '');
$returnUrl  = sanitize_return_url($returnRaw); // internament ja hauria de fer validacions
$withReturn = fn(array $p) => ($returnUrl !== '' ? $p + ['return' => $returnUrl] : $p);

/* Validacions */
$tipusAllow = ['tecnic','sala','productor'];

// Camps obligatoris
if ($nom==='' || $cognoms==='' || $telefon==='' || $email==='' || $p1==='' || $p2==='' || $tipus==='') {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'missing_fields']));
}
// Email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'invalid_email']));
}
// Tipus permès (i mai admin per via pública)
if ($tipus === 'admin' || !in_array($tipus, $tipusAllow, true)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'missing_fields']));
}
// Passwords
if ($p1 !== $p2) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'password_mismatch']));
}
if (!preg_match('/^(?=.{8,})(?=.*[A-Za-z])(?=.*\d).+$/', $p1)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'weak_password']));
}
// Telèfon E.164
if (!preg_match('/^\+[1-9][0-9]{6,14}$/', $telefon)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'invalid_phone']));
}
// Política
if ($politica !== 1) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'policy_required']));
}

/* Throttle registre */
$key = hash('sha256', (string)$email . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
$now = time();
$_SESSION['throttle'] = $_SESSION['throttle'] ?? [];
$rec = $_SESSION['throttle'][$key] ?? ['fail' => 0, 'until' => 0];

if ($rec['until'] > $now) {
  $_SESSION['login_modal'] = [
    'open' => true,
    'flash' => ['type' => 'danger', 'msg' => ($messages['error']['too_many_attempts'] ?? 'Massa intents. Torna més tard.')]
  ];
  ks_set_login_modal_cookie($_SESSION['login_modal']);
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'too_many_attempts']));
}
$throttle_fail = function() use (&$rec, $key) {
  $rec['fail'] = ($rec['fail'] ?? 0) + 1;
  if ($rec['fail'] >= 5) { $rec['until'] = time() + 15*60; $rec['fail'] = 0; }
  $_SESSION['throttle'][$key] = $rec;
};
$throttle_ok = function() use ($key) {
  if (isset($_SESSION['throttle'][$key])) { unset($_SESSION['throttle'][$key]); }
};

/* Hash password */
$hash = defined('PASSWORD_ARGON2ID') ? password_hash($p1, PASSWORD_ARGON2ID) : password_hash($p1, PASSWORD_DEFAULT);

/* Token verificació (24h) */
$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiry    = gmdate('Y-m-d H:i:s', time() + 86400); // UTC

try {
  $stmt = $pdo->prepare("
    INSERT INTO Usuaris
      (Nom_Usuari, Cognoms_Usuari, Telefon_Usuari, Email_Usuari, Password_Hash,
       Tipus_Usuari, Idioma, Email_Verificat,
       Email_Verify_Token_Hash, Email_Verify_Expira,
       Politica_Versio, Politica_Consentit_At, Politica_Consentit_IP,
       Data_Alta_Usuari)
    VALUES
      (:nom, :cognoms, :telefon, :email, :hash,
       :tipus, :idioma, 0,
       :vhash, :vexp,
       :pversio, :p_at, :p_ip,
       UTC_TIMESTAMP())
  ");
  $stmt->execute([
    ':nom'     => $nom,
    ':cognoms' => $cognoms,
    ':telefon' => $telefon,
    ':email'   => $email,
    ':hash'    => $hash,
    ':tipus'   => $tipus,
    ':idioma'  => $idioma,
    ':vhash'   => $tokenHash,
    ':vexp'    => $expiry,
    ':pversio' => $politicaVersio,
    ':p_at'    => $consentAt,
    ':p_ip'    => $consentIP,
  ]);

  $userId = (int)$pdo->lastInsertId();

  // Política per rol 'sala': no publicar telèfon per defecte
  try {
    if ($tipus === 'sala') {
      $pdo->prepare("UPDATE Usuaris SET Publica_Telefon = 0 WHERE ID_Usuari = ?")->execute([$userId]);
    }
  } catch (Throwable $e) { /* silent */ }

  // AUDIT registre OK (email emmascarat)
  try {
    $parts  = explode('@', (string)$email, 2);
    $masked = mask_email((string)$email);
    audit_admin(
      $pdo,
      $userId,
      false,
      'user_register_attempt',
      null,
      null,
      'account',
      [
        'email_masked' => $masked,
        'email_domain' => $parts[1] ?? '',
        'tipus'        => $tipus,
        'policy_ver'   => $politicaVersio,
        'consent_ip'   => $consentIP,
      ],
      'success',
      null
    );
  } catch (Throwable $e) { /* silent */ }

  // Enllaç de verificació
  if (function_exists('url')) {
    $verifyLink = url('php/verify_email.php?token=' . urlencode($rawToken));
  } else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = defined('BASE_PATH') ? (string)BASE_PATH : '/';
    $verifyLink = rtrim("$scheme://$host$base", '/') . '/php/verify_email.php?token=' . urlencode($rawToken);
  }

  // Mail (no bloquejant)
  try {
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
      $va = __DIR__ . '/../vendor/autoload.php';
      if (is_file($va)) { require_once $va; }
    }
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
      $mail->isSMTP();
      $mail->CharSet  = 'UTF-8';
      $mail->Encoding = 'quoted-printable';
      $mail->isHTML(true);
      $mail->Host       = $_ENV['SMTP_HOST']      ?? 'smtp.mail.me.com';
      $mail->SMTPAuth   = true;
      $mail->Username   = $_ENV['SMTP_USER']      ?? '';
      $mail->Password   = $_ENV['SMTP_PASS']      ?? '';
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);

      $from     = $_ENV['SMTP_FROM'] ?: ($_ENV['SMTP_USER'] ?? '');
      $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Kinosonik Riders';
      $mail->setFrom($from, $fromName);
      if (!empty($_ENV['SMTP_USER'])) { $mail->Sender = $_ENV['SMTP_USER']; }
      $mail->addReplyTo($_ENV['SMTP_REPLYTO'] ?? $from, $fromName);
      $mail->addAddress((string)$email);

      $subject   = __('email.verify_subject')    ?: "Verifica el teu correu – Kinosonik Riders";
      $bodyIntro = __('email.verify_body_intro') ?: "Per completar el registre, verifica el teu correu fent clic a l’enllaç (caduca en 24 hores):";
      $safeNom   = h($nom);

      $mail->Subject = $subject;
      $mail->Body    = "<p>Hola, {$safeNom},</p><p>{$bodyIntro}</p><p><a href=\"{$verifyLink}\">{$verifyLink}</a></p>";

      try { $mail->send(); }
      catch (Throwable $e) {
        @file_put_contents('/tmp/riders_mail_fail.log', date('c')." EX: ".$e->getMessage()."\n", FILE_APPEND);
      }
    } else {
      @file_put_contents('/tmp/riders_mail_fail.log', date('c')." PHPMailer no disponible\n", FILE_APPEND);
    }
  } catch (Throwable $e) { /* silent */ }

  // Flash d’èxit + modal login obert
  $_SESSION['login_modal'] = [
    'open'  => true,
    'flash' => ['type' => 'success', 'msg' => ($messages['success']['verify_sent'] ?? "T'hem enviat un correu per verificar el teu compte. Revisa la bústia.")],
  ];
  ks_set_login_modal_cookie($_SESSION['login_modal']);

  $throttle_ok();
  redirect_to('index.php', $withReturn(['modal'=>'login','success'=>'verify_sent']));

} catch (PDOException $e) {
  $parts  = explode('@', (string)$email, 2);
  $masked = mask_email((string)$email);
  $code   = $e->getCode();
  $errKey = ($code === '23000') ? 'email_in_use' : 'db_error';

  try {
    audit_admin(
      $pdo,
      0,
      false,
      'user_register_attempt',
      null,
      null,
      'account',
      [
        'email_masked' => $masked,
        'email_domain' => $parts[1] ?? '',
        'tipus'        => $tipus,
        'error_code'   => (string)$code,
      ],
      'error',
      $errKey
    );
  } catch (Throwable $e2) { /* silent */ }

  if ($code === '23000') {
    $_SESSION['login_modal'] = [
      'open'  => true,
      'flash' => ['type' => 'danger', 'msg' => ($messages['error']['email_in_use'] ?? 'Ja existeix un compte amb aquest correu.')],
    ];
    ks_set_login_modal_cookie($_SESSION['login_modal']);
    $throttle_fail();
    redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'email_in_use']));
  }

  @error_log('Error registre: ' . $e->getMessage());
  $_SESSION['login_modal'] = [
    'open'  => true,
    'flash' => ['type' => 'danger', 'msg' => ($messages['error']['db_error'] ?? 'Error de base de dades.')],
  ];
  ks_set_login_modal_cookie($_SESSION['login_modal']);
  $throttle_fail();
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'db_error']));
}