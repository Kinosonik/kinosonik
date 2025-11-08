<?php
// Afegeix o elimina la subscripció d’un rider (toggle)
declare(strict_types=1);
require_once __DIR__ . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/middleware.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
$csrf   = $_POST['csrf'] ?? '';
if ($userId <= 0 || $csrf !== ($_SESSION['csrf'] ?? '')) {
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

$riderId = (int)($_POST['rider_id'] ?? 0);
if ($riderId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'bad_request']);
  exit;
}

$pdo = db();

// Evita subscriure's al propi rider
$ownChk = $pdo->prepare("SELECT ID_Usuari FROM Riders WHERE ID_Rider=:r LIMIT 1");
$ownChk->execute([':r' => $riderId]);
$owner = (int)($ownChk->fetchColumn() ?: 0);
if ($owner === $userId) {
  echo json_encode(['ok' => false, 'error' => 'own_rider']);
  exit;
}

// Comprova si ja existeix
$sth = $pdo->prepare("SELECT active FROM Rider_Subscriptions WHERE Rider_ID=:r AND Usuari_ID=:u LIMIT 1");
$sth->execute([':r' => $riderId, ':u' => $userId]);
$row = $sth->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  $ins = $pdo->prepare("INSERT INTO Rider_Subscriptions (Rider_ID, Usuari_ID, active) VALUES (:r,:u,1)");
  $ins->execute([':r' => $riderId, ':u' => $userId]);
  echo json_encode(['ok' => true, 'subscribed' => true]);
  exit;
}

// Toggle
$newState = (int)!((int)$row['active']);
$upd = $pdo->prepare("UPDATE Rider_Subscriptions SET active=:a WHERE Rider_ID=:r AND Usuari_ID=:u");
$upd->execute([':a' => $newState, ':r' => $riderId, ':u' => $userId]);

echo json_encode(['ok' => true, 'subscribed' => (bool)$newState]);
