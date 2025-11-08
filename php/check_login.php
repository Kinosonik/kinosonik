<?php
// check_login.php — protegeix pàgines privades i garanteix dades bàsiques a sessió
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
$pdo = db();

// Si no hi ha login → redirigeix (o 401 per AJAX)
if (empty($_SESSION['loggedin']) || empty($_SESSION['user_id'])) {
  // Si és una crida AJAX/fetch, millor 401
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    http_response_code(401);
    exit;
  }
  header('Location: ' . BASE_PATH . 'index.php?error=login_required');
  exit;
}

// Si no tenim el tipus d'usuari a sessió, el recuperem
// Manté compat: omple tant 'user_type' com 'tipus_usuari'
if (empty($_SESSION['user_type']) && empty($_SESSION['tipus_usuari'])) {
  $stmt = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
  $stmt->execute([ (int)$_SESSION['user_id'] ]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!empty($row['Tipus_Usuari'])) {
    $t = (string)$row['Tipus_Usuari'];
    $_SESSION['user_type']     = $t; // anglès
    $_SESSION['tipus_usuari']  = $t; // català (compat)
  } else {
    // Fallback (no hauria de passar): força logout net
    session_unset();
    session_destroy();
    header('Location: ' . BASE_PATH . 'index.php?error=login_required');
    exit;
  }
}