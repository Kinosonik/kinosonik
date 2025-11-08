<?php
// php/admin/ia_export_csv.php — Export CSV de runs IA (només ADMIN)
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
require_once dirname(__DIR__) . '/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/audit.php';

/* ── Seguretat: login + rol admin ───────────────────────── */
$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
  http_response_code(302);
  header('Location: ' . BASE_PATH . 'index.php?error=login_required');
  exit;
}
$pdo = db();
$st = $pdo->prepare('SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari=? LIMIT 1');
$st->execute([$uid]);
if (strcasecmp((string)$st->fetchColumn(), 'admin') !== 0) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

/* ── Helpers ────────────────────────────────────────────── */
$clean = static function (?string $s): string {
  $s = (string)$s;
  return str_replace(["\r", "\n"], ['\r', '\n'], $s);
};
$compactJson = static function (?string $s): string {
  if ($s === null || $s === '') return '';
  $dec = json_decode($s, true);
  if (json_last_error() === JSON_ERROR_NONE) return json_encode($dec, JSON_UNESCAPED_UNICODE);
  return str_replace(["\r", "\n"], ['\r', '\n'], (string)$s);
};
$isDateYmd = static function (string $s): bool {
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
};

/* ── Paràmetres ─────────────────────────────────────────── */
$from   = trim((string)($_GET['from'] ?? ''));
$to     = trim((string)($_GET['to'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$userId = isset($_GET['user_id']) && ctype_digit((string)$_GET['user_id']) ? (int)$_GET['user_id'] : null;

// Per defecte: últims 30 dies
if ($from === '' && $to === '') {
  $to   = (new DateTime('today'))->format('Y-m-d');
  $from = (new DateTime('today -30 days'))->format('Y-m-d');
}

/* ── WHERE dinàmic + bind ───────────────────────────────── */
$clauses = [];
$params  = [];

if ($from !== '' && $isDateYmd($from)) {
  $clauses[]      = 'ir.started_at >= :from';
  $params[':from'] = $from . ' 00:00:00';
}
if ($to !== '' && $isDateYmd($to)) {
  $clauses[]    = 'ir.started_at <= :to';
  $params[':to'] = $to . ' 23:59:59';
}
if ($status === 'ok' || $status === 'error') {
  $clauses[]     = 'ir.status = :st';
  $params[':st'] = $status;
}
if ($userId !== null) {
  $clauses[]      = 'rd.ID_Usuari = :uid';
  $params[':uid'] = $userId;
}
$where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

/* ── SQL (JOIN Riders per rider_uid, user_id) ───────────── */
$sql = "
  SELECT
    ir.id,
    ir.rider_id,
    rd.Rider_UID,
    rd.ID_Usuari,
    ir.job_uid,
    ir.started_at,
    ir.finished_at,
    ir.status,
    ir.score,
    ir.bytes,
    ir.chars,
    ir.log_path,
    ir.error_msg,
    ir.summary_text,
    ir.details_json
  FROM ia_runs ir
  JOIN Riders rd ON rd.ID_Rider = ir.rider_id
  $where
  ORDER BY ir.started_at ASC, ir.id ASC
";

/* ── Capçaleres HTTP + BOM ─────────────────────────────── */
while (ob_get_level()) { ob_end_clean(); }

$fname = 'ia_runs_export';
if ($from !== '' && $to !== '') {
  $fname = sprintf('ia_runs_%s_%s', $from, $to);
  if ($status === 'ok' || $status === 'error') $fname .= '_' . $status;
}
$fname .= '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $fname . '"');

// BOM per Excel
echo "\xEF\xBB\xBF";

/* ── Execució i streaming CSV ───────────────────────────── */
$out = fopen('php://output', 'w');
fputcsv($out, [
  'run_id','rider_id','rider_uid','user_id','job_uid','started_at','finished_at',
  'status','score','bytes','chars','log_path','error_msg','summary_text','details_json'
]);

$st = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  $type = ($k === ':uid') ? PDO::PARAM_INT : PDO::PARAM_STR;
  $st->bindValue($k, $v, $type);
}
$st->execute();

$rows = 0;
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  fputcsv($out, [
    (int)$row['id'],
    (int)$row['rider_id'],
    (string)$row['Rider_UID'],
    (int)$row['ID_Usuari'],
    (string)$row['job_uid'],
    (string)$row['started_at'],
    (string)$row['finished_at'],
    (string)$row['status'],
    is_null($row['score']) ? '' : (int)$row['score'],
    is_null($row['bytes']) ? '' : (int)$row['bytes'],
    is_null($row['chars']) ? '' : (int)$row['chars'],
    $clean($row['log_path'] ?? ''),
    $clean($row['error_msg'] ?? ''),
    $clean($row['summary_text'] ?? ''),
    $compactJson($row['details_json'] ?? '')
  ]);
  $rows++;
}
fclose($out);

/* ── Auditoria ─────────────────────────────────────────── */
try {
  audit_admin(
    $pdo, (int)$uid, true,
    'ia_export_csv',
    null, null, 'ia_export_csv',
    ['from'=>$from, 'to'=>$to, 'status'=>$status, 'user'=>$userId, 'rows'=>$rows],
    'success'
  );
} catch (Throwable $e) {
  error_log('audit ia_export_csv failed: ' . $e->getMessage());
}
exit;