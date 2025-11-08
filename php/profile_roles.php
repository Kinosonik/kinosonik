<?php
// php/profile_roles.php — activa/desactiva el rol "tecnic" (self productors o admin) i opcionalment purga riders
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/ia_cleanup.php'; // ia_purge_for_rider()
require_once __DIR__ . '/r2.php';         // r2_client()
require_once __DIR__ . '/flash.php';      // ✅ flash_set()

$pdo = db();

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* --------------- Utils --------------- */
function audit_roles(PDO $pdo, string $status, array $meta = [], ?string $err = null): void {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'user_roles_update',
      null,
      null,
      'profile',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit user_roles_update failed: ' . $e->getMessage());
  }
}

function sanitize_return(?string $raw): string {
  $s = sanitize_return_url($raw ?? '');
  return $s !== '' ? $s : (BASE_PATH . 'espai.php?seccio=dades');
}

/** ✅ Redirecció coherent amb banners (flash + query) */
function redirect_with_flash(string $type, string $key, string $returnTo): never {
  flash_set($type, $key);
  $sep = (str_contains($returnTo, '?') ? '&' : '?');
  header('Location: ' . $returnTo . $sep . rawurlencode($type) . '=' . rawurlencode($key), true, 302);
  exit;
}

/* --------------- Context sessió --------------- */
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
if ($sessionUserId <= 0 || empty($_SESSION['loggedin'])) {
  audit_roles($pdo, 'error', ['reason' => 'login_required'], 'login_required');
  redirect_to('index.php', ['modal' => 'login', 'error' => 'login_required']);
}

$isAdmin = ks_is_admin();
$targetUserId = $sessionUserId;
if ($isAdmin && isset($_POST['user_id']) && ctype_digit((string)$_POST['user_id'])) {
  $targetUserId = (int)$_POST['user_id'];
}

/* --------------- Rol base del target --------------- */
$st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$st->execute([$targetUserId]);
$usr = $st->fetch(PDO::FETCH_ASSOC);
if (!$usr) {
  audit_roles($pdo, 'error', ['reason' => 'user_not_found', 'target_user_id' => $targetUserId], 'db_error');
  redirect_to('espai.php', ['seccio' => 'dades', 'error' => 'db_error']);
}
$targetBaseRole = strtolower((string)($usr['Tipus_Usuari'] ?? ''));

/* --------------- Política de permís ---------------
   - Admin: sempre pot.
   - Usuari no-admin: només si edita el seu propi perfil i el rol base és 'productor'.
--------------------------------------------------- */
if (!$isAdmin) {
  $editingSelf = ($targetUserId === $sessionUserId);
  if (!($editingSelf && $targetBaseRole === 'productor')) {
    audit_roles($pdo, 'error', [
      'reason' => 'forbidden',
      'target_user_id' => $targetUserId,
      'target_base' => $targetBaseRole
    ], 'forbidden');
    http_response_code(403);
    exit('Forbidden');
  }
}

/* --------------- Inputs --------------- */
$enableTech  = ((string)($_POST['role_tecnic'] ?? '') === '1');
$wipeRiders  = ((string)($_POST['wipe_riders'] ?? '') === '1');
$confirmText = strtoupper(trim((string)($_POST['confirm'] ?? '')));
$returnTo    = sanitize_return($_POST['return_to'] ?? null);

/* --------------- Engega procés --------------- */
try {
  if ($enableTech) {
    // ACTIVAR rol → inserta i refresca sessió
    $ins = $pdo->prepare("INSERT IGNORE INTO User_Roles (user_id, role) VALUES (?, 'tecnic')");
    $ins->execute([$targetUserId]);

  } else {
    // DESACTIVAR rol
    if ($wipeRiders) {
      // Validació confirmació textual
      $must = (current_lang() === 'en') ? 'DELETE' : 'ELIMINAR';
      if ($confirmText !== $must) {
        audit_roles($pdo, 'error', ['reason' => 'confirm_required', 'target_user_id' => $targetUserId], 'confirm_required');
        redirect_with_flash('error', 'confirm_required', $returnTo);
      }

      // 1) Riders + object keys
      $stR = $pdo->prepare("SELECT ID_Rider, Object_Key FROM Riders WHERE ID_Usuari = ?");
      $stR->execute([$targetUserId]);
      $rows = $stR->fetchAll(PDO::FETCH_ASSOC);

      $riderIds    = [];
      $objectKeys  = [];
      foreach ($rows as $row) {
        $rid = (int)($row['ID_Rider'] ?? 0);
        if ($rid > 0) $riderIds[] = $rid;
        $k = (string)($row['Object_Key'] ?? '');
        if ($k !== '') $objectKeys[] = $k;
      }

      // 2) Purga IA per rider
      foreach ($riderIds as $rid) {
        try { ia_purge_for_rider($pdo, $rid); }
        catch (Throwable $e) { error_log("[role_tecnic->off] purge error rider=$rid: ".$e->getMessage()); }
      }

      // 3) Transacció robusta: neteja dependències i elimina Riders
$pdo->beginTransaction();
try {
  if (!empty($riderIds)) {
    $ph = implode(',', array_fill(0, count($riderIds), '?'));

    // Helper tolerant: si la taula/columna no existeix, només registra i continua
    $exec_safe = function(string $sql, array $params = []) use ($pdo) {
      try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
      } catch (Throwable $e) {
        error_log('profile_roles cleanup skip: ' . $e->getMessage() . ' | SQL=' . $sql);
      }
    };

    // 3.0) Desfer redireccions que apuntin als meus riders
    $exec_safe("UPDATE Riders SET Redirect_To = NULL WHERE Redirect_To IN ($ph)", $riderIds);

    // 3.1) Dependències habituals (neteja “filles” abans d’esborrar Riders)
    $exec_safe("DELETE FROM ia_jobs  WHERE rider_id IN ($ph)",           $riderIds);
    $exec_safe("DELETE FROM ia_runs  WHERE rider_id IN ($ph)",           $riderIds);
    $exec_safe("DELETE FROM ia_artifacts WHERE rider_id IN ($ph)",       $riderIds);
    $exec_safe("DELETE FROM Rider_View_Counters  WHERE Rider_ID IN ($ph)", $riderIds);
    $exec_safe("DELETE FROM User_Recent_Riders  WHERE Rider_ID IN ($ph)",  $riderIds);
  }

  // 3.2) Ara sí: elimina els riders del target
  $stDel = $pdo->prepare("DELETE FROM Riders WHERE ID_Usuari = ?");
  $stDel->execute([$targetUserId]);

  $pdo->commit();
} catch (Throwable $eTx) {
  $pdo->rollBack();
  error_log('profile_roles db_delete_riders_failed: ' . $eTx->getMessage());
  audit_roles($pdo, 'error', [
    'reason' => 'db_delete_riders_failed',
    'target_user_id' => $targetUserId,
    'db_msg' => $eTx->getMessage()
  ], 'db_error');
  redirect_with_flash('error', 'db_error', $returnTo);
}


      // 4) Esborra objectes a R2 (fora transacció)
      $r2 = null;
      try {
        $bucket = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? '');
        if ($bucket === '') { throw new RuntimeException('R2_BUCKET no definit'); }
        $r2 = r2_client();
      } catch (Throwable $e) {
        error_log('profile_roles: init R2 failed: '.$e->getMessage());
      }
      $r2Errors = 0;
      if ($r2) {
        foreach ($objectKeys as $key) {
          try {
            $r2->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
          } catch (Throwable $e) {
            $r2Errors++;
            error_log('profile_roles: R2 delete error for key '.$key.': '.$e->getMessage());
          }
        }
      }

      // 5) Auditoria purga
      try {
        audit_admin(
          $pdo,
          (int)($_SESSION['user_id'] ?? 0),
          ks_is_admin(),
          'role_tecnic_disable_cleanup',
          null, null,
          'profile',
          [
            'target_user_id'  => $targetUserId,
            'riders_deleted'  => (int)count($riderIds),
            'r2_objects'      => (int)count($objectKeys),
            'r2_errors'       => (int)$r2Errors
          ],
          'success',
          null
        );
      } catch (Throwable $e) { /* swallow */ }

      // 6) Navbar/guards: ja no té riders
      $_SESSION['has_my_riders'] = 0;
    }

    // Treu el rol de User_Roles (si existeix)
    $del = $pdo->prepare("DELETE FROM User_Roles WHERE user_id = ? AND role = 'tecnic'");
    $del->execute([$targetUserId]);
  }

  // Refresca rols a sessió
  $stRR = $pdo->prepare("SELECT role FROM User_Roles WHERE user_id = ?");
  $stRR->execute([$targetUserId]);
  $newRoles = array_values(array_unique(array_map('strtolower', array_column($stRR->fetchAll(PDO::FETCH_ASSOC), 'role'))));
  if ($targetUserId === $sessionUserId) {
    $_SESSION['roles_extra'] = $newRoles;
  }

  // ÈXIT GLOBAL → flash + redirect coherent
redirect_with_flash('success', $enableTech ? 'role_tecnic_on' : 'role_tecnic_off', $returnTo);


} catch (Throwable $e) {
  error_log('profile_roles fatal: ' . $e->getMessage());
  audit_roles($pdo, 'error', ['reason' => 'exception'], 'server_error');
  redirect_with_flash('error', 'server_error', $returnTo);
}
