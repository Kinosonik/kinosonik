<?php
// php/act_upload_contra.php — puja contra-rider PDF a R2 i associa a l’actuació
declare(strict_types=1);
require_once __DIR__ . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';

ks_require_role('productor','admin');
csrf_check_or_die();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');
$actId = (int)($_POST['act_id'] ?? 0);
if ($actId <= 0) { http_response_code(400); exit('bad_request'); }

/* ── Comprova autorització ─────────────────────────── */
$sql = <<<SQL
SELECT a.id, e.owner_user_id, a.final_doc_id, d.dia, s.nom AS stage_nom, e.nom AS event_nom
FROM Stage_Day_Acts a
JOIN Stage_Days d   ON d.id = a.stage_day_id
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e       ON e.id = s.event_id
WHERE a.id = :id
SQL;
$st = $pdo->prepare($sql);
$st->execute([':id'=>$actId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$row['owner_user_id'] !== $uid) { http_response_code(403); exit('forbidden'); }

/* ── Validacions bàsiques d’arxiu ───────────────────── */
$f = $_FILES['contra_pdf'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  exit(json_encode(['ok'=>false,'error'=>'missing_file']));
}
$tmpPath  = $f['tmp_name'];
$origName = basename($f['name']);
if (!is_uploaded_file($tmpPath)) {
  exit(json_encode(['ok'=>false,'error'=>'invalid_upload']));
}

/* ── Validació de mida i tipus PDF ───────────────────── */
$maxMb    = (int)(getenv('UPLOAD_MAX_MB') ?: ($_ENV['UPLOAD_MAX_MB'] ?? 20));
$maxBytes = $maxMb * 1024 * 1024;
$size     = (int)($f['size'] ?? 0);

if ($size <= 0 || $size > $maxBytes) {
  exit(json_encode(['ok'=>false,'error'=>'file_size']));
}

// Evita fitxers massa petits (menys d’1 KB)
if ($size < 1024) {
  exit(json_encode(['ok'=>false,'error'=>'file_too_small']));
}

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
    exit(json_encode(['ok'=>false,'error'=>'file_type']));
  }
}


/* ── Cleanup del contra-rider anterior si existeix ──── */
if (!empty($row['final_doc_id'])) {
  $q = $pdo->prepare('SELECT object_key FROM Documents WHERE id=:id');
  $q->execute([':id'=>(int)$row['final_doc_id']]);
  $prevKey = $q->fetchColumn();
  if ($prevKey) {
    try {
      $client = r2_client();
      $client->deleteObject(['Bucket' => r2_bucket(), 'Key' => $prevKey]);
      error_log("act_upload_contra.php cleanup OK: $prevKey");
    } catch (Throwable $ee) {
      error_log("act_upload_contra.php cleanup FAIL ($prevKey): " . $ee->getMessage());
    }
  }
}

/* ── Pujada a R2 ────────────────────────────────────── */
$objectKey = "act/{$actId}/contra_" . date('Ymd_His') . ".pdf";
try {
  $r2info = r2_upload($tmpPath, $objectKey, 'application/pdf');
} catch (Throwable $e) {
  error_log('act_upload_contra.php R2 error: ' . $e->getMessage());
  exit(json_encode(['ok'=>false,'error'=>'r2_fail']));
}

/* ── Guarda a BD ────────────────────────────────────── */
$pdo->beginTransaction();
try {
  $stmt = $pdo->prepare('INSERT INTO Documents (filename, object_key, hash_sha256, size_bytes, uploaded_by, ts_created)
                         VALUES (:n, :k, :h, :s, :u, UTC_TIMESTAMP())');
  $stmt->execute([
    ':n' => $origName,
    ':k' => $objectKey,
    ':h' => $r2info['hash'],
    ':s' => $r2info['bytes'],
    ':u' => $uid,
  ]);
  $docId = (int)$pdo->lastInsertId();

  $upd = $pdo->prepare('UPDATE Stage_Day_Acts SET final_doc_id=:d, ts_updated=UTC_TIMESTAMP() WHERE id=:id');
  $upd->execute([':d'=>$docId, ':id'=>$actId]);

  $pdo->commit();

  // Auditoria
  audit_admin(
    $pdo,
    $uid,
    $isAdmin,
    'contra_upload',
    null,
    (string)$actId,
    'stage_day_acts',
    ['object_key'=>$objectKey,'bytes'=>$r2info['bytes'],'filename'=>$origName],
    'success'
  );

  echo json_encode(['ok'=>true,'doc_id'=>$docId,'key'=>$objectKey]);
} catch (Throwable $t) {
  $pdo->rollBack();
  error_log('DB error contra upload: '.$t->getMessage());
  echo json_encode(['ok'=>false,'error'=>'db_error']);
}
