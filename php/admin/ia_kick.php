<?php
// php/admin/ia_kick.php — Encola un job d’IA per a un rider (només ADMIN)
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
require_once dirname(__DIR__) . '/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/audit.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'login_required']);
  exit;
}

try {
  $pdo = db();

  // ── Només ADMIN
  $st = $pdo->prepare('SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari=? LIMIT 1');
  $st->execute([$uid]);
  $isAdmin = strcasecmp((string)$st->fetchColumn(), 'admin') === 0;
  if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
  }

  // ── CSRF segur
  $csrf = $_POST['csrf'] ?? '';
  if (!is_string($csrf) || $csrf === '' ||
      !hash_equals((string)($_SESSION['csrf'] ?? ''), $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad_csrf']);
    exit;
  }

  // ── Inputs: rider_uid o rider_id
  $riderId = null;
  if (isset($_POST['rider_id']) && ctype_digit((string)$_POST['rider_id'])) {
    $riderId = (int)$_POST['rider_id'];
  } elseif (!empty($_POST['rider_uid'])) {
    $riderUid = trim((string)$_POST['rider_uid']);
    $st = $pdo->prepare('SELECT ID_Rider FROM Riders WHERE Rider_UID = ? LIMIT 1');
    $st->execute([$riderUid]);
    $riderId = (int)($st->fetchColumn() ?: 0);
  }

  if (!$riderId) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_rider']);
    exit;
  }

  // ── Verifica que el rider existeix
  $st = $pdo->prepare('SELECT COUNT(*) FROM Riders WHERE ID_Rider = ?');
  $st->execute([$riderId]);
  if ((int)$st->fetchColumn() === 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'rider_not_found']);
    exit;
  }

  // ── Transacció per evitar condicions de carrera
  $pdo->beginTransaction();

  // Bloqueja fila i comprova si hi ha job actiu
  $chk = $pdo->prepare("SELECT COUNT(*) FROM ia_jobs
                        WHERE rider_id=? AND status IN ('queued','running')
                        FOR UPDATE");
  $chk->execute([$riderId]);
  if ((int)$chk->fetchColumn() > 0) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>'already_running_or_queued']);
    exit;
  }

  // ── Enfila nou job
  $job_uid = bin2hex(random_bytes(12)); // 24 hex — col·lisions pràcticament impossibles
  $ins = $pdo->prepare("
    INSERT INTO ia_jobs (rider_id, job_uid, status, attempts, max_attempts, payload_json, created_at)
    VALUES (:rid, :job, 'queued', 0, 3, NULL, UTC_TIMESTAMP())
  ");
  $ins->execute([':rid'=>$riderId, ':job'=>$job_uid]);
  $jobId = (int)$pdo->lastInsertId();

  $pdo->commit();

  // ── Audit
  try {
    audit_admin(
      $pdo, (int)$uid, true,
      'ia_enqueue_job',
      (int)$riderId, null,
      'ia_kick',
      ['job_uid'=>$job_uid, 'job_id'=>$jobId],
      'success'
    );
  } catch (Throwable $e) {
    error_log('audit ia_enqueue_job failed: '.$e->getMessage());
  }

  echo json_encode(['ok'=>true, 'run_id'=>null, 'job_uid'=>$job_uid, 'job_id'=>$jobId]);

} catch (Throwable $e) {
  if ($pdo?->inTransaction()) { $pdo->rollBack(); }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]);
}