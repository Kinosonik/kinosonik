<?php
// php/delete_rider.php — Elimina un rider: R2 + BD + IA (runs/logs/state)
declare(strict_types=1);

use Aws\Exception\AwsException;

require_once dirname(__DIR__) . '/php/preload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/ia_cleanup.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rider_notify.php'; // notificacions subscripcions

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = db();

/* ── Mètode + CSRF ───────────────────────────────────────── */
if (!is_post()) {
  http_response_code(405);
  exit;
}

$csrf = $_POST['csrf'] ?? '';
$context = trim((string)($_POST['context'] ?? '')); // 'admin' quan ve d’admin_riders
$targetSeccio = ($context === 'admin') ? 'admin_riders' : 'riders';

if ($csrf === '' || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
  header('Location: ' . BASE_PATH . 'espai.php?seccio=' . $targetSeccio . '&error=csrf', true, 303);
  exit;
}

/* ── Usuari ──────────────────────────────────────────────── */
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
  header('Location: ' . BASE_PATH . 'index.php?error=login_required', true, 303);
  exit;
}

$tipus = (string)($_SESSION['tipus_usuari'] ?? '');
if ($tipus === '') {
  $stRole = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari=? LIMIT 1");
  $stRole->execute([$currentUserId]);
  $tipus = (string)($stRole->fetchColumn() ?: '');
  $_SESSION['tipus_usuari'] = $tipus;
}
$isAdmin = (strcasecmp($tipus, 'admin') === 0);

/* ── Inputs ──────────────────────────────────────────────── */
$riderUid = trim((string)($_POST['rider_uid'] ?? ''));

// UUID v4: 8-4-4-4-12 hex amb variant/version
if ($riderUid === '' || !preg_match(
  '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
  $riderUid
)) {
  header('Location: ' . BASE_PATH . 'espai.php?seccio=' . $targetSeccio . '&error=invalid_rider', true, 303);
  exit;
}

/* ── Carrega + permisos (amb lock) ───────────────────────── */
$riderId   = null;   // inicialitzats per si fallen abans d’assignar
$ownerId   = null;
$objectKey = '';

try {
  $pdo->beginTransaction();

  // Bloqueja el rider objectiu per aquesta transacció
  $st = $pdo->prepare("
    SELECT ID_Rider, ID_Usuari, Object_Key, Estat_Segell
      FROM Riders
     WHERE Rider_UID = :uid
     LIMIT 1
     FOR UPDATE
  ");
  $st->execute([':uid' => $riderUid]);
  $r = $st->fetch(PDO::FETCH_ASSOC);

  if (!$r) {
    $pdo->rollBack();
    header('Location: ' . BASE_PATH . 'espai.php?seccio=' . $targetSeccio . '&error=rider_not_found', true, 303);
    exit;
  }

  $riderId   = (int)$r['ID_Rider'];
  $ownerId   = (int)$r['ID_Usuari'];
  $objectKey = (string)($r['Object_Key'] ?? '');
  $sealTarget = strtolower((string)($r['Estat_Segell'] ?? ''));

  if (!$isAdmin && $ownerId !== $currentUserId) {
    $pdo->rollBack();
    header('Location: ' . BASE_PATH . 'espai.php?seccio=' . $targetSeccio . '&error=forbidden', true, 303);
    exit;
  }

    /* ── DB: neteja referències + purga IA + notificacions + elimina rider ── */

  // (1a) Localitza riders que redirigeixen a aquest i estan CADUCATS
  $caducatRefs = [];
  $stRef = $pdo->prepare("
    SELECT ID_Rider, Estat_Segell
      FROM Riders
     WHERE rider_actualitzat = :id
  ");
  $stRef->execute([':id' => $riderId]);
  $refRows = $stRef->fetchAll(PDO::FETCH_ASSOC);
  foreach ($refRows as $rowRef) {
    $stRefSeal = strtolower((string)($rowRef['Estat_Segell'] ?? ''));
    if ($stRefSeal === 'caducat') {
      $caducatRefs[] = (int)$rowRef['ID_Rider'];
    }
  }

  // (1b) Neteja referències d’altres riders que apuntin a aquest
  $stUpd = $pdo->prepare("UPDATE Riders SET rider_actualitzat = NULL WHERE rider_actualitzat = :id");
  $stUpd->execute([':id' => $riderId]);
  // Aquí ja tenim la nova situació de redirect per a ks_notify_rider_subscribers()

  // (2) Purga dades IA associades (runs/logs/state)
  $purge = ia_purge_for_rider($pdo, $riderId);

  // (3) Notificacions als subscrits

    // (3) Notificacions als subscrits

  // 3.1) Notifica que aquest rider (validat o caducat) serà eliminat
  if (in_array($sealTarget, ['validat', 'caducat'], true)) {
    try {
      ks_notify_rider_subscribers($pdo, $riderId, 'rider_deleted');
    } catch (Throwable $e) {
      error_log("delete_rider: notify rider_deleted error rider={$riderId}: " . $e->getMessage());
    }
  }

  // 3.2) Notifica canvis de redirecció en riders CADUCATS que apuntaven aquí
  foreach ($caducatRefs as $ridRef) {
    try {
      ks_notify_rider_subscribers($pdo, $ridRef, 'redirect_changed');
    } catch (Throwable $e) {
      error_log("delete_rider: notify redirect_changed error rider={$ridRef}: " . $e->getMessage());
    }
  }

  // (4) Elimina el rider
  $stDel = $pdo->prepare("DELETE FROM Riders WHERE ID_Rider = :id LIMIT 1");
  $stDel->execute([':id' => $riderId]);
  if ($stDel->rowCount() !== 1) {
    throw new RuntimeException('DELETE Riders no va afectar cap fila');
  }

  $pdo->commit();


  // ✅ Auditoria èxit (no bloqueja el flux si falla)
  try {
    audit_admin(
      $pdo,
      $currentUserId,
      $isAdmin,
      'delete_rider',
      $riderId,
      $riderUid,
      $targetSeccio,
      [
        'purge' => [
          'runs_deleted'  => (int)($purge['rows_deleted']   ?? 0),
          'logs_deleted'  => (int)($purge['logs_deleted']   ?? 0),
          'state_deleted' => (int)($purge['state_deleted']  ?? 0),
        ],
        'object_key' => $objectKey ?: null,
      ],
      'success',
      null
    );
  } catch (Throwable $e) {
    error_log('audit_admin failed: ' . $e->getMessage());
  }

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }

  // ❌ Auditoria error (no ha d’aturar la resposta)
  try {
    audit_admin(
      $pdo,
      $currentUserId,
      $isAdmin,
      'delete_rider',
      $riderId,
      $riderUid ?: null,
      $targetSeccio,
      [],
      'error',
      substr($e->getMessage(), 0, 250)
    );
  } catch (Throwable $ignored) {
    error_log('audit_admin (error branch) failed: ' . $ignored->getMessage());
  }

  error_log('delete_rider DB error: ' . $e->getMessage());
  header('Location: ' . BASE_PATH . 'espai.php?seccio=' . $targetSeccio . '&error=server_error', true, 303);
  exit;
}

/* ── R2: esborrat fora de la transacció ─────────────────── */
if ($objectKey !== '') {
  try {
    $bucket = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? '');
    if ($bucket) {
      $s3 = r2_client();
      $s3->deleteObject(['Bucket' => $bucket, 'Key' => $objectKey]);
    } else {
      error_log("delete_rider: R2_BUCKET no definit (rider_uid={$riderUid})");
    }
  } catch (AwsException $e) {
    error_log("R2 deleteObject error ({$objectKey}): " . $e->getAwsErrorMessage());
  } catch (Throwable $e) {
    error_log("R2 deleteObject error ({$objectKey}): " . $e->getMessage());
  }
}

/* ── CDN/Cache purge del públic (si hi ha helper) ───────── */
try {
  if (function_exists('cdn_purge_rider')) {
    cdn_purge_rider($riderUid);
  }
} catch (Throwable $e) {
  error_log("CDN purge failed for {$riderUid}: " . $e->getMessage());
}

/* ── Redirect d’èxit coherent amb l’origen ───────────────── */
header('Location: ' . BASE_PATH . 'espai.php?seccio=' . $targetSeccio . '&success=rider_deleted', true, 303);
exit;