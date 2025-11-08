<?php
// php/delete_account.php — L'usuari elimina el seu propi compte.
// Neteja Riders (BD) + IA (runs/logs/state) + fitxers a Cloudflare R2 i tanca sessió.
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/ia_cleanup.php'; // ← purga de runs/logs/state
require_once __DIR__ . '/audit.php';      // ← AUDIT
require_once __DIR__ . '/rider_notify.php'; // ← notificacions subscripcions
require_once __DIR__ . '/db.php';

// ── Mètode + CSRF (una sola vegada) ────────────────────────
if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();
$pdo = db();

/** Redirecció simple a index.php amb querystring opcional */
function back_to_index(string $qs = ''): never {
  $url = BASE_PATH . 'index.php' . ($qs ? ('?' . $qs) : '');
  header('Location: ' . $url, true, 302);
  exit;
}

/* ── Auth ───────────────────────────────────────────────── */
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || empty($_SESSION['loggedin'])) {
  back_to_index('error=login_required');
}

/* ── CSRF ───────────────────────────────────────────────── */
$csrf = $_POST['csrf'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
  back_to_index('error=csrf');
}

/* ── Blindatge: mai permetre esborrar l'usuari #1 ───────── */
if ($userId === 1) {
  // Audit del bloqueig d’auto-baixa admin
  try {
    audit_admin(
      $pdo,
      (int)$userId,
      true,
      'user_delete_account_attempt',
      null,
      null,
      'account',
      ['reason' => 'admin_self_delete_forbidden'],
      'error',
      'forbidden'
    );
  } catch (Throwable $e) { /* silent */ }
  back_to_index('error=forbidden');
}


/* ── Confirmació textual (multillengua tolerant) ────────── */
$confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
$validWords = ['ELIMINAR','DELETE']; // ca/es/en
if (!in_array($confirm, $validWords, true)) {
  back_to_index('error=confirm_required');
}

/* ── Bloqueig admins: millor fer-ho des del panell admin ── */
$stRole = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$stRole->execute([$userId]);
$myType = strtolower((string)($stRole->fetchColumn() ?: ''));
if ($myType === 'admin') {
  back_to_index('error=forbidden');
}

/* ── Vars per a l’auditoria (necessàries també al catch) ── */
$riderIds     = [];
$objectKeys   = [];
$r2ErrorKeys  = [];

/* ── AUDIT: intent de baixa de compte ───────────────────── */
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) { require_once __DIR__ . '/db.php'; $pdo = db(); }
  audit_admin(
    $pdo,
    (int)$userId,
    false,                   // usuari normal (no admin)
    'user_delete_account_attempt',
    null,
    null,
    'account',
    [],
    'success',
    null
  );
} catch (Throwable $e) {
  error_log('audit user_delete_account_attempt failed: ' . $e->getMessage());
}

try {
  /* ── 1) Llista riders de l'usuari (IDs + Object_Key) ──── */
  $stR = $pdo->prepare("SELECT ID_Rider, Object_Key, Estat_Segell FROM Riders WHERE ID_Usuari = ?");
  $stR->execute([$userId]);
  $rows = $stR->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $row) {
      $rid = (int)($row['ID_Rider'] ?? 0);
      if ($rid > 0) {
          $riderIds[] = $rid;

          // Notificar només si el segell és validat o caducat
          $seal = strtolower((string)($row['Estat_Segell'] ?? ''));
          if (in_array($seal, ['validat', 'caducat'], true)) {
              try {
                  ks_notify_rider_subscribers($pdo, $rid, 'rider_deleted');
              } catch (Throwable $e) {
                  error_log('[DELETE_ACCOUNT] notify error rider='.$rid.': '.$e->getMessage());
              }
          }

      }

      $k = (string)($row['Object_Key'] ?? '');
      if ($k !== '') {
          $objectKeys[] = $k;
      }
  }


  /* ── 2) Purga IA per a cada rider (runs + logs + ia_state) */
  foreach ($riderIds as $rid) {
    try {
      $purge = ia_purge_for_rider($pdo, $rid);
      error_log(sprintf('[DELETE_ACCOUNT] purge rider=%d runs=%d logs=%d state=%d',
        $rid,
        (int)($purge['rows_deleted']  ?? 0),
        (int)($purge['logs_deleted']  ?? 0),
        (int)($purge['state_deleted'] ?? 0)
      ));
    } catch (Throwable $e) {
      // No bloquegem tota la baixa, però ho registrem
      error_log("[DELETE_ACCOUNT] purge error rider=$rid: ".$e->getMessage());
    }
  }

  /* ── 3) Transacció BD: elimina Riders i després l’Usuari ─ */
  $pdo->beginTransaction();

  // 3.0) Neteja redireccions de tercers que apuntin als meus riders
  if (!empty($riderIds)) {
    $placeholders = implode(',', array_fill(0, count($riderIds), '?'));
    $sqlNullRedirects = "UPDATE Riders SET rider_actualitzat = NULL WHERE rider_actualitzat IN ($placeholders)";
    $stmtNull = $pdo->prepare($sqlNullRedirects);
    $stmtNull->execute($riderIds);
  }


  $delR = $pdo->prepare("DELETE FROM Riders WHERE ID_Usuari = ?");
  $delR->execute([$userId]);

  $delU = $pdo->prepare("DELETE FROM Usuaris WHERE ID_Usuari = ? AND ID_Usuari <> 1 LIMIT 1");
  $delU->execute([$userId]);

  $pdo->commit();

  /* ── 4) Esborra objectes a R2 (fora de la transacció) ─── */
  try {
    $bucket = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? '');
    if ($bucket === '') { throw new RuntimeException('R2_BUCKET no definit'); }
    $r2 = r2_client();
  } catch (Throwable $e) {
    // No parem la baixa; només registrem que pot quedar "brutícia" a l'object store
    error_log('delete_account: no s’ha pogut inicialitzar R2: ' . $e->getMessage());
    $r2 = null;
  }

  if ($r2) {
    foreach ($objectKeys as $key) {
      try {
        $r2->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
      } catch (Throwable $e) {
        $r2ErrorKeys[] = $key; // ← AUDIT
        error_log('delete_account: R2 delete error for key '.$key.': '.$e->getMessage());
      }
    }
  }

  /* ── 5) Tanca sessió i neteja cookies ─────────────────── */
  $_SESSION = [];

  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $domain = $_SERVER['HTTP_HOST'] ?? '';

  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $sessName = session_name();

    // 5.1) Esborra la cookie de sessió amb els paràmetres originals
    setcookie($sessName, '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);

    // 5.2) Esborra també a la path del projecte (per si BASE_PATH canvia)
    if (!function_exists('cookie_path')) {
      // fallback conservador
      $cookiePath = '/';
    } else {
      $cookiePath = cookie_path();
    }
    setcookie($sessName, '', time() - 42000, $cookiePath, $domain, $secure, $params['httponly']);
  }
  session_destroy();

  // 5.3) Neteja cookies pròpies (modal + idioma) amb path coherent
  if (function_exists('ks_clear_login_modal_cookie')) {
    ks_clear_login_modal_cookie();
  }
  @setcookie('lang', '', [
    'expires'  => time() - 3600,
    'path'     => function_exists('cookie_path') ? cookie_path() : '/',
    'domain'   => $domain,
    'secure'   => $secure,
    'httponly' => false,
    'samesite' => 'Lax',
  ]);

  /* ── 6) AUDIT: èxit ───────────────────────────────────── */
  try {
    audit_admin(
      $pdo,
      (int)$userId,
      false,
      'user_delete_account',
      null,
      null,
      'account',
      [
        'riders_deleted'   => (int)count($riderIds),
        'r2_objects_total' => (int)count($objectKeys),
        'r2_errors'        => (int)count($r2ErrorKeys),
        'r2_error_keys'    => array_slice($r2ErrorKeys, 0, 50),
      ],
      'success',
      null
    );
  } catch (Throwable $e) {
    error_log('audit user_delete_account success failed: ' . $e->getMessage());
  }

  /* ── 7) Fora, amb missatge d’èxit ─────────────────────── */
  back_to_index('success=account_deleted');

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('delete_account fatal: ' . $e->getMessage());

  // ——— AUDIT: error durant la baixa
  try {
    if (!isset($pdo) || !($pdo instanceof PDO)) { require_once __DIR__ . '/db.php'; $pdo = db(); }
    audit_admin(
      $pdo,
      (int)$userId,
      false,
      'user_delete_account',
      null,
      null,
      'account',
      [
        'riders_deleted'   => (int)count($riderIds),
        'r2_objects_total' => (int)count($objectKeys),
        'r2_errors'        => (int)count($r2ErrorKeys),
        'error'            => $e->getMessage()
      ],
      'error',
      'user account deletion failed'
    );
  } catch (Throwable $e2) {
    error_log('audit user_delete_account error failed: ' . $e2->getMessage());
  }

  back_to_index('error=server_error');
}