<?php
// act_delete.php — elimina una actuació d’un dia d’escenari i totes les seves dades associades
// Retorna JSON {ok:true} o {ok:false,error:"..."}

declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

header('Content-Type: application/json; charset=UTF-8');
ks_require_role('productor','admin');

// ─── Helpers ─────────────────────────────────────────────
function jfail(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}
function jok(): never {
  echo json_encode(['ok' => true]);
  exit;
}

// ─── Validacions ─────────────────────────────────────────
$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) jfail('CSRF invàlid.', 403);

$actId = (int)($_POST['id'] ?? 0);
if ($actId <= 0) jfail('Identificador d’actuació invàlid.', 400);

// ─── Context i autorització ──────────────────────────────
$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

$sqlCtx = <<<SQL
SELECT a.id AS act_id, a.stage_day_id, d.stage_id, s.event_id, e.owner_user_id,
       a.rider_orig_doc_id, a.contrarider_doc_id, a.final_doc_id
FROM Stage_Day_Acts a
JOIN Stage_Days d   ON d.id = a.stage_day_id
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e       ON e.id = s.event_id
WHERE a.id = :id
SQL;
$st = $pdo->prepare($sqlCtx);
$st->execute([':id' => $actId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctx) jfail('Actuació inexistent.', 404);
if (!$isAdmin && (int)$ctx['owner_user_id'] !== $uid) jfail('No autoritzat.', 403);

// ─── Esborrat ────────────────────────────────────────────
try {
  $pdo->beginTransaction();

  // 1) Elimina execucions IA vinculades
  $pdo->prepare('DELETE FROM ia_runs WHERE rider_id = :rid')->execute([':rid' => $actId]);

  // 2) Elimina documents associats
  $docs = [];
  foreach (['rider_orig_doc_id','contrarider_doc_id','final_doc_id'] as $c) {
    if (!empty($ctx[$c])) $docs[] = (int)$ctx[$c];
  }
  if ($docs) {
    $in = implode(',', array_fill(0, count($docs), '?'));
    $pdo->prepare("DELETE FROM Documents WHERE id IN ($in)")->execute($docs);
  }

  // 3) Elimina l’actuació
  $pdo->prepare('DELETE FROM Stage_Day_Acts WHERE id = :id')->execute([':id' => $actId]);

  $pdo->commit();
  jok();

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('Error en eliminar l’actuació: '.$e->getMessage(), 500);
}
