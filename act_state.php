<?php
// act_state.php — Canvis d'estat i neteja de flags (AJAX/POST JSON)
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

header('Content-Type: application/json; charset=UTF-8');
ks_require_role('productor','admin');

function jfail(string $m, int $code=400): never {
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jfail('method_not_allowed',405);
$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) jfail('csrf_invalid',403);

$actId  = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if ($actId<=0 || $action==='') jfail('bad_request',400);

// Autorització per propietari
$sqlAuth = <<<SQL
SELECT e.owner_user_id
FROM Stage_Day_Acts a
JOIN Stage_Days d   ON d.id=a.stage_day_id
JOIN Event_Stages s ON s.id=d.stage_id
JOIN Events e       ON e.id=s.event_id
WHERE a.id=:id
SQL;
$st = $pdo->prepare($sqlAuth); $st->execute([':id'=>$actId]);
$owner = $st->fetchColumn();
if ($owner===false) jfail('not_found',404);
if (!$isAdmin && (int)$owner !== $uid) jfail('forbidden',403);

try {
  if ($action==='set_state') {
    $state = (string)($_POST['state'] ?? '');
    $allowed = ['rider_rebut','contra_enviat','esperant_resposta','comentat','acord_tancat','final_publicat','final_reobert'];
    if (!in_array($state,$allowed,true)) jfail('invalid_state',422);

    $u = $pdo->prepare('UPDATE Stage_Day_Acts SET negotiation_state=:st, ts_updated=NOW() WHERE id=:id');
    $u->execute([':st'=>$state, ':id'=>$actId]);

    // Registre al timeline si existeix taula Negotiation_Rounds (opcional)
    try {
      $pdo->query("SELECT 1 FROM Negotiation_Rounds LIMIT 1");
      $nr = $pdo->prepare("INSERT INTO Negotiation_Rounds (act_id, tipus, by_user, ts, payload_json)
                           VALUES (:aid, :tipus, :uid, NOW(), :payload)");
      $nr->execute([
        ':aid'=>$actId,
        ':tipus'=>'estat_canviat',
        ':uid'=>$uid,
        ':payload'=>json_encode(['state'=>$state], JSON_UNESCAPED_UNICODE),
      ]);
    } catch (Throwable $e) { /* opcional */ }

    echo json_encode(['ok'=>true]); exit;
  }

  if ($action==='clear_refresh') {
    $u = $pdo->prepare('UPDATE Stage_Day_Acts SET needs_contrarider_refresh=0, ts_updated=NOW() WHERE id=:id');
    $u->execute([':id'=>$actId]);

    try {
      $pdo->query("SELECT 1 FROM Negotiation_Rounds LIMIT 1");
      $nr = $pdo->prepare("INSERT INTO Negotiation_Rounds (act_id, tipus, by_user, ts, payload_json)
                           VALUES (:aid, 'clear_refresh', :uid, NOW(), '{}')");
      $nr->execute([':aid'=>$actId, ':uid'=>$uid]);
    } catch (Throwable $e) { /* opcional */ }

    echo json_encode(['ok'=>true]); exit;
  }

  jfail('unknown_action',400);
} catch (Throwable $e) {
  jfail('server_error',500);
}
