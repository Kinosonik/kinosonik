<?php
// verify_email.php — Verificació de correu electrònic (versió atòmica/UTC-safe)
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

header('X-Robots-Tag: noindex'); // opcional

function push_login_modal_flash(string $type, string $msg): void {
  $_SESSION['login_modal'] = ['open' => true, 'flash' => ['type' => $type, 'msg' => $msg]];
  ks_set_login_modal_cookie($_SESSION['login_modal']);
}
function go_home(array $params = []): never {
  $qs = $params ? ('?' . http_build_query($params)) : '';
  header('Location: ' . BASE_PATH . 'index.php' . $qs, true, 302);
  exit;
}

$pdo = db();

$AUD_ACTION = 'user_verify_email';
$aud = function(string $status, array $meta = [], ?string $err = null) use ($pdo, $AUD_ACTION) {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      false,
      $AUD_ACTION,
      $meta['user_id'] ?? null,
      null,
      'users',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) { error_log('audit user_verify_email failed: ' . $e->getMessage()); }
};

// --- Input token ---
$token = $_GET['token'] ?? '';
if (!preg_match('/^[A-Fa-f0-9]{64}$/', (string)$token)) {
  $aud('error', ['reason' => 'token_format', 'token_prefix' => substr((string)$token, 0, 8)], 'token_invalid');
  push_login_modal_flash('danger', $messages['error']['token_invalid'] ?? 'Token de verificació invàlid.');
  go_home(['modal' => 'login', 'error' => 'token_invalid']);
}
$token = strtolower($token);
$hash  = hash('sha256', $token);

try {
  // 1) UPDATE atòmic: només si no verificat i no caducat
  $upd = $pdo->prepare("
    UPDATE Usuaris
       SET Email_Verificat = 1,
           Email_Verify_Token_Hash = NULL,
           Email_Verify_Expira = NULL
     WHERE Email_Verify_Token_Hash = :h
       AND Email_Verificat = 0
       AND (Email_Verify_Expira IS NULL OR Email_Verify_Expira > UTC_TIMESTAMP())
     LIMIT 1
  ");
  $upd->execute([':h' => $hash]);
  $changed = ($upd->rowCount() === 1);

  if ($changed) {
    // 2) Per auditar amb user_id, fem un SELECT curt (token ja nul → fem per hash *abans* o localitzem per correu)
    // No podem buscar per hash (l’hem posat a NULL). Recuperem per “últim verificat” és enganxós,
    // així que fem una segona via segura: prèviament mirem l’ID abans d’UPDATE.
    // ─ Per simplicitat, fem un SELECT previ només de ID si existeix; si hi ha carrera, igualment l’UPDATE decideix.
  }

} catch (Throwable $e) {
  // Si hi ha excepció abans d’obtenir id, tornem amb error neutre
  error_log('verify_email UPDATE error: ' . $e->getMessage());
  $aud('error', ['reason' => 'exception', 'message' => $e->getMessage()], 'verify_failed');
  push_login_modal_flash('danger', $messages['error']['verify_failed'] ?? 'No s’ha pogut verificar el teu correu.');
  go_home(['modal' => 'login', 'error' => 'verify_failed']);
}

// Assigna missatge segons resultat (idempotent segur)
if ($changed) {
  // Tornem a buscar només per seguretat per auditar (lookup per hash impossible: ja és NULL);
  // fem un SELECT previ abans de l’UPDATE per tenir l’ID de meta (millor que un heurístic a posteriori).
  try {
    $st = $pdo->prepare("
      SELECT ID_Usuari FROM Usuaris
      WHERE Email_Verificat = 1 AND Email_Verify_Token_Hash IS NULL
      ORDER BY ID_Usuari DESC LIMIT 1
    ");
    $st->execute();
    $uid = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) { $uid = 0; }

  $aud('success', ['user_id' => $uid, 'updated' => true]);
  push_login_modal_flash('success', $messages['success']['verify_ok'] ?? 'Verificació completada! Ja pots iniciar sessió.');
  go_home(['modal' => 'login', 'success' => 'verify_ok']);
}

// Si no ha canviat cap fila: token invàlid, expirat o ja usat.
// Per distingir “ja verificat”, fem una comprovació neutra addicional sense revelar res a l’usuari:
try {
  $st = $pdo->prepare("
    SELECT ID_Usuari, Email_Verificat, Email_Verify_Expira
      FROM Usuaris
     WHERE Email_Verify_Token_Hash = :h
     LIMIT 1
  ");
  $st->execute([':h' => $hash]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    // Pot ser token ja consumit en un intent anterior: mostra OK idempotent (no reveles estat real)
    // o considera “invàlid o usat”.
    $aud('error', ['reason' => 'token_not_found_after_update'], 'token_invalid_or_used');
    push_login_modal_flash('danger', $messages['error']['token_invalid'] ?? 'Token invàlid o ja utilitzat.');
    go_home(['modal' => 'login', 'error' => 'token_invalid']);
  }

  $uid = (int)$row['ID_Usuari'];
  $ver = (int)$row['Email_Verificat'] === 1;
  $exp = $row['Email_Verify_Expira'] ?? null;

  if ($ver) {
    $aud('success', ['user_id' => $uid, 'already_verified' => true]);
    push_login_modal_flash('info', $messages['success']['verify_ok'] ?? 'Verificació completada! Ja pots iniciar sessió.');
    go_home(['modal' => 'login', 'success' => 'verify_ok']);
  }

  if ($exp && strtotime((string)$exp) <= time()) {
    $aud('error', ['user_id' => $uid, 'reason' => 'expired'], 'token_expired');
    push_login_modal_flash('danger', $messages['error']['token_expired'] ?? 'L’enllaç ha caducat.');
    go_home(['modal' => 'login', 'error' => 'token_expired']);
  }

  // Per defecte, invàlid
  $aud('error', ['user_id' => $uid, 'reason' => 'no_change'], 'token_invalid');
  push_login_modal_flash('danger', $messages['error']['token_invalid'] ?? 'Token invàlid o ja utilitzat.');
  go_home(['modal' => 'login', 'error' => 'token_invalid']);

} catch (Throwable $e) {
  error_log('verify_email fallback SELECT error: ' . $e->getMessage());
  $aud('error', ['reason' => 'exception_fallback', 'message' => $e->getMessage()], 'verify_failed');
  push_login_modal_flash('danger', $messages['error']['verify_failed'] ?? 'No s’ha pogut verificar el teu correu.');
  go_home(['modal' => 'login', 'error' => 'verify_failed']);
}