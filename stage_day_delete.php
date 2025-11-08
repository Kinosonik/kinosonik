<?php
// stage_day_delete.php — Esborra un dia d’escenari i totes les seves dades dependents
// Retorna JSON {ok:true} o {ok:false, error:"..."}

declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

header('Content-Type: application/json; charset=UTF-8');
ks_require_role('productor','admin');

function jfail(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

// ─── Validacions bàsiques ────────────────────────────────────────────────
$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) jfail('CSRF invàlid.', 403);

$dayId = (int)($_POST['id'] ?? 0);
if ($dayId <= 0) jfail('Identificador de dia invàlid.', 400);

// ─── Context i autorització ──────────────────────────────────────────────
$sqlCtx = <<<SQL
SELECT d.id AS day_id, d.stage_id, d.dia,
       s.event_id, e.owner_user_id
FROM Stage_Days d
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e ON e.id = s.event_id
WHERE d.id = :id
SQL;
$st = $pdo->prepare($sqlCtx);
$st->execute([':id' => $dayId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctx) jfail('Dia inexistent.', 404);
if (!$isAdmin && (int)$ctx['owner_user_id'] !== $uid) jfail('No autoritzat.', 403);

// ─── Esborrat ───────────────────────────────────────────────────────────
try {
  $pdo->beginTransaction();

  // 1) Elimina IA runs relacionats
  $pdo->prepare('DELETE FROM ia_runs 
                 WHERE id IN (
                   SELECT ia_precheck_run_id 
                   FROM Stage_Day_Acts 
                   WHERE stage_day_id = :did
                 )')->execute([':did' => $dayId]);

  // 2) Elimina actuacions del dia
  $pdo->prepare('DELETE FROM Stage_Day_Acts WHERE stage_day_id = :did')->execute([':did' => $dayId]);

  // 3) Elimina el dia
  $pdo->prepare('DELETE FROM Stage_Days WHERE id = :did')->execute([':did' => $dayId]);

  $pdo->commit();
  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('Error en eliminar el dia: '.$e->getMessage(), 500);
}
