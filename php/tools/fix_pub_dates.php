<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "Riders — Fix coherència Data_Publicacio i redireccions\n";
echo "======================================================\n\n";

try {
  // 1) 'validat' sense data → posa NOW() (respectem la primera execució)
  $sql1 = "
    UPDATE Riders
       SET Data_Publicacio = NOW()
     WHERE Estat_Segell = 'validat'
       AND (Data_Publicacio IS NULL OR Data_Publicacio = '0000-00-00 00:00:00')
  ";
  $n1 = $pdo->exec($sql1);
  echo "[1] Validats sense Data_Publicacio → fixats: " . (int)$n1 . "\n";

  // 2) No 'validat' amb data → posa NULL
  $sql2 = "
    UPDATE Riders
       SET Data_Publicacio = NULL
     WHERE Estat_Segell <> 'validat'
       AND Data_Publicacio IS NOT NULL
  ";
  $n2 = $pdo->exec($sql2);
  echo "[2] No-validats amb Data_Publicacio → netejats: " . (int)$n2 . "\n";

  // 3) Si NO caducat però té rider_actualitzat → neteja
  $sql3 = "
    UPDATE Riders
       SET rider_actualitzat = NULL
     WHERE Estat_Segell <> 'caducat'
       AND rider_actualitzat IS NOT NULL
  ";
  $n3 = $pdo->exec($sql3);
  echo "[3] Redireccions inconsistents (no caducat) → netejades: " . (int)$n3 . "\n";

  // 4) (Opcional) Redireccions que apunten a riders NO validats → neteja
  $sql4 = "
    UPDATE Riders r
    LEFT JOIN Riders t ON t.ID_Rider = r.rider_actualitzat
       SET r.rider_actualitzat = NULL
     WHERE r.Estat_Segell = 'caducat'
       AND r.rider_actualitzat IS NOT NULL
       AND (t.ID_Rider IS NULL OR t.Estat_Segell <> 'validat')
  ";
  $n4 = $pdo->exec($sql4);
  echo "[4] Redireccions cap a no-validats o inexistents → netejades: " . (int)$n4 . "\n";

  echo "\nFI OK\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "\nERROR: " . $e->getMessage() . "\n";
}