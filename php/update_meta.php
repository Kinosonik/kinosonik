<?php
// php/update_meta.php — Actualitza meta d’un rider (descripció, referència)
// Requereix sessió iniciada i permís: admin o propietari. No permet editar VALIDAT/CADUCAT.
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

/* Helpers JSON */
function jerr(string $m, int $code = 400, array $meta = [], ?callable $audit = null): never {
  if ($audit) { $audit('error', ['reason' => $m] + $meta, $m); }
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE);
  exit;
}
function jok(array $data = []): never {
  echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
  exit;
}

/* Auditoria local */
$aud = function(string $status, array $meta = [], ?string $err = null) use ($pdo) {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'rider_meta_update',
      isset($meta['rider_id']) ? (int)$meta['rider_id'] : null,
      isset($meta['rider_uid']) ? (string)$meta['rider_uid'] : null,
      'rider_meta',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit rider_meta_update failed: ' . $e->getMessage());
  }
};

/* Auth bàsic */
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) jerr('login_required', 401, [], $aud);
$isAdmin = (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0);

/* Inputs */
$id    = (int)($_POST['rider_id'] ?? 0);
$uid   = trim((string)($_POST['rider_uid'] ?? ''));
$desc  = trim((string)($_POST['descripcio'] ?? ''));
$refIn = trim((string)($_POST['referencia'] ?? '')); // buit → NULL

if ($id <= 0 || $uid === '') jerr('missing_params', 422, [], $aud);
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uid)) {
  jerr('invalid_uid', 422, ['rider_uid' => $uid], $aud);
}

/* Hard limits de camps */
if ($desc !== '' && mb_strlen($desc, 'UTF-8') > 2000) {
  $desc = mb_substr($desc, 0, 2000, 'UTF-8');
}
if ($refIn !== '') {
  // referència “segura”: lletres, dígits, guions, punts, espais i underscore (max 120)
  $refIn = preg_replace('/[^ \pL\pN._-]+/u', ' ', $refIn);
  $refIn = trim(preg_replace('/\s{2,}/u', ' ', $refIn));
  if (mb_strlen($refIn, 'UTF-8') > 120) {
    $refIn = mb_substr($refIn, 0, 120, 'UTF-8');
  }
}
$refNew = ($refIn !== '' ? $refIn : null);

/* Carrega rider */
$st = $pdo->prepare("
  SELECT ID_Rider, ID_Usuari, Rider_UID, Estat_Segell, Descripcio, Referencia
    FROM Riders
   WHERE ID_Rider = :id AND Rider_UID = :uid
   LIMIT 1
");
$st->execute([':id' => $id, ':uid' => $uid]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) jerr('not_found', 404, ['rider_id' => $id, 'rider_uid' => $uid], $aud);

$ownerId = (int)$row['ID_Usuari'];
if (!($isAdmin || $ownerId === $userId)) {
  jerr('forbidden', 403, ['rider_id' => (int)$row['ID_Rider'], 'rider_uid' => (string)$row['Rider_UID']], $aud);
}

/* Estat */
$estat = strtolower((string)($row['Estat_Segell'] ?? ''));
if (in_array($estat, ['validat', 'caducat'], true)) {
  jerr('sealed_immutable', 422, ['rider_id' => (int)$row['ID_Rider'], 'rider_uid' => (string)$row['Rider_UID'], 'seal' => $estat], $aud);
}

/* Canvis (per auditoria) */
$fieldsChanged = [];
if ((string)$row['Descripcio'] !== $desc) { $fieldsChanged[] = 'Descripcio'; }
if ((string)($row['Referencia'] ?? '') !== (string)($refNew ?? '')) { $fieldsChanged[] = 'Referencia'; }

/* Idempotent */
if (!$fieldsChanged) {
  $aud('success', [
    'rider_id'       => (int)$row['ID_Rider'],
    'rider_uid'      => (string)$row['Rider_UID'],
    'owner_id'       => $ownerId,
    'is_admin'       => $isAdmin,
    'noop'           => true,
    'fields_changed' => []
  ]);
  jok(['changed' => false]);
}

/* Update */
try {
  $up = $pdo->prepare("
    UPDATE Riders
       SET Descripcio = :d,
           Referencia = :r
     WHERE ID_Rider = :id AND Rider_UID = :uid
     LIMIT 1
  ");
  $up->execute([
    ':d'  => $desc,
    ':r'  => $refNew,
    ':id' => $id,
    ':uid'=> $uid
  ]);

  $aud('success', [
    'rider_id'       => (int)$row['ID_Rider'],
    'rider_uid'      => (string)$row['Rider_UID'],
    'owner_id'       => $ownerId,
    'is_admin'       => $isAdmin,
    'fields_changed' => $fieldsChanged
  ]);

  jok([
    'changed' => true,
    'fields'  => $fieldsChanged
  ]);

} catch (Throwable $e) {
  error_log('update_meta DB error: ' . $e->getMessage());
  jerr('db_error', 500, ['rider_id' => (int)$row['ID_Rider'], 'rider_uid' => (string)$row['Rider_UID']], $aud);
}