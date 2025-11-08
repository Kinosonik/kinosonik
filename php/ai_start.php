<?php
// php/ai_start.php — crea un job a la cua i (opcionalment) llança el worker en segon pla (PRIMERA PAGINA QUE LLANÇA USUARI)
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- Helper d'error JSON ---------- */
function fail(string $m, int $code = 400): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo json_encode(['error' => $m], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Mètode i CSRF ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') fail('method_not_allowed', 405);

$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) fail('csrf_invalid', 403);

/* ---------- Sessió ---------- */
if (empty($_SESSION['user_id'])) fail('login_required', 401);

/* ---------- Input ---------- */
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) fail('bad_id');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

$pdo = db();

/* ---------- Permisos ---------- */
$me      = (int)($_SESSION['user_id'] ?? 0);
$tipus   = (string)($_SESSION['tipus_usuari'] ?? '');
$isAdmin = (strcasecmp($tipus, 'admin') === 0);

$st = $pdo->prepare("
  SELECT ID_Usuari, Rider_UID, Object_Key, Estat_Segell
    FROM Riders
   WHERE ID_Rider = ?
   LIMIT 1
");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) fail('not_found', 404);
if (!$isAdmin && (int)$row['ID_Usuari'] !== $me) fail('forbidden', 403);

$estat = strtolower((string)$row['Estat_Segell']);
if ($estat === 'validat' || $estat === 'caducat') fail('locked', 409);

$riderUid = (string)$row['Rider_UID'];
$riderId  = $id;

/* ---------- Evita duplicats actius per rider ---------- */
$chk = $pdo->prepare("SELECT COUNT(*) FROM ia_jobs WHERE rider_id = ? AND status IN ('queued','running')");
$chk->execute([$riderId]);
if ((int)$chk->fetchColumn() > 0) {
  fail('already_running_or_queued', 409);
}

/* ---------- Crear job a la cua ---------- */
$jobUid = substr(bin2hex(random_bytes(12)), 0, 12);

$ins = $pdo->prepare("
  INSERT INTO ia_jobs (rider_id, job_uid, status, attempts, max_attempts, payload_json, created_at)
  VALUES (:rid, :job, 'queued', 0, 3, NULL, UTC_TIMESTAMP())
");
$ins->execute([':rid' => $riderId, ':job' => $jobUid]);

/* ---------- Estat inicial (opcional per UI que fa polling) ---------- */
$stateFile = sys_get_temp_dir() . "/ai-$jobUid.json";
$initial = [
  'pct'       => 0,
  'stage'     => 'En cua…',
  'logs'      => ['Job creat i encolat'],
  'done'      => false,
  'id'        => $riderId,
  'uid'       => (int)($_SESSION['user_id'] ?? 0),
  'ts'        => time(), // epoch (TZ-agnòstic)
  'ts_iso'    => (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('c'),
  'job_uid'   => $jobUid,
  'rider_uid' => $riderUid,
];
@file_put_contents($stateFile, json_encode($initial, JSON_UNESCAPED_UNICODE), LOCK_EX);

// --- Hora local per a logs/UI (format EU 24h)
$tz = defined('KS_TZ') ? new DateTimeZone(KS_TZ) : new DateTimeZone('Europe/Madrid');
$nowLocal = (new DateTimeImmutable('now', $tz))->format('d/m/Y H:i:s');


/* ---------- Audit (opcional però recomanat) ---------- */
try {
  audit_admin(
    $pdo,
    (int)$me,
    $isAdmin,
    'ia_enqueue_job_ui',
    (int)$riderId,
    (string)$riderUid,
    'ai_start',
    ['job_uid' => $jobUid, 'created_at_local' => $nowLocal],
    'success'
  );
} catch (Throwable $e) { error_log('audit ia_enqueue_job_ui failed: ' . $e->getMessage()); }

// --- Comprovar exec i, NOMÉS SI ESTÀ PERMÈS, llançar worker en 2n pla
$allowExec =
    (defined('KS_ALLOW_EXEC_WORKER') && KS_ALLOW_EXEC_WORKER === true) ||
    (getenv('KS_ALLOW_EXEC_WORKER') === '1');

if ($allowExec) {
  $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
  if (!in_array('exec', $disabled, true)) {
    $php    = '/usr/bin/php'; // força CLI
    $worker = __DIR__ . '/cron/ia_worker.php';
    $logDir = (defined('KS_SECURE_LOG_DIR') ? rtrim((string)KS_SECURE_LOG_DIR, '/') : '/var/config/logs/riders');
    if (!is_dir($logDir)) { @mkdir($logDir, 02775, true); }
    @chmod($logDir, 02775);
    $log = $logDir . '/ai-worker.log';

    $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($worker)
         . ' --id=' . (int)$riderId . ' --job=' . escapeshellarg($jobUid)
         . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
    @exec($cmd);
  }
}

/* ---------- Resposta ---------- */
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo json_encode([
  'ok'   => true,
  'job'  => $jobUid,
  'poll' => BASE_PATH . 'php/ai_status.php?job=' . rawurlencode($jobUid)
], JSON_UNESCAPED_UNICODE);