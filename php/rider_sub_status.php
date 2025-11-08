<?php
// Retorna si l'usuari actual està subscrit a un rider concret
declare(strict_types=1);
require_once __DIR__ . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/middleware.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  echo json_encode(['ok' => false, 'subscribed' => false]);
  exit;
}

$riderId = (int)($_GET['rider_id'] ?? 0);
if ($riderId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'bad_request']);
  exit;
}

$pdo = db();
$sth = $pdo->prepare("SELECT 1 FROM Rider_Subscriptions WHERE Usuari_ID=:u AND Rider_ID=:r AND active=1 LIMIT 1");
$sth->execute([':u' => $userId, ':r' => $riderId]);
$isSub = (bool)$sth->fetchColumn();

echo json_encode(['ok' => true, 'subscribed' => $isSub]);
