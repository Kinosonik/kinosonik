<?php
// php/save_profile.php — actualitza el perfil d’un usuari (self o, si admin, un altre)
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// A preload.php ja tens db(), middleware, etc.
// require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/flash.php';

$pdo = db();

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* ----------------- Utils ----------------- */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function audit_profile(PDO $pdo, string $status, array $meta = [], ?string $err = null): void {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'user_profile_update',
      null,
      null,
      'profile',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit user_profile_update failed: ' . $e->getMessage());
  }
}

/* ----------------- Guard bàsic ----------------- */
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
if ($sessionUserId <= 0 || empty($_SESSION['loggedin'])) {
  audit_profile($pdo, 'error', ['reason' => 'login_required'], 'login_required');
  redirect_to('index.php', ['modal' => 'login', 'error' => 'login_required']);
}

/* ----------------- Permisos (admin pot editar altres) ----------------- */
try {
  $st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
  $st->execute([$sessionUserId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $isAdmin = $row && strcasecmp((string)$row['Tipus_Usuari'], 'admin') === 0;
} catch (Throwable $e) {
  audit_profile($pdo, 'error', ['reason' => 'role_lookup_failed'], 'db_error');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'server_error']);
}

$targetUserId = $sessionUserId;
if ($isAdmin && isset($_POST['user_id']) && ctype_digit((string)$_POST['user_id'])) {
  $targetUserId = (int)$_POST['user_id'];
}

// Un 'sala' no pot editar tercers (ni forçant POST user_id)
if (!$isAdmin && $targetUserId !== $sessionUserId) {
  audit_profile($pdo, 'error', ['reason' => 'forbidden_target_edit', 'target' => $targetUserId], 'forbidden');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'forbidden']);
}

/* ----------------- Inputs ----------------- */
$nom        = trim((string)($_POST['nom'] ?? ''));
$cognoms    = trim((string)($_POST['cognoms'] ?? ''));
$telefon    = trim((string)($_POST['telefon'] ?? ''));
$emailRaw   = (string)($_POST['email'] ?? '');
$email      = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($emailRaw), 'UTF-8') : '';
$publicaTel = (($_POST['publica_telefon'] ?? '0') === '1') ? 1 : 0;
$idiomaIn   = normalize_lang((string)($_POST['idioma'] ?? current_lang()));
$pwd1       = (string)($_POST['password'] ?? '');
$pwd2       = (string)($_POST['confirmPassword'] ?? '');

/* ----------------- Validacions bàsiques ----------------- */
if ($nom === '' || $cognoms === '' || $telefon === '' || !$email) {
  audit_profile($pdo, 'error', ['target_user_id' => $targetUserId, 'reason' => 'missing_fields'], 'missing_fields');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'missing_fields']);
}
if (!preg_match('/^\+[1-9][0-9]{6,14}$/', $telefon)) {
  audit_profile($pdo, 'error', ['target_user_id' => $targetUserId, 'reason' => 'invalid_phone'], 'invalid_phone');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'invalid_phone']);
}
if ($pwd1 !== '' && $pwd1 !== $pwd2) {
  audit_profile($pdo, 'error', ['target_user_id' => $targetUserId, 'reason' => 'password_mismatch'], 'password_mismatch');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'password_mismatch']);
}
if ($pwd1 !== '' && !preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $pwd1)) {
  audit_profile($pdo, 'error', ['target_user_id' => $targetUserId, 'reason' => 'weak_password'], 'weak_password');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'weak_password']);
}

/* ----------------- Carrega usuari target ----------------- */
try {
  $st = $pdo->prepare("
    SELECT ID_Usuari, Email_Usuari, Nom_Usuari, Cognoms_Usuari, Telefon_Usuari,
           COALESCE(Idioma,'ca') AS Idioma, COALESCE(Publica_Telefon,0) AS Publica_Telefon,
           Tipus_Usuari
      FROM Usuaris
     WHERE ID_Usuari = ?
     LIMIT 1
  ");
  $st->execute([$targetUserId]);
  $usr = $st->fetch(PDO::FETCH_ASSOC);
  if (!$usr) {
    audit_profile($pdo, 'error', ['target_user_id' => $targetUserId, 'reason' => 'user_not_found'], 'db_error');
    redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'db_error']);
  }
} catch (Throwable $e) {
  audit_profile($pdo, 'error', ['target_user_id' => $targetUserId, 'reason' => 'db_error_load'], 'db_error');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'db_error']);
}

// ── Rols
$targetRole = strtolower((string)($usr['Tipus_Usuari'] ?? 'unknown'));

// ── Valor entrant del formulari i política segons rol
$publicaTel = (($_POST['publica_telefon'] ?? '0') === '1') ? 1 : 0;

// ── Política:
//  - 'sala' (autogestionant-se) NO pot canviar-ho → conserva valor BD
//  - admin editant qualsevol: POT canviar-ho
//  - opcionalment, si vols forçar que un 'sala' MAI publiqui telèfon, descomenta "// $publicaTel = 0;" a sota
if ($targetRole === 'sala' && !$isAdmin) {
  $publicaTel = (int)$usr['Publica_Telefon']; // ignora entrada del form
  // $publicaTel = 0; // ← Opcional: força sempre 0 per 'sala'
}

/* ----------------- Unicitat d'email si ha canviat ----------------- */
if (strcasecmp($email, (string)$usr['Email_Usuari']) !== 0) {
  try {
    $chk = $pdo->prepare("SELECT 1 FROM Usuaris WHERE Email_Usuari = ? AND ID_Usuari <> ? LIMIT 1");
    $chk->execute([$email, $targetUserId]);
    if ($chk->fetch()) {
      audit_profile($pdo, 'error', ['target_user_id' => $targetUserId, 'reason' => 'email_in_use', 'email' => $email], 'email_in_use');
      redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'email_in_use']);
    }
  } catch (Throwable $e) {
    audit_profile($pdo, 'error', ['target_user_id' => $targetUserId, 'reason' => 'db_error_email_check'], 'db_error');
    redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'db_error']);
  }
}

/* ----------------- Calcular canvis per auditar ----------------- */
$fieldsChanged = [];
$addIfChanged = function(string $field, $old, $new) use (&$fieldsChanged) {
  if ((string)$old !== (string)$new) { $fieldsChanged[] = $field; }
};
$addIfChanged('nom',              (string)$usr['Nom_Usuari'],                 $nom);
$addIfChanged('cognoms',          (string)$usr['Cognoms_Usuari'],             $cognoms);
$addIfChanged('telefon',          (string)$usr['Telefon_Usuari'],             $telefon);
$addIfChanged('email',            mb_strtolower((string)$usr['Email_Usuari'], 'UTF-8'), $email);
$addIfChanged('idioma',           strtolower((string)$usr['Idioma']),         strtolower($idiomaIn));
$addIfChanged('publica_telefon',  (int)$usr['Publica_Telefon'],  (int)$publicaTel);
$passwordChanged = ($pwd1 !== '');

/* ----------------- UPDATE ----------------- */
try {
  if ($pwd1 !== '') {
    $hash = defined('PASSWORD_ARGON2ID') ? password_hash($pwd1, PASSWORD_ARGON2ID) : password_hash($pwd1, PASSWORD_DEFAULT);
    $sql = "UPDATE Usuaris
               SET Nom_Usuari=?, Cognoms_Usuari=?, Telefon_Usuari=?, Email_Usuari=?, Password_Hash=?, Idioma=?, Publica_Telefon=?
             WHERE ID_Usuari=?";
    $params = [$nom, $cognoms, $telefon, $email, $hash, $idiomaIn, $publicaTel, $targetUserId];
  } else {
    $sql = "UPDATE Usuaris
               SET Nom_Usuari=?, Cognoms_Usuari=?, Telefon_Usuari=?, Email_Usuari=?, Idioma=?, Publica_Telefon=?
             WHERE ID_Usuari=?";
    $params = [$nom, $cognoms, $telefon, $email, $idiomaIn, $publicaTel, $targetUserId];
  }
  $pdo->prepare($sql)->execute($params);

  // Si és el propi perfil, refresca sessió i idioma
  if ($targetUserId === $sessionUserId) {
    $_SESSION['user_email']    = $email;
    $_SESSION['user_name']     = $nom;
    $_SESSION['user_surnames'] = $cognoms;
    set_lang($idiomaIn); // sincronitza sessió + cookie (path coherent via BASE_PATH)
  }

  audit_profile($pdo, 'success', [
    'is_admin'         => $isAdmin,
    'target_user_id'   => $targetUserId,
    'self_edit'        => ($targetUserId === $sessionUserId),
    'fields_changed'   => $fieldsChanged,
    'password_changed' => $passwordChanged,
  ]);

  // Flash d’èxit + redirecció
  flash_set('success', 'updated');
  $qs = ['seccio' => 'dades', 'success' => 'updated'];
  if ($isAdmin && $targetUserId !== $sessionUserId) {
    $qs['user'] = (string)$targetUserId;
  }
  redirect_to('espai.php', $qs);

} catch (Throwable $e) {
  error_log('Update profile error: ' . $e->getMessage());
  audit_profile($pdo, 'error', [
    'is_admin'         => $isAdmin,
    'target_user_id'   => $targetUserId,
    'fields_changed'   => $fieldsChanged,
    'password_changed' => $passwordChanged,
    'reason'           => 'db_update_error'
  ], 'db_error');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'db_error']);
}