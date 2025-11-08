<?php
// php/admin/ia_purge.php — Purga execucions/logs d'IA d’un rider
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
require_once dirname(__DIR__) . '/config.php';  // BASE_PATH + KS_SECURE_LOG_DIR
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/audit.php';
require_once dirname(__DIR__) . '/ia_cleanup.php';

function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ── Només POST ─────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

try {
  // 1) Sessió + CSRF
  $uid = $_SESSION['user_id'] ?? null;
  if (!$uid) { throw new RuntimeException('login_required'); }

  $csrf = $_POST['csrf'] ?? '';
  if (!is_string($csrf) || $csrf === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $csrf)) {
    throw new RuntimeException('bad_csrf');
  }

  // 2) Inputs
  $riderId = isset($_POST['rider_id']) && ctype_digit((string)$_POST['rider_id'])
    ? (int)$_POST['rider_id'] : 0;
  if ($riderId <= 0) { throw new RuntimeException('bad_rider'); }

  $pdo = db();

  // 3) Rol + propietat
  $st = $pdo->prepare('SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari=? LIMIT 1');
  $st->execute([$uid]);
  $isAdmin = (strcasecmp((string)$st->fetchColumn(), 'admin') === 0);

  $st = $pdo->prepare('SELECT ID_Usuari, Rider_UID FROM Riders WHERE ID_Rider=? LIMIT 1');
  $st->execute([$riderId]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r) { throw new RuntimeException('rider_not_found'); }

  $ownerId  = (int)$r['ID_Usuari'];
  $riderUid = (string)$r['Rider_UID'];

  if (!$isAdmin && $ownerId !== (int)$uid) {
    throw new RuntimeException('forbidden');
  }

  // 4) Purga centralitzada (ia_runs + logs + ia_state + /tmp/ai-*.json)
  $result = ia_purge_for_rider($pdo, $riderId) ?: [];

  // 5) Auditoria + redirect OK
  try {
    audit_admin(
      $pdo,
      (int)$uid,
      $isAdmin,
      'ia_purge',
      $riderId,
      $riderUid,
      'ia_detail',
      $result,
      'success',
      null,
      ['target_type'=>'ia','target_id'=>$riderId,'target_uid'=>$riderUid]
    );
  } catch (Throwable $e) {
    error_log('audit ia_purge failed: ' . $e->getMessage());
  }

  // 6) Redirecció 303 després del POST
  header('Location: ' . BASE_PATH . 'espai.php?seccio=ia_detail&' . http_build_query([
    'rider_uid' => $riderUid,
    'purged'    => 1,
    'rows'      => $result['rows_deleted'] ?? 0,
    'logs'      => $result['logs_deleted'] ?? 0,
    'state'     => $result['state_deleted'] ?? 0,
  ], '', '&', PHP_QUERY_RFC3986), true, 303);
  exit;

} catch (Throwable $e) {
  // 7) Auditoria d’error
  try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
      require_once dirname(__DIR__) . '/db.php';
      $pdo = db();
    }
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      isset($isAdmin) ? (bool)$isAdmin : false,
      'ia_purge',
      isset($riderId) ? (int)$riderId : null,
      isset($riderUid) ? (string)$riderUid : null,
      'ia_detail',
      ['error' => $e->getMessage()],
      'error',
      'purge_failed'
    );
  } catch (Throwable $e2) {
    error_log('audit ia_purge error failed: ' . $e2->getMessage());
  }

  // 8) Redirecció d’error 303 (semàntica POST→GET)
  while (ob_get_level()) { ob_end_clean(); }
  http_response_code(303);
  $msg = urlencode($e->getMessage());
  $fallback = BASE_PATH . 'espai.php?seccio=riders&error=' . $msg;
  header('Location: ' . $fallback, true, 303);
  exit;
}