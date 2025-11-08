<?php
// act_reorder.php — Actualitza l’ordre d’una actuació dins del dia
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

header('Content-Type: application/json; charset=UTF-8');
ks_require_role('productor','admin');

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

$id = (int)($_POST['id'] ?? 0);
$newOrdre = (int)($_POST['ordre'] ?? 0);
$csrf = (string)($_POST['csrf'] ?? '');

if ($id <= 0 || $newOrdre <= 0) {
  echo json_encode(['ok'=>false,'error'=>'params_invalid']); exit;
}
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
  echo json_encode(['ok'=>false,'error'=>'csrf_invalid']); exit;
}

// Carrega actuació + autorització
$sql = <<<SQL
SELECT a.id, a.stage_day_id, a.ordre, e.owner_user_id
FROM Stage_Day_Acts a
JOIN Stage_Days d ON d.id = a.stage_day_id
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e ON e.id = s.event_id
WHERE a.id = :id
SQL;
$st = $pdo->prepare($sql);
$st->execute([':id'=>$id]);
$act = $st->fetch(PDO::FETCH_ASSOC);
if (!$act) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
if (!$isAdmin && (int)$act['owner_user_id'] !== $uid) { echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

$dayId = (int)$act['stage_day_id'];
$oldOrdre = (int)$act['ordre'];

if ($newOrdre === $oldOrdre) {
  echo json_encode(['ok'=>true,'msg'=>'same_order']); exit;
}

// Reindexa
try {
  $pdo->beginTransaction();

  // Fase 1: desplaça tots els ordres existents temporalment
  $pdo->prepare('UPDATE Stage_Day_Acts SET ordre = ordre + 1000 WHERE stage_day_id = :did')
      ->execute([':did' => $dayId]);

  // Fase 2: obté l’ordre actualitzat, reindexa i aplica el nou ordre
  $acts = $pdo->prepare('SELECT id FROM Stage_Day_Acts WHERE stage_day_id = :did ORDER BY ordre');
  $acts->execute([':did'=>$dayId]);
  $ids = $acts->fetchAll(PDO::FETCH_COLUMN);

  $ids = array_values($ids);
  $idx = array_search($id, $ids, true);
  if ($idx === false) throw new RuntimeException('not_in_day');

  array_splice($ids, $idx, 1); // treu l’element actual
  array_splice($ids, $newOrdre - 1, 0, [$id]); // insereix a nova posició

  $upd = $pdo->prepare('UPDATE Stage_Day_Acts SET ordre = :o WHERE id = :id');
  foreach ($ids as $i => $actId) {
    $upd->execute([':o' => $i + 1, ':id' => $actId]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
