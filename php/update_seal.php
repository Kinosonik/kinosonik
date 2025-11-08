<?php
// php/update_seal.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rider_notify.php'; // notificacions subscripcions

$pdo = db();

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
csrf_check_or_die();

function jexit(int $http, array $payload): never {
  http_response_code($http);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function is_uuid(string $s): bool {
  return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $s);
}

$rider_uid = (string)($_POST['rider_uid'] ?? '');
$estat     = (string)($_POST['estat'] ?? '');
$redir_to       = array_key_exists('redir_to', $_POST) ? trim((string)$_POST['redir_to']) : null;
$redirProvided  = ($redir_to !== null);

// UUID v1–v5 (coherent amb la resta)
if (!is_uuid($rider_uid)) {
  jexit(400, ['ok' => false, 'error' => 'bad_uid']);
}

$allowed = ['cap','pendent','validat','caducat'];
$estat   = strtolower(trim($estat));
if (!in_array($estat, $allowed, true)) {
  jexit(400, ['ok' => false, 'error' => 'bad_state']);
}
$sendSealExpired      = false; // validat -> caducat
$sendRedirectChanged  = false; // canvi de redirect en caducat

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("
    SELECT r.ID_Rider, r.ID_Usuari, r.Estat_Segell, r.Data_Publicacio, r.rider_actualitzat,
           r.Hash_SHA256, r.Mida_Bytes
      FROM Riders r
     WHERE r.Rider_UID = :uid
     LIMIT 1
     FOR UPDATE
  ");
  $st->execute([':uid' => $rider_uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $pdo->rollBack();
    jexit(404, ['ok' => false, 'error' => 'not_found']);
  }

  $idRider     = (int)$row['ID_Rider'];
  $ownerId     = (int)$row['ID_Usuari'];
  $estatActual = strtolower(trim((string)($row['Estat_Segell'] ?? '')));
  $pubDateCurr = (string)($row['Data_Publicacio'] ?? '');
  $pubIsZero   = ($pubDateCurr === '0000-00-00 00:00:00');
  $pubDateCurrNorm = ($pubDateCurr === '' || $pubIsZero) ? '' : $pubDateCurr;
  $redirCurr   = isset($row['rider_actualitzat']) ? (int)$row['rider_actualitzat'] : 0;

  $tipus   = (string)($_SESSION['tipus_usuari'] ?? '');
  $isAdmin = (strcasecmp($tipus, 'admin') === 0);
  $isOwner = ($ownerId === (int)($_SESSION['user_id'] ?? 0));

  // Permisos
  // - Admin pot tot
  // - Propietari només pot VALIDAT -> CADUCAT (i opcionalment establir redirecció)
  if (!$isAdmin) {
    $propPermes = ($isOwner && $estatActual === 'validat' && $estat === 'caducat');
    if (!$propPermes) {
      $pdo->rollBack();
      jexit(403, ['ok' => false, 'error' => 'forbidden']);
    }
  }

  // Si validem, requerim hash i mida > 0 (per evitar error trigger BD)
  if ($estat === 'validat') {
    $shaCurr   = (string)($row['Hash_SHA256'] ?? '');
    $bytesCurr = (int)($row['Mida_Bytes'] ?? 0);
    if ($shaCurr === '' || strlen($shaCurr) !== 64) {
      $pdo->rollBack();
      jexit(422, ['ok' => false, 'error' => 'hash_required']);
    }
    if ($bytesCurr <= 0) {
      $pdo->rollBack();
      jexit(422, ['ok' => false, 'error' => 'size_required']);
    }
  }

  // Helper: carrega opció de redirecció vàlida (retorna [id, uid]) o [0,'']
  $loadRedirectTarget = function(string $candidate) use ($pdo, $ownerId, $idRider): array {
    if ($candidate === '') return [0, ''];

    $dest = null;
    if (ctype_digit($candidate)) {
      $q = $pdo->prepare("
        SELECT ID_Rider, Rider_UID, Estat_Segell, rider_actualitzat, ID_Usuari
          FROM Riders WHERE ID_Rider = :id LIMIT 1 FOR UPDATE
      ");
      $q->execute([':id' => (int)$candidate]);
      $dest = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif (is_uuid($candidate)) {
      $q = $pdo->prepare("
        SELECT ID_Rider, Rider_UID, Estat_Segell, rider_actualitzat, ID_Usuari
          FROM Riders WHERE Rider_UID = :uid LIMIT 1 FOR UPDATE
      ");
      $q->execute([':uid' => $candidate]);
      $dest = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
      return [0, ''];
    }

    if (!$dest) return [0, ''];

    // Mateix propietari, estat validat, no ell mateix
    if ((int)$dest['ID_Usuari'] !== $ownerId) return [0, ''];
    if (strtolower((string)$dest['Estat_Segell']) !== 'validat') return [0, ''];
    $destId  = (int)$dest['ID_Rider'];
    $destUid = (string)$dest['Rider_UID'];
    if ($destId === $idRider) return [0, '']; // evitar autoredirecció (FK/triggers també ho bloquegen)

    // Evitar cicles: segueix la cadena des de dest fins a 10 salts
    $visited = [];
    $curr = $destId;
    $hops = 0;
    while ($curr && $hops < 10) {
      if ($curr === $idRider) return [0, '']; // crearia bucle
      if (isset($visited[$curr])) return [0, '']; // bucle preexistent
      $visited[$curr] = true;
      $hops++;

      $qq = $pdo->prepare("SELECT rider_actualitzat FROM Riders WHERE ID_Rider = :id LIMIT 1");
      $qq->execute([':id' => $curr]);
      $curr = (int)($qq->fetchColumn() ?: 0);
    }

    return [$destId, $destUid];
  };

  // Calcula Data_Publicacio per canvis d’estat
$setPubDateSql = null; // null => no tocar Data_Publicacio
$bindKeepDate  = false;

if ($estat !== $estatActual) {
  if ($estat === 'validat') {
    $setPubDateSql = 'UTC_TIMESTAMP()';
  } elseif ($estat === 'caducat') {
    if ($pubDateCurrNorm !== '') {
      $setPubDateSql = ':dp_keep';
      $bindKeepDate  = true;
    } else {
      $setPubDateSql = 'NULL';
    }
  } else { // cap / pendent
    $setPubDateSql = 'NULL';
  }
}

  // 1) UPDATE d’estat (si canvia)
  if ($estat !== $estatActual) {
    // construeix el SET de forma robusta (sense comes sobreres)
    $setParts = ["Estat_Segell = :estat"];
    $params   = [':estat' => $estat, ':id' => $idRider];
    if ($setPubDateSql !== null) {
      if ($bindKeepDate) {
        $setParts[] = "Data_Publicacio = :dp_keep";
        $params[':dp_keep'] = $pubDateCurrNorm;
      } else {
        // expressions com UTC_TIMESTAMP() o NULL van literals
        $setParts[] = "Data_Publicacio = {$setPubDateSql}";
      }
    }
    $sql = "UPDATE Riders SET " . implode(', ', $setParts) . " WHERE ID_Rider = :id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Marquem si hi ha transició VALIDAT -> CADUCAT
    if ($estatActual === 'validat' && $estat === 'caducat') {
      $sendSealExpired = true;
    }

    // Si NO és 'caducat', esborrem qualsevol redirecció
    if ($estat !== 'caducat') {
      $pdo->prepare("UPDATE Riders SET rider_actualitzat = NULL WHERE ID_Rider = :id LIMIT 1")
          ->execute([':id' => $idRider]);
      $redirCurr = 0;
    }

  }

// 2) Redirecció: només si l’estat final és caducat i el camp s’ha enviat
$redirUid = '';
$redirCurrBefore = $redirCurr; // valor anterior de rider_actualitzat

if ($estat === 'caducat' && $redirProvided) {
  if ($redir_to !== '') {
    [$destId, $destUid] = $loadRedirectTarget($redir_to);
    if ($destId === 0) {
      $pdo->rollBack();
      jexit(422, ['ok' => false, 'error' => 'bad_redirect_target']);
    }
    $pdo->prepare("UPDATE Riders SET rider_actualitzat = :to WHERE ID_Rider = :id LIMIT 1")
        ->execute([':to' => $destId, ':id' => $idRider]);
    $redirCurr = $destId;
    $redirUid  = $destUid;
  } else {
    // buidar redirecció explícitament
    $pdo->prepare("UPDATE Riders SET rider_actualitzat = NULL WHERE ID_Rider = :id LIMIT 1")
        ->execute([':id' => $idRider]);
    $redirCurr = 0;
  }

  // Si el redirect ha canviat (inclòs passar de tenir-lo a NULL), marquem notificació
  if ($redirCurrBefore !== $redirCurr) {
    $sendRedirectChanged = true;
  }
}


  // Rellegim Data_Publicacio final des de BD (exactitud)
  $qPub = $pdo->prepare("SELECT Data_Publicacio FROM Riders WHERE ID_Rider = :id LIMIT 1");
  $qPub->execute([':id' => $idRider]);
  $pubDateNew = (string)($qPub->fetchColumn() ?: '');

  // Opcions de redirecció (altres VALIDATS del mateix usuari)
  $opts = [];
  $sel = $pdo->prepare("
    SELECT ID_Rider, Descripcio, Nom_Arxiu, Data_Publicacio
      FROM Riders
     WHERE ID_Usuari = :uid
       AND Estat_Segell = 'validat'
       AND ID_Rider <> :curr
     ORDER BY Data_Publicacio DESC, ID_Rider DESC
     LIMIT 100
  ");
  $sel->execute([':uid' => $ownerId, ':curr' => $idRider]);
  while ($r2 = $sel->fetch(PDO::FETCH_ASSOC)) {
    $id   = (int)$r2['ID_Rider'];
    $desc = trim((string)($r2['Descripcio'] ?? ''));
    $nom  = (string)($r2['Nom_Arxiu'] ?? '');
    $when = (string)($r2['Data_Publicacio'] ?? '');
    $text = 'RD'.$id.' — ' . ($desc !== '' ? $desc : $nom);
    if ($when !== '') $text .= ' ('.substr($when,0,10).')';
    $opts[] = ['id' => $id, 'label' => $text, 'desc' => $text];
  }

  // Si hi ha redirecció seleccionada i no hem emplenat $redirUid encara, la busquem
  if ($redirCurr > 0 && $redirUid === '') {
    $q = $pdo->prepare("SELECT Rider_UID FROM Riders WHERE ID_Rider = :id LIMIT 1");
    $q->execute([':id' => $redirCurr]);
    $redirUid = (string)($q->fetchColumn() ?: '');
  }

    // AUDIT
  audit_admin(
    $pdo,
    (int)($_SESSION['user_id'] ?? 0),
    $isAdmin,
    'update_seal',
    $idRider,
    $rider_uid,
    ($isAdmin ? 'admin_riders' : 'riders'),
    [
      'from' => $estatActual,
      'to'   => $estat,
      'redirect_to' => $redirCurr ?: null
    ],
    'success',
    null
  );

  // Tanquem transacció abans de notificar
  $pdo->commit();

  // Notificacions best-effort (no bloquegen la resposta)
  try {
    if ($sendSealExpired) {
      ks_notify_rider_subscribers($pdo, $idRider, 'seal_expired');
    }
    if ($sendRedirectChanged) {
      ks_notify_rider_subscribers($pdo, $idRider, 'redirect_changed');
    }
  } catch (Throwable $ne) {
    error_log('update_seal notify error: ' . $ne->getMessage());
  }

  jexit(200, [
    'ok'   => true,
    'data' => [
      'estat'             => $estat,
      'data_publicacio'   => ($pubDateNew !== '' ? $pubDateNew : null),
      'redirect_options'  => $opts,
      'redirect_selected' => $redirCurr ?: 0,
      'redirect_uid'      => $redirUid,
    ],
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('update_seal.php error: ' . $e->getMessage());
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'update_seal',
      isset($idRider) ? (int)$idRider : null,
      (string)($rider_uid ?? ''),
      (isset($isAdmin) && $isAdmin) ? 'admin_riders' : 'riders',
      ['from' => $estatActual ?? null, 'to' => $estat ?? null],
      'error',
      substr($e->getMessage(), 0, 250)
    );
  } catch (Throwable $ae) {
    error_log('audit update_seal error failed: ' . $ae->getMessage());
  }
  jexit(500, ['ok' => false, 'error' => 'server_error']);
}