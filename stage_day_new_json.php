<?php
// stage_day_new_json.php — API per crear un nou dia d'escenari (AJAX)
// Retorna JSON: {status:"ok", id:int} o {status:"error", msg:string}

declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

header('Content-Type: application/json; charset=UTF-8');
ks_require_role('productor','admin');

function jfail(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['status' => 'error', 'msg' => $msg]);
  exit;
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

// ── Validació bàsica ───────────────────────────────────────────────────
$stageId = (int)($_POST['stage_id'] ?? 0);
if ($stageId <= 0) jfail('Identificador d\'escenari invàlid.');

// ── Validació de propietari ─────────────────────────────────────────────
$sql = <<<SQL
SELECT s.id, s.nom, s.data_inici, s.data_fi,
       e.id AS event_id, e.nom AS event_nom, e.owner_user_id,
       e.is_open_ended, e.data_inici AS event_inici, e.data_fi AS event_fi
FROM Event_Stages s
JOIN Events e ON e.id = s.event_id
WHERE s.id = :sid
SQL;
$st = $pdo->prepare($sql);
$st->execute([':sid' => $stageId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctx) jfail('Escenari inexistent.', 404);
if (!$isAdmin && (int)$ctx['owner_user_id'] !== $uid) jfail('No autoritzat.', 403);

// ── Camps i validacions ────────────────────────────────────────────────
$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) jfail('CSRF invàlid.', 403);

$dia = trim((string)($_POST['dia'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) jfail('Format de data invàlid.');

// ── Fronteres de dates ─────────────────────────────────────────────────
function toYmd(?string $s): ?string {
  if (!$s) return null;
  $t = strtotime($s);
  return $t ? date('Y-m-d', $t) : null;
}

$starts = array_filter([toYmd($ctx['event_inici']), toYmd($ctx['data_inici'])]);
$minDate = $starts ? max($starts) : null;

$evEnd = toYmd($ctx['event_fi']);
$stEnd = toYmd($ctx['data_fi']);
$maxDate = null;
if ($evEnd && $stEnd)      $maxDate = min($evEnd, $stEnd);
elseif ($evEnd && !$stEnd) $maxDate = $evEnd;
elseif (!$evEnd && $stEnd) $maxDate = $stEnd;

if ($minDate && $dia < $minDate)
  jfail('La data seleccionada és anterior al període permès de l\'esdeveniment o de l\'escenari.');
if ($maxDate && $dia > $maxDate)
  jfail('La data seleccionada és posterior al període permès de l\'esdeveniment o de l\'escenari.');

// ── Duplicats ─────────────────────────────────────────────────────────
$chk = $pdo->prepare('SELECT id FROM Stage_Days WHERE stage_id=:sid AND dia=:d LIMIT 1');
$chk->execute([':sid' => $stageId, ':d' => $dia]);
if ($chk->fetch())
  jfail('Aquesta data ja existeix dins l\'escenari.');

// ── Inserció ───────────────────────────────────────────────────────────
try {
  $ins = $pdo->prepare('INSERT INTO Stage_Days (stage_id, dia) VALUES (:sid, :d)');
  $ins->execute([':sid' => $stageId, ':d' => $dia]);
  echo json_encode(['status' => 'ok', 'id' => (int)$pdo->lastInsertId()]);
  exit;
} catch (Throwable $e) {
  jfail('S\'ha produït un error en desar el dia. Torna-ho a provar més tard.', 500);
}
