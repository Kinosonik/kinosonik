<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';
// require_once __DIR__ . '/db.php'; // ❌ DUPLICAT: ja ve per preload.php
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php'; // ✅ auditar

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

// ✅ inicialitza PDO un cop i prou
$pdo = db();

/* ---------------- Helpers específics ---------------- */
if (!function_exists('push_login_modal_flash')) {
  function push_login_modal_flash(string $type, string $msg): void {
    $_SESSION['login_modal'] = ['open' => true, 'flash' => ['type' => $type, 'msg' => $msg]];
    ks_set_login_modal_cookie($_SESSION['login_modal']);
  }
}

if (!function_exists('redirect_index')) {
  function redirect_index(array $qs = []): never {
    redirect_to('index.php', $qs); // respecta BASE_PATH
  }
}

/* ---------------- Inputs ---------------- */
$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$email = $email ? mb_strtolower(trim($email), 'UTF-8') : $email;
$password = (string)($_POST['contrasenya'] ?? '');

/* ---------------- Normalitza return (ÚNICA via) ---------------- */
$returnRaw  = (string)($_POST['return'] ?? '');
$returnUrl  = sanitize_return_url($returnRaw);
$withReturn = fn(array $p) => ($returnUrl !== '' ? $p + ['return' => $returnUrl] : $p);

/* -------- Throttle: setup i bloqueig temporal -------- */
$who = $email ? $email : 'anon';
$key = hash('sha256', $who . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
$now = time();
$_SESSION['throttle'] = $_SESSION['throttle'] ?? [];
$rec = $_SESSION['throttle'][$key] ?? ['fail' => 0, 'until' => 0];

if ($rec['until'] > $now) {
  // 🔎 AUDIT: massa intents
  try {
    audit_admin(
      $pdo,
      0,
      false,
      'login_error',
      null,
      null,
      'auth',
      ['email' => (string)($email ?? ''), 'reason' => 'too_many_attempts'],
      'error',
      'throttled'
    );
  } catch (Throwable $e) { error_log('audit login_error(throttle) failed: '.$e->getMessage()); }

  push_login_modal_flash('danger', $messages['error']['too_many_attempts'] ?? 'Massa intents. Torna més tard.');
  redirect_index($withReturn(['modal' => 'login', 'error' => 'too_many_attempts']));
}

$throttle_fail = function() use (&$rec, $key) {
  $rec['fail'] = ($rec['fail'] ?? 0) + 1;
  if ($rec['fail'] >= 5) { $rec['until'] = time() + 15*60; $rec['fail'] = 0; }
  $_SESSION['throttle'][$key] = $rec;
};
$throttle_ok = function() use ($key) {
  if (isset($_SESSION['throttle'][$key])) { unset($_SESSION['throttle'][$key]); }
};

if (!$email || $password === '') {
  // 🔎 AUDIT: camps buits
  try {
    audit_admin(
      $pdo,
      0,
      false,
      'login_error',
      null,
      null,
      'auth',
      ['email' => (string)($email ?? ''), 'reason' => 'missing_fields'],
      'error',
      'missing email/password'
    );
  } catch (Throwable $e) { error_log('audit login_error(missing) failed: '.$e->getMessage()); }

  $throttle_fail();
  push_login_modal_flash('danger', $messages['error']['missing_fields'] ?? 'Falten camps.');
  redirect_index($withReturn(['modal' => 'login', 'error' => 'missing_fields']));
}

/* ---------------- Procés de login ---------------- */
try {
  $stmt = $pdo->prepare("
    SELECT ID_Usuari, Nom_Usuari, Cognoms_Usuari, Email_Usuari, Password_Hash,
           Email_Verificat, Tipus_Usuari, COALESCE(Idioma,'ca') AS Idioma
      FROM Usuaris
     WHERE Email_Usuari = :email
     LIMIT 1
  ");
  $stmt->execute([':email' => $email]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    // 🔎 AUDIT: email no trobat
    try {
      audit_admin(
        $pdo,
        0,
        false,
        'login_error',
        null,
        null,
        'auth',
        ['email' => (string)$email, 'reason' => 'not_found'],
        'error',
        'user not found'
      );
    } catch (Throwable $e) { error_log('audit login_error(not_found) failed: '.$e->getMessage()); }

    $throttle_fail();
    push_login_modal_flash('danger', $messages['error']['email_not_found'] ?? 'Usuari no trobat.');
    redirect_index($withReturn(['modal' => 'login', 'error' => 'email_not_found']));
  }

  if ((int)$user['Email_Verificat'] !== 1) {
    // 🔎 AUDIT: email no verificat
    try {
      audit_admin(
        $pdo,
        (int)$user['ID_Usuari'],
        (strcasecmp((string)$user['Tipus_Usuari'], 'admin') === 0),
        'login_error',
        null,
        null,
        'auth',
        ['email' => (string)$email, 'reason' => 'email_not_verified'],
        'error',
        'email not verified'
      );
    } catch (Throwable $e) { error_log('audit login_error(unverified) failed: '.$e->getMessage()); }

    push_login_modal_flash('warning', $messages['error']['email_not_verified'] ?? 'Verifica el teu correu abans d’iniciar sessió.');
    redirect_index($withReturn(['modal'=>'login','error'=>'email_not_verified','email'=>(string)$email]));
  }

  if (!password_verify($password, (string)$user['Password_Hash'])) {
    // 🔎 AUDIT: contrasenya incorrecta
    try {
      audit_admin(
        $pdo,
        (int)$user['ID_Usuari'],
        (strcasecmp((string)$user['Tipus_Usuari'], 'admin') === 0),
        'login_error',
        null,
        null,
        'auth',
        ['email' => (string)$email, 'reason' => 'bad_password'],
        'error',
        'wrong password'
      );
    } catch (Throwable $e) { error_log('audit login_error(bad_password) failed: '.$e->getMessage()); }

    $throttle_fail();
    push_login_modal_flash('danger', $messages['error']['wrong_password'] ?? 'Contrasenya incorrecta.');
    redirect_index($withReturn(['modal' => 'login', 'error' => 'wrong_password']));
  }

  // LOGIN OK
  $throttle_ok();
  session_regenerate_id(true);
  $_SESSION['loggedin']      = true;
  $_SESSION['user_id']       = (int)$user['ID_Usuari'];
  $_SESSION['user_name']     = (string)$user['Nom_Usuari'];
  $_SESSION['user_surnames'] = (string)$user['Cognoms_Usuari'];
  $_SESSION['user_email']    = (string)$user['Email_Usuari'];
  $_SESSION['tipus_usuari']  = (string)$user['Tipus_Usuari'];
  // rol normalitzat per decisions de flux
  $role = strtolower((string)$user['Tipus_Usuari']);

  $idioma = strtolower((string)$user['Idioma']);
  $_SESSION['lang'] = in_array($idioma, ['ca','es','en'], true) ? $idioma : 'ca';
  if (function_exists('set_lang')) { set_lang($_SESSION['lang']); }

  /* ───────── MULTIROLE: bootstrap de rols (User_Roles) ───────── */
try {
  $role = strtolower((string)$user['Tipus_Usuari']); // rol base
  // Garanteix que el rol base figura a User_Roles (per compatibilitat)
  $ins = $pdo->prepare('INSERT IGNORE INTO User_Roles (user_id, role) VALUES (?, ?)');
  $ins->execute([(int)$user['ID_Usuari'], $role]);

  // Carrega tots els rols de l’usuari
  $st = $pdo->prepare('SELECT role FROM User_Roles WHERE user_id = ?');
  $st->execute([(int)$user['ID_Usuari']]);
  $_SESSION['roles_extra'] = array_values(
    array_unique(array_map('strtolower', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'role')))
  );
} catch (Throwable $e) {
  error_log('login: roles_extra bootstrap failed: '.$e->getMessage());
  $_SESSION['roles_extra'] = [$role]; // fallback: almenys el rol base
}

/* (Opcional) cache ràpida per navbar: té riders propis? */
try {
  $stCnt = $pdo->prepare('SELECT COUNT(*) FROM Riders WHERE ID_Usuari = ?');
  $stCnt->execute([(int)$user['ID_Usuari']]);
  $_SESSION['has_my_riders'] = ((int)$stCnt->fetchColumn() > 0) ? 1 : 0;
} catch (Throwable $e) {
  $_SESSION['has_my_riders'] = 0;
}


  $upd = $pdo->prepare("UPDATE Usuaris SET Ultim_Acces_Usuari = UTC_TIMESTAMP() WHERE ID_Usuari = :id");
  $upd->execute([":id" => (int)$user["ID_Usuari"]]);

  // 🔎 AUDIT: èxit de login
  try {
    audit_admin(
      $pdo,
      (int)$user['ID_Usuari'],
      (strcasecmp((string)$user['Tipus_Usuari'], 'admin') === 0),
      'login_success',
      null,
      null,
      'auth',
      ['email' => (string)$email, 'method' => 'password'],
      'success',
      null
    );
  } catch (Throwable $e) { error_log('audit login_success failed: '.$e->getMessage()); }

  // ── Redirecció post-login amb protecció de retorn segons rol ──────────────
$roleBase = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));

// Si hi ha returnUrl, valida’l i aplica excepcions per a 'sala'
if ($returnUrl !== '') {
  $isRestricted = (
    str_contains($returnUrl, 'espai.php') ||
    preg_match('#/(?:admin|secure)/#i', $returnUrl)
  );
  if (!($roleBase === 'sala' && $isRestricted)) {
    header('Location: ' . $returnUrl);
    exit;
  }
}

// Fallback segons rols (precedència: admin > productor > tecnic > sala)
if (ks_has_role('admin')) {
  redirect_to('espai.php', ['seccio' => 'admin_riders']);
} elseif (ks_has_role('productor')) {
  redirect_to('espai.php', ['seccio' => 'produccio']);
} elseif (ks_has_role('tecnic')) {
  redirect_to('espai.php', ['seccio' => 'riders']);         // “Els teus riders”
} elseif (ks_has_role('sala')) {
  redirect_to('rider_vistos.php');                         // “Riders que has vist”
} else {
  redirect_to('index.php');                                 // darrer recurs
}
exit;

} catch (Throwable $e) {
  error_log('Error en login: ' . $e->getMessage());

  // 🔎 AUDIT: excepció servidor
  try {
    audit_admin(
      $pdo,
      0,
      false,
      'login_error',
      null,
      null,
      'auth',
      ['email' => (string)($email ?? ''), 'reason' => 'exception'],
      'error',
      'server exception'
    );
  } catch (Throwable $e2) { error_log('audit login_error(exception) failed: '.$e2->getMessage()); }

  push_login_modal_flash('danger', $messages['error']['server_error'] ?? 'Error intern.');
  redirect_index($withReturn(['modal' => 'login', 'error' => 'server_error']));
}