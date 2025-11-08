<?php
// php/upload_rider.php — Pujar nou rider (PDF) a R2 + inserir a BD (amb hash SHA-256) + auditoria
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* ─────────────────────────── Helpers ─────────────────────────── */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
function back_to_riders(): never { redirect_to('espai.php', ['seccio' => 'riders']); }

/* ─────────────────────────── Hash SHA-256 ─────────────────────────── */
function sha256_file_robust(string $path): ?string {
  // Intent 1: hash_file
  try {
    $h = @hash_file('sha256', $path);
    if (is_string($h) && strlen($h) === 64) {
      return $h;
    }
    error_log("upload_rider: hash_file returned ".var_export($h,true)." path={$path}");
  } catch (Throwable $e) {
    error_log('sha256_file_robust/hash_file EXC: ' . $e->getMessage());
  }

  // Intent 2: stream a mà
  try {
    $ctx = hash_init('sha256');
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
      error_log("upload_rider: fopen failed path={$path}");
      return null;
    }
    while (!feof($fh)) {
      $buf = fread($fh, 8192);
      if ($buf === '' || $buf === false) break;
      hash_update($ctx, $buf);
    }
    fclose($fh);
    $dig = hash_final($ctx, false);
    if (is_string($dig) && strlen($dig) === 64) {
      return $dig;
    }
    error_log("upload_rider: stream-hash bad len=".strlen((string)$dig)." path={$path}");
    return null;
  } catch (Throwable $e) {
    error_log('sha256_file_robust/stream EXC: ' . $e->getMessage());
    return null;
  }
}

$pdo = db();

/* Auditoria helper */
$AUD_ACTION = 'rider_upload';
$aud = function(string $status, array $meta = [], ?string $err = null) use ($pdo, $AUD_ACTION) {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      $AUD_ACTION,
      $meta['rider_id']  ?? null,
      $meta['rider_uid'] ?? null,
      'riders',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit rider_upload failed: ' . $e->getMessage());
  }
};

/* ───────────────────── Seguretat bàsica ───────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  flash_set('error', 'bad_method');
  $aud('error', ['reason'=>'bad_method']);
  back_to_riders();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  flash_set('error', 'login_required');
  $aud('error', ['reason'=>'login_required']);
  redirect_to('index.php');
}

/* ───────────────────── Inputs formulari ───────────────────── */
$descripcio = trim((string)($_POST['descripcio'] ?? ''));
$referencia = trim((string)($_POST['referencia'] ?? ''));

if ($descripcio === '') {
  flash_set('error', 'missing_description');
  $aud('error', ['reason'=>'missing_description']);
  back_to_riders();
}

// ★ Neteja i limita camps segons BD
$descripcio = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $descripcio);
$referencia = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $referencia);
$descripcio = mb_substr($descripcio, 0, 255, 'UTF-8');
$referencia = ($referencia !== '') ? mb_substr($referencia, 0, 100, 'UTF-8') : null;

if (!isset($_FILES['rider_pdf']) || !is_array($_FILES['rider_pdf'])) {
  flash_set('error', 'no_file');
  $aud('error', ['reason'=>'no_file']);
  back_to_riders();
}

$f = $_FILES['rider_pdf'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $code = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
  $keyMap = [
    UPLOAD_ERR_INI_SIZE   => 'upload_err_ini_size',
    UPLOAD_ERR_FORM_SIZE  => 'upload_err_form_size',
    UPLOAD_ERR_PARTIAL    => 'upload_err_partial',
    UPLOAD_ERR_NO_FILE    => 'upload_err_no_file',
    UPLOAD_ERR_NO_TMP_DIR => 'upload_err_no_tmp_dir',
    UPLOAD_ERR_CANT_WRITE => 'upload_err_cant_write',
    UPLOAD_ERR_EXTENSION  => 'upload_err_extension',
  ];
  $key = $keyMap[$code] ?? 'upload_err_unknown';
  error_log("upload_rider.php UPLOAD_ERR code=$code -> key=$key");
  flash_set('error', $key);
  $aud('error', ['reason'=>'upload_php_error','php_upload_code'=>$code,'php_upload_key'=>$key]);
  back_to_riders();
}

/* ─────────────── Validacions de mida i tipus ─────────────── */
$maxMb    = (int)(getenv('UPLOAD_MAX_MB') ?: ($_ENV['UPLOAD_MAX_MB'] ?? 20));
$maxBytes = $maxMb * 1024 * 1024;
$size     = (int)($f['size'] ?? 0);

if ($size <= 0 || $size > $maxBytes) {
  flash_set('error', 'file_size');
  $aud('error', ['reason'=>'file_size','size'=>$size,'limit'=>$maxBytes]);
  back_to_riders();
}

// ★ Evita fitxers ridículament petits (p.ex. 0.5 KB)
if ($size < 1024) {
  flash_set('error', 'file_too_small');
  $aud('error', ['reason'=>'file_too_small','size'=>$size]);
  back_to_riders();
}

$tmpPath  = (string)($f['tmp_name'] ?? '');
$origName = (string)($f['name'] ?? 'rider.pdf');
// Sanejament del nom d’arxiu (DB/friendly)
$origName = mb_substr(basename($origName), 0, 180, 'UTF-8');

// MIME + signatura PDF
$mime = '';
try {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($tmpPath) ?: '';
} catch (Throwable $e) {}
if ($mime !== 'application/pdf') {
  $fh = @fopen($tmpPath, 'rb');
  $magic = $fh ? fread($fh, 5) : '';
  if ($fh) fclose($fh);
  if (strncmp((string)$magic, '%PDF-', 5) !== 0) {
    flash_set('error', 'file_type');
    $aud('error', ['reason'=>'file_type','mime'=>$mime,'magic'=>bin2hex((string)$magic)]);
    back_to_riders();
  }
}

/* ─────────────── Defensa extra: is_uploaded_file ─────────────── */
if (!is_uploaded_file($tmpPath)) {
  flash_set('error', 'upload_err_unknown');
  $aud('error', ['reason'=>'not_is_uploaded_file','tmp'=>$tmpPath]);
  back_to_riders();
}

/* ─────────────────────────── Quota d’usuari ─────────────────────────── */
$quotaEnv   = getenv('USER_QUOTA_BYTES') ?: ($_ENV['USER_QUOTA_BYTES'] ?? '');
$quotaBytes = (int)$quotaEnv ?: (500 * 1024 * 1024);

$stq = $pdo->prepare("SELECT COALESCE(SUM(Mida_Bytes),0) FROM Riders WHERE ID_Usuari = :uid");
$stq->execute([':uid' => $userId]);
$usedBytes = (int)$stq->fetchColumn();

if ($quotaBytes > 0 && ($usedBytes + $size) > $quotaBytes) {
  flash_set('error', 'quota_exceeded', ['limit' => $quotaBytes, 'used' => $usedBytes]);
  $aud('error', [
    'reason'        => 'quota_exceeded',
    'quota_limit'   => $quotaBytes,
    'quota_used'    => $usedBytes,
    'new_bytes'     => $size,
    'quota_remain'  => max(0, $quotaBytes - $usedBytes),
    'quota_percent' => round(($usedBytes / $quotaBytes) * 100, 2),
  ]);
  back_to_riders();
}

/* ─────────────────────────── Hash SHA-256 ─────────────────────────── */
$sha256 = sha256_file_robust($tmpPath);
if (!$sha256) {
  error_log("upload_rider: HASH NULL size={$size} tmp={$tmpPath}");
  flash_set('error', 'hash_unavailable');
  $aud('error', ['reason'=>'hash_unavailable','tmp'=>$tmpPath,'size'=>$size]);
  back_to_riders();
}

/* ─────────────────── Clau d’objecte + pujada a R2 ─────────────────── */
$uid       = uuidv4();
$objectKey = "user/{$userId}/{$uid}.pdf";

try {
  $r2info = r2_upload($tmpPath, $objectKey, 'application/pdf');
} catch (Throwable $e) {
  error_log('upload_rider.php R2 error: ' . $e->getMessage());
  flash_set('error', 'r2_upload');
  $aud('error', [
    'reason'     => 'r2_upload',
    'rider_uid'  => $uid,
    'object_key' => $objectKey,
    'message'    => $e->getMessage()
  ]);
  back_to_riders();
}

$size   = $r2info['bytes'];
$sha256 = $r2info['hash'];


/* ───────────────────────────── Inserció a BD ───────────────────────────── */
// ★ GUARDA DATES EN UTC
try {
  $sql = "
    INSERT INTO Riders
      (ID_Usuari, Rider_UID, Nom_Arxiu, Descripcio, Referencia,
       Object_Key, Mida_Bytes, Hash_SHA256, Estat_Segell, Data_Pujada, Data_Publicacio)
    VALUES
      (:uid, :ruid, :nom, :desc, :ref,
       :okey, :mida, :sha256, 'pendent', UTC_TIMESTAMP(), NULL)
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':uid'    => $userId,
    ':ruid'   => $uid,
    ':nom'    => $origName,
    ':desc'   => $descripcio,
    ':ref'    => $referencia,           // pot ser NULL
    ':okey'   => $objectKey,
    ':mida'   => $size,
    ':sha256' => $sha256,               // MAI NULL aquí
  ]);

  $riderId = (int)$pdo->lastInsertId();

  // Auditoria d’èxit
  $aud('success', [
    'rider_id'   => $riderId,
    'rider_uid'  => $uid,
    'bytes'      => $size,
    'sha256'     => $sha256,
    'object_key' => $objectKey,
    'orig_name'  => $origName,
    'desc'       => $descripcio,
    'ref'        => $referencia,
    'quota_used' => $usedBytes,
    'quota_new'  => $usedBytes + $size
  ]);

  flash_set('success', 'uploaded');
  back_to_riders();

} catch (Throwable $t) {
  error_log('upload_rider.php DB error: ' . $t->getMessage());
  // cleanup R2 best-effort
  try {
    if (isset($client, $bucket, $objectKey) && $bucket !== '' && $objectKey !== '') {
      $client->deleteObject(['Bucket' => $bucket, 'Key' => $objectKey]);
    }
  } catch (Throwable $e) {
    error_log('upload_rider.php cleanup R2 error: ' . $e->getMessage());
  }
  $aud('error', [
    'reason'     => 'db_insert',
    'rider_uid'  => $uid,
    'object_key' => $objectKey,
    'message'    => $t->getMessage()
  ]);
  flash_set('error', 'db_insert');
  back_to_riders();
}