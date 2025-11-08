<?php
// php/reupload_rider.php — Repujar versió d’un rider existent a R2 + UPDATE BD
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/ia_cleanup.php'; // per purgar IA
require_once __DIR__ . '/../php/i18n.php';

$pdo = db();

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function jok(array $data = []): never {
  echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ─────────────────────────── Seguretat bàsica ──────────────────────────── */
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) jerr('Sessió no iniciada', 401);
$tipusSessio = (string)($_SESSION['tipus_usuari'] ?? '');
$isAdmin = (strcasecmp($tipusSessio, 'admin') === 0);

/* ─────────────────────────── Inputs ─────────────────────────────────────── */
$riderUid = trim((string)($_POST['rider_uid'] ?? ''));
$riderId  = (int)($_POST['rider_id'] ?? 0);

if ($riderUid === '' || $riderId <= 0) jerr('Paràmetres incomplets', 422);
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $riderUid)) {
  jerr('UID invàlid', 422);
}

/* ─────────────────────────── Carrega rider i permisos ──────────────────── */
$st = $pdo->prepare("
  SELECT ID_Rider, ID_Usuari, Rider_UID, Estat_Segell, Object_Key
    FROM Riders
   WHERE ID_Rider = :id AND Rider_UID = :uid
   LIMIT 1
");
$st->execute([':id' => $riderId, ':uid' => $riderUid]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) jerr('Rider no trobat', 404);

$ownerId = (int)$row['ID_Usuari'];
if (!$isAdmin && $userId !== $ownerId) jerr('Sense permís', 403);

$estat = strtolower(trim((string)$row['Estat_Segell']));
if (in_array($estat, ['validat','caducat'], true)) {
  jerr('No es pot repujar un rider validat/caducat', 422);
}

/* ─────────────────────────── Validacions de pujada (i18n) ──────────────── */
// Bàsiques PHP
if (!isset($_FILES['rider_pdf']) || !is_array($_FILES['rider_pdf'])) {
  jerr(__('riders.upload.no_file'), 400);
}
$f = $_FILES['rider_pdf'];
if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  jerr(__('riders.upload.upload_error'), 400);
}
$tmpPath = (string)($f['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
  jerr(__('riders.upload.tmp_invalid'), 400);
}

// Límit coherent amb ENV o 20 MB per defecte
$maxMb    = (int)(getenv('UPLOAD_MAX_MB') ?: ($_ENV['UPLOAD_MAX_MB'] ?? 20));
$maxBytes = $maxMb * 1024 * 1024;
$size     = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
  jerr(__('riders.upload.too_big'), 413);
}

// Nom de fitxer net
$origNameRaw = (string)($f['name'] ?? 'rider.pdf');
$origName = mb_substr(basename($origNameRaw), 0, 180, 'UTF-8');
$origName = preg_replace('/[\x00-\x1F\x7F]+/u', '', $origName); // treu control chars
if ($origName === '' || stripos($origName, '.pdf') === false) { $origName = 'rider.pdf'; }

// MIME i màgia
$finfo = new finfo(FILEINFO_MIME_TYPE);
// Normalitza (p.ex. "application/pdf; charset=binary")
$mime  = strtolower((string)($finfo->file($tmpPath) ?: ''));
$mimeMain = explode(';', $mime, 2)[0];
if ($mimeMain !== 'application/pdf') {
  $fh = @fopen($tmpPath, 'rb');
  $magic = $fh ? fread($fh, 5) : '';
  if ($fh) fclose($fh);
  if (strncmp((string)$magic, '%PDF-', 5) !== 0) {
    jerr(__('riders.upload.invalid_pdf'), 422);
  }
}

// Comprovació ràpida de final de PDF (evita truncats)
$tailLen = min(2048, $size);
$fh2 = @fopen($tmpPath, 'rb');
if ($fh2) {
  fseek($fh2, -$tailLen, SEEK_END);
  $tail = fread($fh2, $tailLen) ?: '';
  fclose($fh2);
  if (strpos($tail, '%%EOF') === false) {
    jerr(__('riders.upload.truncated_pdf'), 422);
  }
}

/* ─────────────────────────── Hash ──────────────────────────────────────── */
$sha256 = null;
try {
  $sha = @hash_file('sha256', $tmpPath);
  $sha256 = ($sha && strlen($sha) === 64) ? $sha : null;
} catch (Throwable $e) {
  error_log('reupload_rider.php hash error: ' . $e->getMessage());
}

/* ─────────────────────────── Clau d’objecte i pujada a R2 ──────────────── */
$objectKey = (string)($row['Object_Key'] ?? '');
if ($objectKey === '') {
  $objectKey = "user/{$ownerId}/{$riderUid}.pdf";
}

try {
  $client = r2_client();
  $bucket = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? '');
  if ($bucket === '') { throw new RuntimeException('R2_BUCKET no definit'); }

  $stream = fopen($tmpPath, 'rb');
  if ($stream === false) { throw new RuntimeException('No s’ha pogut llegir el fitxer temporal'); }

  // Sobreescriu el mateix objecte (mateixa clau)
  $client->putObject([
    'Bucket'             => $bucket,
    'Key'                => $objectKey,
    'Body'               => $stream,
    'ContentType'        => 'application/pdf',
    // Millor UX en navegadors/descàrrega
    'ContentDisposition' => 'inline; filename="' . addslashes($origName) . '"',
  ]);
  fclose($stream);
} catch (Throwable $e) {
  error_log('reupload_rider.php R2 error: ' . $e->getMessage());
  try {
    audit_admin(
      $pdo, $userId, $isAdmin, 'admin_reupload', $riderId, $riderUid, 'admin_riders',
      ['stage' => 'r2_upload', 'object_key' => $objectKey, 'size' => $size], 'error',
      substr($e->getMessage(), 0, 250)
    );
  } catch (Throwable $ae) { error_log('audit admin_reupload (r2) failed: ' . $ae->getMessage()); }
  jerr('R2 upload', 500);
}

/* ─────────────────────────── UPDATE BD + purga IA ──────────────────────── */
try {
  $pdo->beginTransaction();

  $sql = "
    UPDATE Riders
       SET Nom_Arxiu         = :nom,
           Mida_Bytes        = :mida,
           Hash_SHA256       = :sha256,
           Object_Key        = :objkey,
           Estat_Segell      = 'cap',
           Valoracio         = 0,
           Data_Publicacio   = NULL,
           rider_actualitzat = NULL
           -- , Validacio_Manual_Solicitada = 0
           -- , Validacio_Manual_Data       = NULL
           -- , Data_Modificacio            = NOW()
     WHERE ID_Rider = :id AND Rider_UID = :uid
     LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':nom'    => $origName,
    ':mida'   => $size,
    ':sha256' => $sha256, // pot ser NULL
    ':objkey' => $objectKey,
    ':id'     => $riderId,
    ':uid'    => $riderUid,
  ]);

  // Purga IA vinculada (best-effort)
  try {
    ia_purge_for_rider($pdo, $riderId);
  } catch (Throwable $e) {
    error_log("reupload_rider ia_purge_for_rider failed: " . $e->getMessage());
  }

  $pdo->commit();

  // AUDIT èxit
  audit_admin(
    $pdo, $userId, $isAdmin, 'admin_reupload', $riderId, $riderUid, 'admin_riders',
    ['filename' => $origName, 'size' => $size, 'object_key' => $objectKey, 'sha256' => $sha256],
    'success', null
  );

  jok([
    'sha256'    => $sha256,
    'bytes'     => $size,
    'estat'     => 'cap',
    'valoracio' => 0,
  ]);

} catch (Throwable $t) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('reupload_rider.php DB error: ' . $t->getMessage());

  try {
    audit_admin(
      $pdo, $userId, $isAdmin, 'admin_reupload', $riderId, $riderUid, 'admin_riders',
      ['stage' => 'db_update', 'object_key' => $objectKey], 'error',
      substr($t->getMessage(), 0, 250)
    );
  } catch (Throwable $ae) { error_log('audit admin_reupload (db) failed: ' . $ae->getMessage()); }

  jerr('DB update', 500);
}