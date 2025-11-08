<?php
// php/admin_update_user_type.php — Canviar Tipus_Usuari via AJAX (només ADMIN)
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/middleware.php';

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

$pdo = db();

// ─────────────────────────── helpers JSON
function json_out(array $payload, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// ─────────────────────────── seguretat bàsica
if (empty($_SESSION['user_id'])) {
  json_out(['ok' => false, 'error' => 'auth_required'], 401);
}

// Confirmem que l’usuari actual és ADMIN (via sessió + DB)
$currentUserId = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$st->execute([$currentUserId]);
$tipusSessio = (string)($st->fetchColumn() ?: '');
if (strcasecmp($tipusSessio, 'admin') !== 0) {
  json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

// ─────────────────────────── inputs
$userId  = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0; // target
$newType = strtolower(trim((string)($_POST['tipus'] ?? '')));

$allowed = ['tecnic','productor','sala','admin'];
if ($userId <= 0 || !in_array($newType, $allowed, true)) {
  json_out(['ok' => false, 'error' => 'invalid_params'], 400);
}

// ─────────────────────────── llegim l’usuari destí
$su = $pdo->prepare("SELECT ID_Usuari, Tipus_Usuari FROM Usuaris WHERE ID_Usuari = :id LIMIT 1");
$su->execute([':id' => $userId]);
$target = $su->fetch(PDO::FETCH_ASSOC);

if (!$target) {
  json_out(['ok' => false, 'error' => 'user_not_found'], 404);
}

$currentType = strtolower((string)$target['Tipus_Usuari']);

// Si no hi ha canvi, OK silenciós
if ($currentType === $newType) {
  json_out(['ok' => true, 'data' => ['unchanged' => true, 'tipus' => $currentType]]);
}

// ─────────────────────────── protecció: no deixar el sistema sense admins
if ($currentType === 'admin' && $newType !== 'admin') {
  // Quants admins *altres* que el target hi ha?
  $sc = $pdo->prepare("SELECT COUNT(*) FROM Usuaris WHERE LOWER(Tipus_Usuari) = 'admin' AND ID_Usuari <> :id");
  $sc->execute([':id' => $userId]);
  $otherAdmins = (int)$sc->fetchColumn();

  // Si no n’hi ha cap més, no permetre la degradació
  if ($otherAdmins <= 0) {
    json_out(['ok' => false, 'error' => 'last_admin_cannot_be_downgraded'], 409);
  }
}

// ─────────────────────────── actualització
try {
  $up = $pdo->prepare("UPDATE Usuaris SET Tipus_Usuari = :t WHERE ID_Usuari = :id LIMIT 1");
  $up->execute([':t' => $newType, ':id' => $userId]);

  json_out([
    'ok'   => true,
    'data' => [
      'user_id'      => $userId,
      'old_type'     => $currentType,
      'new_type'     => $newType,
      'is_self_edit' => ($userId === $currentUserId),
    ]
  ]);
} catch (Throwable $e) {
  error_log('admin_update_user_type.php error: ' . $e->getMessage());
  json_out(['ok' => false, 'error' => 'db_error'], 500);
}