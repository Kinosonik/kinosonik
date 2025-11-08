<?php
// stage_delete.php — Esborra un escenari i la seva informació associada (JSON)
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function jfail(string $m, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE);
  exit;
}

ks_require_role('productor','admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jfail('method_not_allowed', 405);

$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) jfail('csrf_invalid', 403);

$sid = (int)($_POST['id'] ?? 0);
if ($sid <= 0) jfail('bad_request', 400);

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

// ── Carrega l’escenari + propietari de l’event per validar permisos
$st = $pdo->prepare('
  SELECT s.id, s.event_id, e.owner_user_id
  FROM Event_Stages s
  JOIN Events e ON e.id = s.event_id
  WHERE s.id = :id
');
$st->execute([':id' => $sid]);
$stage = $st->fetch(PDO::FETCH_ASSOC);
if (!$stage) jfail('not_found', 404);
if (!$isAdmin && (int)$stage['owner_user_id'] !== $uid) jfail('forbidden', 403);

$eventId = (int)$stage['event_id'];

try {
  $pdo->beginTransaction();

  // ── Recull IDs de Stage_Days
  $qDays = $pdo->prepare('SELECT id FROM Stage_Days WHERE stage_id = :sid');
  $qDays->execute([':sid' => $sid]);
  $dayIds = array_map('intval', $qDays->fetchAll(PDO::FETCH_COLUMN));

  // ── Recull IDs d’Actuacions
  $actIds = [];
  if ($dayIds) {
    $inDays = implode(',', array_fill(0, count($dayIds), '?'));
    $qActs = $pdo->prepare('SELECT id FROM Stage_Day_Acts WHERE stage_day_id IN (' . $inDays . ')');
    $qActs->execute($dayIds);
    $actIds = array_map('intval', $qActs->fetchAll(PDO::FETCH_COLUMN));
  }

  // ── Esborra dependències opcionals (si existeixen)
  if ($actIds) {
    $inActs = implode(',', array_fill(0, count($actIds), '?'));

    // Negotiation_Events
    try {
      $pdo->prepare('DELETE FROM Negotiation_Events WHERE act_id IN (' . $inActs . ')')->execute($actIds);
    } catch (Throwable $e) { /* taula opcional */ }

    // ia_runs de productor (si hi ha camps)
    try {
      // si existeix act_id
      $pdo->prepare("DELETE FROM ia_runs WHERE source='producer_precheck' AND act_id IN (" . $inActs . ")")->execute($actIds);
    } catch (Throwable $e) { /* pot no existir la columna act_id */ }
    try {
      // o si s’usa ref_id
      $pdo->prepare("DELETE FROM ia_runs WHERE source='producer_precheck' AND ref_id IN (" . $inActs . ")")->execute($actIds);
    } catch (Throwable $e) { /* pot no existir la columna ref_id */ }

    // Stage_Day_Acts
    $pdo->prepare('DELETE FROM Stage_Day_Acts WHERE id IN (' . $inActs . ')')->execute($actIds);
  }

  // ── Esborra Stage_Days
  if ($dayIds) {
    $inDays = implode(',', array_fill(0, count($dayIds), '?'));
    $pdo->prepare('DELETE FROM Stage_Days WHERE id IN (' . $inDays . ')')->execute($dayIds);
  }

  // ── Share_Packs (escenari) + recipients (opcionales)
  try {
    $pdo->prepare("
      DELETE FROM Share_Pack_Recipients 
      WHERE pack_id IN (SELECT id FROM Share_Packs WHERE scope='escenari' AND ref_id=:sid)
    ")->execute([':sid' => $sid]);
    $pdo->prepare("DELETE FROM Share_Packs WHERE scope='escenari' AND ref_id=:sid")
        ->execute([':sid' => $sid]);
  } catch (Throwable $e) { /* opcional en alguns despliegues */ }

  // ── Esborra l’escenari
  $pdo->prepare('DELETE FROM Event_Stages WHERE id = :sid')->execute([':sid' => $sid]);

  // ── Toca l’event
  $pdo->prepare('UPDATE Events SET ts_updated = NOW() WHERE id = :eid')->execute([':eid' => $eventId]);

  $pdo->commit();
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  $pdo->rollBack();
  error_log('[stage_delete] ' . $e->getMessage());
  jfail('delete_failed', 500);
}
