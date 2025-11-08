<?php
// delete_event.php — Esborra un esdeveniment i tot el que penja (sense tocar R2)
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

header('Content-Type: application/json; charset=UTF-8');

function jfail(string $code, int $http = 400, ?string $msg = null): never {
  http_response_code($http);
  echo json_encode(['ok' => false, 'error' => $code, 'message' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  ks_require_role('productor','admin'); // si falla, llença

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jfail('method_not_allowed', 405);
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) jfail('csrf_invalid', 403);

  $eventId = (int)($_POST['id'] ?? 0);
  if ($eventId <= 0) jfail('bad_request', 400, 'id_missing');

  $pdo = db();
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

  // Carrega event i comprova propietat
  $st = $pdo->prepare('SELECT id, owner_user_id FROM Events WHERE id=:id');
  $st->execute([':id' => $eventId]);
  $ev = $st->fetch(PDO::FETCH_ASSOC);
  if (!$ev) jfail('not_found', 404);
  if (!$isAdmin && (int)$ev['owner_user_id'] !== $uid) jfail('forbidden', 403);

  $pdo->beginTransaction();

  // 1) Negotiation_Events (si existeix la taula)
  try {
    $pdo->exec("
      DELETE ne FROM Negotiation_Events ne
      JOIN Stage_Day_Acts a   ON ne.act_id = a.id
      JOIN Stage_Days d       ON a.stage_day_id = d.id
      JOIN Event_Stages s     ON d.stage_id = s.id
      WHERE s.event_id = {$eventId}
    ");
  } catch (Throwable $e) {
    // si no existeix la taula, ignorem
  }

  // 2) Share_Pack_Recipients i Share_Packs per l'event (poden no existir)
  try {
    $pdo->exec("
      DELETE r FROM Share_Pack_Recipients r
      JOIN Share_Packs p ON p.id = r.pack_id
      WHERE p.scope IN ('event','produccio') AND p.ref_id = {$eventId}
    ");
    $pdo->exec("
      DELETE p FROM Share_Packs p
      WHERE p.scope IN ('event','produccio') AND p.ref_id = {$eventId}
    ");
  } catch (Throwable $e) {
    // opcional/ignora si no hi són
  }

  // 3) Stage_Day_Acts
  $pdo->exec("
    DELETE a FROM Stage_Day_Acts a
    JOIN Stage_Days d   ON a.stage_day_id = d.id
    JOIN Event_Stages s ON d.stage_id = s.id
    WHERE s.event_id = {$eventId}
  ");

  // 4) Stage_Days
  $pdo->exec("
    DELETE d FROM Stage_Days d
    JOIN Event_Stages s ON d.stage_id = s.id
    WHERE s.event_id = {$eventId}
  ");

  // 5) Stage_Templates (opcional)
  try {
    $pdo->exec("
      DELETE t FROM Stage_Templates t
      JOIN Event_Stages s ON t.stage_id = s.id
      WHERE s.event_id = {$eventId}
    ");
  } catch (Throwable $e) {
    // ignora si no hi és
  }

  // 6) Event_Stages
  $pdo->exec("DELETE s FROM Event_Stages s WHERE s.event_id = {$eventId}");

  // 7) Event
  $del = $pdo->prepare("DELETE FROM Events WHERE id=:id");
  $del->execute([':id' => $eventId]);

  $pdo->commit();
  echo json_encode(['ok' => true, 'deleted_event_id' => $eventId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
  // Log complet per diagnòstic
  error_log('[DELETE_EVENT_FAIL] id='.$_POST['id'].' user='.(int)($_SESSION['user_id']??0).' ip='.($_SERVER['REMOTE_ADDR']??'?').' | '.$e->getMessage());
  jfail('db_error', 500);
}