<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php'; // ✉️ afegit per enviament immediat

$pdo = db();
error_log('[debug-db] Database in use: ' . ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: 'NULL'));


if (!is_post()) { http_response_code(405); exit; }

// CSRF check
$postCsrf = (string)($_POST['csrf'] ?? '');
$sessCsrf = (string)($_SESSION['csrf'] ?? '');
if ($postCsrf === '' || $sessCsrf === '' || !hash_equals($sessCsrf, $postCsrf)) {
  $_SESSION['login_modal'] = [
    'open'  => true,
    'flash' => ['type' => 'danger', 'msg' => ($messages['error']['csrf'] ?? 'Sessió caducada.')]
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
  $u = mb_substr($user, 0, 1, 'UTF-8');
  $stars = min(5, mb_strlen($user, 'UTF-8') - 1);
  return $u . str_repeat('*', $stars) . '@' . $dom;
}

function real_client_ip(): string {
  $trustedProxies = ['127.0.0.1', '::1'];
  $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
  if ($forwardedFor !== '' && in_array($remoteAddr, $trustedProxies, true)) {
    $ips = array_map('trim', explode(',', $forwardedFor));
    return $ips[0] ?? $remoteAddr;
  }
  return $remoteAddr;
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

$emailPost = trim((string)($_POST['email'] ?? ''));
$email     = $emailPost !== '' ? mb_strtolower($emailPost, 'UTF-8') : '';

// Honeypot anti-bot
$honeypot = trim((string)($_POST['website'] ?? ''));
if ($honeypot !== '') {
    sleep(3);
    error_log('[register] Bot detected: ' . real_client_ip());
    redirect_to('index.php', ['modal'=>'login','error'=>'invalid_request']);
}

$politicaVersio = (string)($_POST['politica_versio'] ?? '');
$consentAt      = gmdate('Y-m-d H:i:s');
$consentIP      = real_client_ip();
$idioma         = normalize_lang((string)($_SESSION['lang'] ?? 'ca'));

$returnRaw  = (string)($_POST['return'] ?? '');
$returnUrl  = sanitize_return_url($returnRaw);
$withReturn = fn(array $p) => ($returnUrl !== '' ? $p + ['return' => $returnUrl] : $p);

/* Validacions */
$tipusAllow = ['tecnic','sala','productor'];

if ($nom==='' || $cognoms==='' || $telefon==='' || $email==='' || $p1==='' || $p2==='' || $tipus==='') {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'missing_fields']));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'invalid_email']));
}

// Emails desechables
$disposableDomains = ['tempmail.com','guerrillamail.com','10minutemail.com','mailinator.com','throwaway.email','yopmail.com','maildrop.cc','temp-mail.org','getnada.com'];
$emailDomain = strtolower(explode('@', $email)[1] ?? '');
if (in_array($emailDomain, $disposableDomains, true)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'disposable_email']));
}

if ($tipus === 'admin' || !in_array($tipus, $tipusAllow, true)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'missing_fields']));
}

if ($p1 !== $p2) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'password_mismatch']));
}

if (!preg_match('/^(?=.{8,})(?=.*[A-Za-z])(?=.*\d).+$/', $p1)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'weak_password']));
}

if (!str_starts_with($telefon, '+')) {
  if (preg_match('/^(34|33|44|1)\d/', $telefon)) {
    $telefon = '+' . $telefon;
  } else {
    $telefon = '+34' . ltrim($telefon, '0');
  }
}

if (!preg_match('/^\+[1-9][0-9]{6,14}$/', $telefon)) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'invalid_phone']));
}

if ($politica !== 1) {
  redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'policy_required']));
}

/* Rate limiting per IP */
$ip = real_client_ip();
try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM Usuaris WHERE Politica_Consentit_IP=? AND Data_Alta_Usuari>DATE_SUB(NOW(),INTERVAL 1 HOUR)");
  $stmt->execute([$ip]);
  if ((int)$stmt->fetchColumn() >= 3) {
    error_log("[register] IP rate limit: $ip");
    redirect_to('index.php', $withReturn(['modal'=>'login','error'=>'ip_rate_limit']));
  }
} catch (PDOException $e) {
  error_log('[register] IP rate limit check failed: '.$e->getMessage());
}

/* Throttle */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS register_attempts (
      throttle_key VARCHAR(64) PRIMARY KEY,
      fail_count INT NOT NULL DEFAULT 0,
      blocked_until INT NOT NULL DEFAULT 0,
      last_attempt INT NOT NULL DEFAULT 0,
      INDEX idx_blocked (blocked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
} catch (PDOException $e) { error_log('[register] throttle table creation failed: '.$e->getMessage()); }

$throttleKey = hash('sha256', $email.'|'.$ip);
$now = time();

try {
  $stmt = $pdo->prepare("SELECT fail_count,blocked_until FROM register_attempts WHERE throttle_key=? AND blocked_until>=?");
  $stmt->execute([$throttleKey,$now]);
  $blocked = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($blocked) {
    error_log("[register] Throttled: $email from $ip");
    $_SESSION['login_modal']=['open'=>true,'flash'=>['type'=>'danger','msg'=>($messages['error']['too_many_attempts']??'Massa intents.')]];
    ks_set_login_modal_cookie($_SESSION['login_modal']);
    redirect_to('index.php',$withReturn(['modal'=>'login','error'=>'too_many_attempts']));
  }
} catch (PDOException $e) { error_log('[register] Throttle check failed: '.$e->getMessage()); }

$throttle_fail=function()use($pdo,$throttleKey,$now){try{
  $stmt=$pdo->prepare("
    INSERT INTO register_attempts (throttle_key,fail_count,last_attempt,blocked_until)
    VALUES (?,1,?,0)
    ON DUPLICATE KEY UPDATE
      fail_count=fail_count+1,
      last_attempt=VALUES(last_attempt),
      blocked_until=CASE WHEN fail_count+1>=5 THEN VALUES(last_attempt)+(15*60) ELSE blocked_until END
  ");$stmt->execute([$throttleKey,$now]);
}catch(PDOException $e){error_log('[register] Throttle fail update: '.$e->getMessage());}};
$throttle_ok=function()use($pdo,$throttleKey){try{$pdo->prepare("DELETE FROM register_attempts WHERE throttle_key=?")->execute([$throttleKey]);}catch(PDOException $e){error_log('[register] Throttle reset: '.$e->getMessage());}};

/* Password hash */
$hash = password_hash($p1, PASSWORD_DEFAULT);

/* Token verificació */
$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiry    = gmdate('Y-m-d H:i:s', time() + 86400);

try {
  $stmt = $pdo->prepare("
    INSERT INTO Usuaris
      (Nom_Usuari,Cognoms_Usuari,Telefon_Usuari,Email_Usuari,Password_Hash,
       Tipus_Usuari,Idioma,Email_Verificat,
       Email_Verify_Token_Hash,Email_Verify_Expira,
       Politica_Versio,Politica_Consentit_At,Politica_Consentit_IP,
       Data_Alta_Usuari)
    VALUES
      (:nom,:cognoms,:telefon,:email,:hash,
       :tipus,:idioma,0,
       :vhash,:vexp,
       :pversio,:p_at,:p_ip,
       UTC_TIMESTAMP())
  ");
  $stmt->execute([
    ':nom'=>$nom,':cognoms'=>$cognoms,':telefon'=>$telefon,':email'=>$email,':hash'=>$hash,
    ':tipus'=>$tipus,':idioma'=>$idioma,':vhash'=>$tokenHash,':vexp'=>$expiry,
    ':pversio'=>$politicaVersio,':p_at'=>$consentAt,':p_ip'=>$consentIP
  ]);

  $userId=(int)$pdo->lastInsertId();

  if($tipus==='sala'){ $pdo->prepare("UPDATE Usuaris SET Publica_Telefon=0 WHERE ID_Usuari=?")->execute([$userId]); }

  $parts=explode('@',(string)$email,2);
  $masked=mask_email((string)$email);
  audit_admin($pdo,$userId,false,'user_register_attempt',null,null,'account',[
      'email_masked'=>$masked,'email_domain'=>$parts[1]??'','tipus'=>$tipus,
      'policy_ver'=>$politicaVersio,'consent_ip'=>$consentIP
  ],'success',null);

  // Enllaç verificació
  if(function_exists('url')){
    $verifyLink=url('php/verify_email.php?token='.urlencode($rawToken));
  }else{
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    $host=$_SERVER['HTTP_HOST']??'localhost';
    $base=defined('BASE_PATH')?(string)BASE_PATH:'/';
    $verifyLink=rtrim("$scheme://$host$base",'/').'/php/verify_email.php?token='.urlencode($rawToken);
  }

  // ✉️ Enviament immediat
  $subject   = __('email.verify_subject') ?: "Verifica el teu correu – Kinosonik Riders";
  $bodyIntro = __('email.verify_body_intro') ?: "Per completar el registre, verifica el teu correu (caduca en 24h):";
  $safeNom   = h($nom);
  $mailBody  = "<p>Hola, {$safeNom},</p><p>{$bodyIntro}</p><p><a href=\"{$verifyLink}\">{$verifyLink}</a></p>";
  $sent = ks_send_mail($email,$subject,$mailBody,null,$nom);

  if(!$sent){ $_SESSION['mail_warning']=true; }

  error_log("[register] User $email ($ip) registered OK (mail immediate=".($sent?'yes':'no').")");

  $_SESSION['login_modal']=[
    'open'=>true,
    'flash'=>['type'=>'success','msg'=>($messages['success']['verify_sent']??"Check e-mail (24h max).")]
  ];
  ks_set_login_modal_cookie($_SESSION['login_modal']);

  $throttle_ok();
  redirect_to('index.php',$withReturn(['modal'=>'login','success'=>'verify_sent']));

}catch(PDOException $e){
  $parts=explode('@',(string)$email,2);
  $masked=mask_email((string)$email);
  $code=$e->getCode();
  $errKey=($code==='23000')?'email_in_use':'db_error';
  audit_admin($pdo,0,false,'user_register_attempt',null,null,'account',[
      'email_masked'=>$masked,'email_domain'=>$parts[1]??'','tipus'=>$tipus,'error_code'=>(string)$code
  ],'error',$errKey);
  if($code==='23000'){
    $_SESSION['login_modal']=['open'=>true,'flash'=>['type'=>'danger','msg'=>($messages['error']['email_in_use']??'Email ja registrat.')]];
    ks_set_login_modal_cookie($_SESSION['login_modal']);
    $throttle_fail();
    redirect_to('index.php',$withReturn(['modal'=>'login','error'=>'email_in_use']));
  }
  error_log('[register] Error: '.$e->getMessage());
  $_SESSION['login_modal']=['open'=>true,'flash'=>['type'=>'danger','msg'=>($messages['error']['db_error']??'Error de BD.')]];
  ks_set_login_modal_cookie($_SESSION['login_modal']);
  $throttle_fail();
  redirect_to('index.php',$withReturn(['modal'=>'login','error'=>'db_error']));
}
