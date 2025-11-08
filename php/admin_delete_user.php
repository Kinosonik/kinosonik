<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/ia_cleanup.php'; // ✅ per purgar artefactes IA per rider

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* ───────────────────────── Helpers flash + redirect ─────────────────────── */
function flash_set_text(string $type, string $text, array $extra = []): void {
  $_SESSION['flash'] = ['type' => $type, 'text' => $text, 'extra' => $extra];
}
function go_list(): never {
  header('Location: ' . BASE_PATH . 'espai.php?seccio=usuaris', true, 302);
  exit;
}

/* ─────────────────────────── PDO ────────────────────────────────────────── */
$pdo = db();

/* ───────────────────────────── Auth bàsic ──────────────────────────────── */
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
  try {
    audit_admin($pdo, 0, false, 'admin_delete_user', null, null, 'admin_users', [], 'error', 'login_required');
  } catch (Throwable $e) { error_log('audit admin_delete_user (login_required) failed: '.$e->getMessage()); }
  flash_set_text('error', 'Has d’iniciar sessió.');
  header('Location: ' . BASE_PATH . 'index.php?error=login_required', true, 302);
  exit;
}

// És admin?
$st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$st->execute([$userId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
$isAdmin = $row && strcasecmp((string)$row['Tipus_Usuari'], 'admin') === 0;
if (!$isAdmin) {
  try {
    audit_admin($pdo, (int)$userId, false, 'admin_delete_user', null, null, 'admin_users', [], 'error', 'forbidden');
  } catch (Throwable $e) { error_log('audit admin_delete_user (forbidden) failed: '.$e->getMessage()); }
  flash_set_text('error', 'No tens permisos per fer aquesta acció.');
  go_list();
}

/* ────────────────────────── Validacions target ─────────────────────────── */
$targetId = (int)($_POST['user_id'] ?? 0);
if ($targetId <= 0) {
  try {
    audit_admin($pdo, (int)$userId, true, 'admin_delete_user', null, null, 'admin_users', ['target_id'=>$targetId], 'error', 'bad_params');
  } catch (Throwable $e) { error_log('audit admin_delete_user (bad_params) failed: '.$e->getMessage()); }
  flash_set_text('error', 'Petició invàlida (usuari incorrecte).');
  go_list();
}

// Evita auto-eliminació
if ($targetId === (int)$userId) {
  try {
    audit_admin($pdo, (int)$userId, true, 'admin_delete_user', null, null, 'admin_users', ['target_id'=>$targetId], 'error', 'self_delete_blocked');
  } catch (Throwable $e) { error_log('audit admin_delete_user (self_delete_blocked) failed: '.$e->getMessage()); }
  flash_set_text('error', 'No pots eliminar el teu propi compte.');
  go_list();
}

// Llegeix dades del target
$stUser = $pdo->prepare("SELECT ID_Usuari, Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$stUser->execute([$targetId]);
$target = $stUser->fetch(PDO::FETCH_ASSOC);
if (!$target) {
  try {
    audit_admin($pdo, (int)$userId, true, 'admin_delete_user', null, null, 'admin_users', ['target_id'=>$targetId], 'error', 'target_not_found');
  } catch (Throwable $e) { error_log('audit admin_delete_user (target_not_found) failed: '.$e->getMessage()); }
  flash_set_text('error', 'L’usuari indicat no existeix.');
  go_list();
}

$targetType = strtolower((string)$target['Tipus_Usuari']);

// No permetre eliminar l’últim ADMIN del sistema
if ($targetType === 'admin') {
  $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM Usuaris WHERE Tipus_Usuari = 'admin'")->fetchColumn();
  if ($countAdmins <= 1) {
    try {
      audit_admin($pdo, (int)$userId, true, 'admin_delete_user', null, null, 'admin_users', ['target_id'=>$targetId], 'error', 'last_admin_blocked');
    } catch (Throwable $e) { error_log('audit admin_delete_user (last_admin_blocked) failed: '.$e->getMessage()); }
    flash_set_text('error', 'No es pot eliminar l’últim administrador.');
    go_list();
  }
}

/* ───────────────────── Riders + claus d’objecte R2 ─────────────────────── */
$stRiders = $pdo->prepare("
  SELECT ID_Rider, Object_Key
    FROM Riders
   WHERE ID_Usuari = ?
");
$stRiders->execute([$targetId]);
$riders = $stRiders->fetchAll(PDO::FETCH_ASSOC);

$riderIds   = [];
$objectKeys = [];
foreach ($riders as $r) {
  $riderIds[] = (int)$r['ID_Rider'];
  $k = trim((string)($r['Object_Key'] ?? ''));
  if ($k !== '') { $objectKeys[] = $k; }
}

/* ─────────────────────────── Purgar IA per rider ──────────────────────────
   Això neteja ia_runs + logs + ia_state (i el que defineixi ia_purge_for_rider)
--------------------------------------------------------------------------- */
$purgeTotals = ['rows_deleted'=>0,'logs_deleted'=>0,'state_deleted'=>0,'errors'=>0];
foreach ($riderIds as $rid) {
  try {
    $res = ia_purge_for_rider($pdo, $rid);
    if (is_array($res)) {
      $purgeTotals['rows_deleted']  += (int)($res['rows_deleted']  ?? 0);
      $purgeTotals['logs_deleted']  += (int)($res['logs_deleted']  ?? 0);
      $purgeTotals['state_deleted'] += (int)($res['state_deleted'] ?? 0);
    }
  } catch (Throwable $e) {
    $purgeTotals['errors']++;
    error_log("admin_delete_user purge rider#$rid failed: ".$e->getMessage());
  }
}

/* ─────────────────────────── Esborrat a R2 (best-effort) ───────────────── */
$r2Warnings = false;
$r2ErrCount = 0;

if (!empty($objectKeys)) {
  try {
    $client = r2_client();
    $bucket = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? '');
    if ($bucket === '') { throw new RuntimeException('R2_BUCKET no definit'); }

    $batchSize = 900;
    for ($i = 0; $i < count($objectKeys); $i += $batchSize) {
      $chunk = array_slice($objectKeys, $i, $batchSize);
      $objects = array_map(fn($k) => ['Key' => $k], $chunk);

      $resp = $client->deleteObjects([
        'Bucket' => $bucket,
        'Delete' => [ 'Objects' => $objects, 'Quiet' => true ],
      ]);

      if (!empty($resp['Errors'])) {
        $r2Warnings = true;
        $r2ErrCount += count($resp['Errors']);
        foreach ($resp['Errors'] as $err) {
          $code = $err['Code'] ?? 'Unknown';
          $msg  = $err['Message'] ?? '';
          $key  = $err['Key'] ?? '';
          error_log("admin_delete_user R2 partial error: key=$key code=$code msg=$msg");
        }
      }
    }
  } catch (Throwable $e) {
    $r2Warnings = true;
    $r2ErrCount++;
    error_log('admin_delete_user R2 fatal error: ' . $e->getMessage());
  }
}

/* ──────────────────────── Esborrat a la base de dades ──────────────────── */
try {
  $pdo->beginTransaction();

  // Esborra Riders del target
  $delRiders = $pdo->prepare("DELETE FROM Riders WHERE ID_Usuari = ?");
  $delRiders->execute([$targetId]);
  $deletedRiders = (int)$delRiders->rowCount();

  // Esborra Usuari
  $delUser = $pdo->prepare("DELETE FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
  $delUser->execute([$targetId]);
  $deletedUsers = (int)$delUser->rowCount();

  if ($deletedUsers !== 1) {
    throw new RuntimeException('db_inconsistent_delete_user');
  }

  $pdo->commit();

  // ✅ Audit: èxit
  try {
    audit_admin(
      $pdo,
      (int)$userId,
      true,
      'admin_delete_user',
      null,
      null,
      'admin_users',
      [
        'target_id'       => $targetId,
        'target_type'     => $targetType,
        'deleted_users'   => $deletedUsers,
        'deleted_riders'  => $deletedRiders,
        'purge_rows'      => $purgeTotals['rows_deleted'],
        'purge_logs'      => $purgeTotals['logs_deleted'],
        'purge_state'     => $purgeTotals['state_deleted'],
        'purge_errors'    => $purgeTotals['errors'],
        'r2_objects'      => count($objectKeys),
        'r2_warnings'     => $r2Warnings,
        'r2_err_count'    => $r2ErrCount,
      ],
      'success',
      null
    );
  } catch (Throwable $e) {
    error_log('audit admin_delete_user (success) failed: '.$e->getMessage());
  }

  // Missatge final
  $noteR2   = $r2Warnings ? ' Alguns fitxers al núvol no s’han pogut esborrar; s’ha registrat la incidència.' : '';
  $notePurg = ($purgeTotals['errors'] > 0) ? ' Algunes purgues d’IA han fallat; revisa els logs.' : '';
  flash_set_text('success', 'Usuari i riders associats eliminats correctament.' . $noteR2 . $notePurg);
  go_list();

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('admin_delete_user DB error: ' . $e->getMessage());

  // 🔴 Audit: error DB
  try {
    audit_admin(
      $pdo,
      (int)$userId,
      true,
      'admin_delete_user',
      null,
      null,
      'admin_users',
      [
        'target_id'    => $targetId,
        'target_type'  => $targetType ?? null,
        'purge_rows'   => $purgeTotals['rows_deleted'] ?? 0,
        'purge_logs'   => $purgeTotals['logs_deleted'] ?? 0,
        'purge_state'  => $purgeTotals['state_deleted'] ?? 0,
        'purge_errors' => $purgeTotals['errors'] ?? 0,
        'r2_objects'   => count($objectKeys),
        'r2_warnings'  => $r2Warnings,
        'r2_err_count' => $r2ErrCount,
      ],
      'error',
      'db_error: ' . $e->getMessage()
    );
  } catch (Throwable $e2) {
    error_log('audit admin_delete_user (db_error) failed: '.$e2->getMessage());
  }

  flash_set_text('error', 'S’ha produït un error inesperat eliminant l’usuari.');
  go_list();
}