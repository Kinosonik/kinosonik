<?php
// php/ks_pdf.php — Detecció i validació del segell Kinosonik Riders
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/**
 * ks_detect_seal — comprova si un PDF conté un segell Kinosonik Riders autèntic
 *
 * Retorna:
 * [
 *   'valid'      => bool,       // Segell trobat i verificat a la base de dades
 *   'rider_id'   => int|null,   // ID Rider trobat
 *   'hash'       => string|null,// Hash SHA256 trobat
 *   'found_text' => string,     // Mostra del text extret (debug)
 *   'reason'     => string      // Explicació curta (ok / no_match / no_hash / no_id / not_found)
 * ]
 */
function ks_detect_seal(string $pdfPath): array {
  if (!is_file($pdfPath) || !filesize($pdfPath)) {
    return ['valid'=>false, 'rider_id'=>null, 'hash'=>null, 'found_text'=>'', 'reason'=>'no_file'];
  }

  // ── 1) Extracció de text (només primera pàgina) ───────────────────────
  $tmpTxt = tempnam(sys_get_temp_dir(), 'pdftext_');
  $cmd = sprintf('pdftotext -f 1 -l 1 -q %s %s 2>/dev/null',
                 escapeshellarg($pdfPath), escapeshellarg($tmpTxt));
  exec($cmd);
  $txt = @file_get_contents($tmpTxt) ?: '';
  @unlink($tmpTxt);

  $seal = [
    'valid' => false,
    'rider_id' => null,
    'hash' => null,
    'found_text' => trim(substr($txt, 0, 400)),
    'reason' => 'not_found'
  ];

  // ── 2) Cerca patrons ──────────────────────────────────────────────────
  if (!preg_match('/Kinosonik\s*Riders/i', $txt)) {
    $seal['reason'] = 'no_marker';
    return $seal;
  }

  if (preg_match('/RID[#:\s]*([0-9]{2,6})/i', $txt, $m)) {
    $seal['rider_id'] = (int)$m[1];
  } else {
    $seal['reason'] = 'no_id';
    return $seal;
  }

  if (preg_match('/SHA256[:\s]*([A-Fa-f0-9]{16,})/i', $txt, $m)) {
    $seal['hash'] = strtolower($m[1]);
  } else {
    $seal['reason'] = 'no_hash';
    return $seal;
  }

  // ── 3) Validació a la base de dades ───────────────────────────────────
  try {
    $pdo = db();
    $q = $pdo->prepare('SELECT id FROM Riders WHERE id=:id AND LOWER(Hash_SHA256)=:h LIMIT 1');
    $q->execute([':id'=>$seal['rider_id'], ':h'=>$seal['hash']]);
    if ($q->fetchColumn()) {
      $seal['valid'] = true;
      $seal['reason'] = 'ok';
    } else {
      $seal['reason'] = 'no_match';
    }
  } catch (Throwable $e) {
    error_log("Seal validation error: " . $e->getMessage());
    $seal['reason'] = 'db_error';
  }

  return $seal;
}
