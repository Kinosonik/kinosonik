<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: text/plain; charset=UTF-8');
echo "DB SANITY CHECK\n==========\n";

// 1) Estat_Segell fora de l’enum esperat
$bad = $pdo->query("
  SELECT ID_Rider, Rider_UID, Estat_Segell
  FROM Riders
  WHERE Estat_Segell NOT IN ('cap','pendent','validat','caducat') OR Estat_Segell IS NULL
")->fetchAll(PDO::FETCH_ASSOC);
echo "Estats invàlids: " . count($bad) . "\n";
foreach ($bad as $r) {
  echo " - RD{$r['ID_Rider']} {$r['Rider_UID']} => '{$r['Estat_Segell']}'\n";
}

// 2) Data_Publicacio incoherent (només ha d’existir si 'validat')
$badPub = $pdo->query("
  SELECT ID_Rider, Estat_Segell, Data_Publicacio
  FROM Riders
  WHERE (Estat_Segell <> 'validat' AND Data_Publicacio IS NOT NULL)
     OR (Estat_Segell = 'validat' AND Data_Publicacio IS NULL)
")->fetchAll(PDO::FETCH_ASSOC);
echo "Publicacions incoherents: " . count($badPub) . "\n";
foreach ($badPub as $r) {
  $dp = $r['Data_Publicacio'] ?? 'NULL';
  echo " - RD{$r['ID_Rider']} segell={$r['Estat_Segell']} Data_Publicacio={$dp}\n";
}

// 3) Caducats amb redirecció cap a un rider NO validat o inexistent
$badRedir = $pdo->query("
  SELECT r.ID_Rider AS Caducat, r.rider_actualitzat, v.Estat_Segell AS Estat_Target
  FROM Riders r
  LEFT JOIN Riders v ON v.ID_Rider = r.rider_actualitzat
  WHERE r.Estat_Segell = 'caducat' AND r.rider_actualitzat IS NOT NULL
    AND (v.ID_Rider IS NULL OR v.Estat_Segell <> 'validat')
")->fetchAll(PDO::FETCH_ASSOC);
echo "Redireccions caducats→no-validat: " . count($badRedir) . "\n";
foreach ($badRedir as $r) {
  echo " - RD{$r['Caducat']} → RD{$r['rider_actualitzat']} (estat target={$r['Estat_Target']})\n";
}

// 4) Redireccions circulars simples A<->B
$loop = $pdo->query("
  SELECT a.ID_Rider A, a.rider_actualitzat A_to, b.ID_Rider B, b.rider_actualitzat B_to
  FROM Riders a
  JOIN Riders b ON b.ID_Rider = a.rider_actualitzat
  WHERE a.rider_actualitzat IS NOT NULL AND b.rider_actualitzat = a.ID_Rider
")->fetchAll(PDO::FETCH_ASSOC);
echo "Redireccions circulars A<->B: " . count($loop) . "\n";
foreach ($loop as $r) {
  echo " - RD{$r['A']} ↔ RD{$r['B']}\n";
}

echo "FI\n";