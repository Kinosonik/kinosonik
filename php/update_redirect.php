<?php
// php/update_redirect.php — Actualitza/neteja la redirecció d’un rider caducat cap a un rider validat del mateix usuari.
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rider_notify.php';

/* ── Només POST + CSRF ───────────────────────────────────── */
if (!is_post()) {
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
  exit;
}
csrf_check_or_die();

/* ── Capçaleres JSON ─────────────────────────────────────── */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$pdo = db();

/* Helpers simples JSON */
function jerr(string $msg, int $code = 400, ?callable $audit = null, array $meta = []): never {
  if ($audit) { $audit('error', $meta + ['reason' => $msg], $msg); }
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function jok(array $data = [], ?callable $audit = null, array $meta = []): never {
  if ($audit) { $audit('success', $meta, null); }
  echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function is_uuid(string $s): bool {
  return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $s);
}

/* Auditoria local */
$aud = function(string $status, array $meta = [], ?string $err = null) use ($pdo) {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'rider_redirect_update',
      isset($meta['src_id']) ? (int)$meta['src_id'] : null,
      isset($meta['src_uid']) ? (string)$meta['src_uid'] : null,
      'rider_redirect',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit rider_redirect_update failed: ' . $e->getMessage());
  }
};

/* ── Sessió & rol ────────────────────────────────────────── */
$userId  = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) jerr('login_required', 401, $aud);
$isAdmin = (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0);

/* ── Inputs ──────────────────────────────────────────────── */
$riderUid = trim((string)($_POST['rider_uid'] ?? ''));
$toIdRaw  = trim((string)($_POST['redirect_to'] ?? ''));
$toUidRaw = trim((string)($_POST['redirect_to_uid'] ?? '')); // opcional: permetre UID de destí

if ($riderUid === '' || !is_uuid($riderUid)) {
  jerr('invalid_uid', 422, $aud, ['src_uid'=>$riderUid]);
}

// Normalitza destí: primer intentem ID; si no hi és però hi ha UID, l’acceptem.
$toId = null;
if ($toIdRaw !== '') {
  if (ctype_digit($toIdRaw)) {
    $toId = (int)$toIdRaw;
    if ($toId === 0) $toId = null;
  } else {
    jerr('invalid_destination_id', 422, $aud, ['src_uid'=>$riderUid, 'to_id_raw'=>$toIdRaw]);
  }
} elseif ($toUidRaw !== '') {
  if (!is_uuid($toUidRaw)) {
    jerr('invalid_destination_uid', 422, $aud, ['src_uid'=>$riderUid, 'to_uid_raw'=>$toUidRaw]);
  }
}

/* ── Lògica ──────────────────────────────────────────────── */
try {
  $pdo->beginTransaction();

  /* Origen (bloquejat) */
  $st = $pdo->prepare("
    SELECT r.ID_Rider, r.ID_Usuari, r.Estat_Segell, r.rider_actualitzat
      FROM Riders r
     WHERE r.Rider_UID = :uid
     LIMIT 1
     FOR UPDATE
  ");
  $st->execute([':uid' => $riderUid]);
  $src = $st->fetch(PDO::FETCH_ASSOC);
  if (!$src) {
    $pdo->rollBack();
    jerr('not_found', 404, $aud, ['src_uid'=>$riderUid]);
  }

  $srcId       = (int)$src['ID_Rider'];
  $ownerId     = (int)$src['ID_Usuari'];
  $estatSrc    = strtolower(trim((string)$src['Estat_Segell'] ?? ''));
  $currentToId = isset($src['rider_actualitzat']) ? (int)$src['rider_actualitzat'] : 0;

  if (!($isAdmin || $ownerId === $userId)) {
    $pdo->rollBack();
    jerr('forbidden', 403, $aud, ['src_id'=>$srcId, 'src_uid'=>$riderUid, 'owner_id'=>$ownerId, 'actor_id'=>$userId]);
  }

  /* Només origen caducat */
  if ($estatSrc !== 'caducat') {
    $pdo->rollBack();
    jerr('only_expired_can_redirect', 422, $aud, ['src_id'=>$srcId, 'src_uid'=>$riderUid, 'src_status'=>$estatSrc]);
  }

  /* Si han passat UID de destí, resol-lo a ID (amb lock) */
  $destUid = '';
  if ($toId === null && $toUidRaw !== '') {
    $sd = $pdo->prepare("
      SELECT ID_Rider, Rider_UID, Estat_Segell, rider_actualitzat, ID_Usuari
        FROM Riders
       WHERE Rider_UID = :uid
       LIMIT 1
       FOR UPDATE
    ");
    $sd->execute([':uid' => $toUidRaw]);
    $dst = $sd->fetch(PDO::FETCH_ASSOC);
    if (!$dst) {
      $pdo->rollBack();
      jerr('invalid_destination', 422, $aud, ['src_id'=>$srcId, 'src_uid'=>$riderUid, 'to_uid'=>$toUidRaw]);
    }
    if ((int)$dst['ID_Usuari'] !== $ownerId) {
      $pdo->rollBack();
      jerr('destination_wrong_owner', 422, $aud, ['src_id'=>$srcId, 'to_uid'=>$toUidRaw]);
    }
    $toId    = (int)$dst['ID_Rider'];
    $destUid = (string)$dst['Rider_UID'];
  }

  /* No-op: si no canvia res, sortim OK directament */
  $requestedToId = ($toId !== null) ? $toId : 0;
  if ($requestedToId === $currentToId) {
    // prepara opcions per mantenir la UI coherent
    $optSt = $pdo->prepare("
      SELECT ID_Rider, Descripcio, Nom_Arxiu, Data_Publicacio
        FROM Riders
       WHERE ID_Usuari = :uid
         AND Estat_Segell = 'validat'
         AND (rider_actualitzat IS NULL OR rider_actualitzat = 0)
       ORDER BY Data_Publicacio DESC, ID_Rider DESC
    ");
    $optSt->execute([':uid' => $ownerId]);
    $options = [];
    while ($row = $optSt->fetch(PDO::FETCH_ASSOC)) {
      $vid = (int)$row['ID_Rider'];
      $lab = trim((string)($row['Descripcio'] ?? ''));
      if ($lab === '') $lab = (string)($row['Nom_Arxiu'] ?? '');
      if ($lab === '') $lab = 'RD'.$vid;
      $when = (string)($row['Data_Publicacio'] ?? '');
      $text = $vid . ' — ' . $lab . ($when !== '' ? ' ('.substr($when,0,10).')' : '');
      $options[] = ['id' => $vid, 'label' => $text, 'desc' => $text];
    }
    $currentUid = '';
    if ($currentToId > 0) {
      $qcu = $pdo->prepare("SELECT Rider_UID FROM Riders WHERE ID_Rider = :id LIMIT 1");
      $qcu->execute([':id' => $currentToId]);
      $currentUid = (string)($qcu->fetchColumn() ?: '');
    }
    $pdo->commit();
    $meta = [
      'src_id'   => $srcId,
      'src_uid'  => $riderUid,
      'owner_id' => $ownerId,
      'actor_id' => $userId,
      'is_admin' => $isAdmin,
      'no_op'    => true,
    ];
    jok([
      'redirect_selected' => $currentToId,
      'redirect_uid'      => $currentUid,
      'redirect_options'  => $options
    ], $aud, $meta);
  }

  /* Si demanen establir redirecció */
  if ($toId !== null) {
    if ($toId === $srcId) {
      $pdo->rollBack();
      jerr('self_redirect_not_allowed', 422, $aud, ['src_id'=>$srcId, 'src_uid'=>$riderUid, 'to_id'=>$toId]);
    }

    $sd = $pdo->prepare("
      SELECT ID_Rider, Rider_UID, Estat_Segell, rider_actualitzat, ID_Usuari
        FROM Riders
       WHERE ID_Rider = :id
         AND ID_Usuari = :uid
       LIMIT 1
       FOR UPDATE
    ");
    $sd->execute([':id' => $toId, ':uid' => $ownerId]);
    $dst = $sd->fetch(PDO::FETCH_ASSOC);
    if (!$dst) {
      $pdo->rollBack();
      jerr('invalid_destination', 422, $aud, ['src_id'=>$srcId, 'src_uid'=>$riderUid, 'to_id'=>$toId]);
    }

    $estatD = strtolower(trim((string)$dst['Estat_Segell'] ?? ''));
    if ($estatD !== 'validat') {
      $pdo->rollBack();
      jerr('destination_must_be_validated', 422, $aud, ['src_id'=>$srcId, 'src_uid'=>$riderUid, 'to_id'=>$toId, 'dst_status'=>$estatD]);
    }

    // Evita cadenes: el destí no pot redirigir
    if (!empty($dst['rider_actualitzat'])) {
      $pdo->rollBack();
      jerr('destination_already_redirects', 422, $aud, [
        'src_id'  => $srcId,
        'src_uid' => $riderUid,
        'to_id'   => $toId,
        'dst_redirects_to' => $dst['rider_actualitzat']
      ]);
    }

    // Evita bucles: segueix cadenes fins a 10 salts
    $loopCheckId = (int)$dst['ID_Rider'];
    $hops = 0;
    while ($loopCheckId && $hops < 10) {
      if ($loopCheckId === $srcId) {
        $pdo->rollBack();
        jerr('redirect_cycle_detected', 422, $aud, ['src_id'=>$srcId, 'src_uid'=>$riderUid, 'to_id'=>$toId]);
      }
      $hops++;
      $q = $pdo->prepare("SELECT rider_actualitzat FROM Riders WHERE ID_Rider = :id LIMIT 1");
      $q->execute([':id' => $loopCheckId]);
      $loopCheckId = (int)($q->fetchColumn() ?: 0);
    }

    $destUid = (string)$dst['Rider_UID'];

    // Update a la mateixa transacció
    $up = $pdo->prepare("UPDATE Riders SET rider_actualitzat = :toid WHERE Rider_UID = :uid LIMIT 1");
    $up->execute([':toid' => $toId, ':uid' => $riderUid]);

  } else {
    // Si NO hi ha destí → neteja redirecció
    $up = $pdo->prepare("UPDATE Riders SET rider_actualitzat = NULL WHERE Rider_UID = :uid LIMIT 1");
    $up->execute([':uid' => $riderUid]);
    $destUid = '';
  }

  /* Recarrega opcions per la UI (VALIDATS sense redirecció) */
  $optSt = $pdo->prepare("
    SELECT ID_Rider, Descripcio, Nom_Arxiu, Data_Publicacio
      FROM Riders
     WHERE ID_Usuari = :uid
       AND Estat_Segell = 'validat'
       AND (rider_actualitzat IS NULL OR rider_actualitzat = 0)
     ORDER BY Data_Publicacio DESC, ID_Rider DESC
  ");
  $optSt->execute([':uid' => $ownerId]);

  $options = [];
  while ($row = $optSt->fetch(PDO::FETCH_ASSOC)) {
    $vid = (int)$row['ID_Rider'];
    $lab = trim((string)($row['Descripcio'] ?? ''));
    if ($lab === '') $lab = (string)($row['Nom_Arxiu'] ?? '');
    if ($lab === '') $lab = 'RD'.$vid;
    $when = (string)($row['Data_Publicacio'] ?? '');
    $text = $vid . ' — ' . $lab . ($when !== '' ? ' ('.substr($when,0,10).')' : '');
    $options[] = ['id' => $vid, 'label' => $text, 'desc' => $text];
  }

    $pdo->commit();

  /* Resposta + auditoria d’èxit */
  $meta = [
    'src_id'      => $srcId,
    'src_uid'     => $riderUid,
    'owner_id'    => $ownerId,
    'actor_id'    => $userId,
    'is_admin'    => $isAdmin,
    'cleared'     => ($toId === null),
    'to_id'       => $toId,
    'to_uid'      => $destUid,
  ];

  // Notificació a subscrits: la redirecció ha canviat o s'ha netejat
  try {
    ks_notify_rider_subscribers($pdo, $srcId, 'redirect_changed');
  } catch (Throwable $ne) {
    error_log('update_redirect notify error rider '.$srcId.': '.$ne->getMessage());
  }

  // Si acabem de netejar, el selected és 0 i no hi ha UID. Si hem posat un destí, tornem també el seu UID.
  jok([
    'redirect_selected' => $toId ?? 0,
    'redirect_uid'      => $destUid,
    'redirect_options'  => $options
  ], $aud, $meta);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('update_redirect.php error: ' . $e->getMessage());
  jerr('db_update_error', 500, $aud, ['src_uid'=>$riderUid, 'to_id'=>$toId]);
}