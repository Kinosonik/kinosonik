<?php
// php/rider_meta.php — Metadades públiques del rider (hash/mida/filename) amb la mateixa política d'accés que rider_file.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';

/* ─────────────────────────────────────────────────────────────
   Mètode i capçalera de resposta JSON
   ───────────────────────────────────────────────────────────── */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isHead = strcasecmp($method, 'HEAD') === 0;

header('Content-Type: application/json; charset=utf-8');

/* ─────────────────────────────────────────────────────────────
   Helpers
   ───────────────────────────────────────────────────────────── */
function json_out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function safe_filename(string $s): string {
  $s = str_replace(["\r","\n"], '', $s);
  $s = preg_replace('/[^A-Za-z0-9.\-_ ]+/', '_', $s);
  return $s !== '' ? $s : 'file.pdf';
}

/* ─────────────────────────────────────────────────────────────
   Garanteix PDO
   ───────────────────────────────────────────────────────────── */
$pdo = null;
if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
  $pdo = $GLOBALS['pdo'];
} elseif (function_exists('db')) {
  $pdo = db();
} elseif (function_exists('get_pdo')) {
  $pdo = get_pdo();
}
if (!$pdo instanceof PDO) {
  json_out(500, ['ok' => false, 'error' => 'db_unavailable']);
}

/* ─────────────────────────────────────────────────────────────
   Input
   ───────────────────────────────────────────────────────────── */
$ref = isset($_GET['ref']) ? trim((string)$_GET['ref']) : '';
if ($ref === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $ref)) {
  json_out(400, ['ok' => false, 'error' => 'bad_ref']);
}

/* ─────────────────────────────────────────────────────────────
   Consulta rider (incloem dates per a Last-Modified)
   ───────────────────────────────────────────────────────────── */
$sql = "
  SELECT r.ID_Rider, r.ID_Usuari, r.Rider_UID, r.Nom_Arxiu,
         r.Mida_Bytes, r.Hash_SHA256, r.Estat_Segell,
         r.Data_Publicacio, r.Data_Pujada
    FROM Riders r
   WHERE r.Rider_UID = :ref
   LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([':ref' => $ref]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  json_out(404, ['ok' => false, 'error' => 'rider_not_found']);
}

/* ─────────────────────────────────────────────────────────────
   Permisos (paritat amb rider_file.php)
   - Públic: només si segell = validat
   - Propietari i admin: sempre
   ───────────────────────────────────────────────────────────── */
$ownerId     = (int)$row['ID_Usuari'];
$me          = (int)($_SESSION['user_id'] ?? 0);
$tipus       = (string)($_SESSION['tipus_usuari'] ?? '');
$isAdmin     = strcasecmp($tipus, 'admin') === 0;
$isOwner     = ($me === $ownerId);
$sealState   = strtolower((string)($row['Estat_Segell'] ?? ''));
$isValidated = ($sealState === 'validat');

$allowed = $isOwner || $isAdmin || $isValidated;

if (!$allowed) {
  // Amaguem existència (mateix tractament “404” que rider_file)
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'");
  header('Referrer-Policy: same-origin');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex, noarchive');
  json_out(404, ['ok' => false, 'error' => 'forbidden']);
}

/* ─────────────────────────────────────────────────────────────
   Hardening & Cache headers (coherents amb rider_file.php)
   + ETag / Last-Modified / 304 condicionals / HEAD
   ───────────────────────────────────────────────────────────── */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'");
header('Referrer-Policy: same-origin');
header('Vary: Cookie');

if ($isValidated) {
  header('Cache-Control: public, max-age=86400'); // 1 dia per metadades de publicats
} else {
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex, noarchive');
}

/* ─────────────────────────────────────────────────────────────
   ETag / Last-Modified
   ───────────────────────────────────────────────────────────── */
$uid      = (string)$row['Rider_UID'];
$bytes    = isset($row['Mida_Bytes']) ? (int)$row['Mida_Bytes'] : 0;
$hash     = isset($row['Hash_SHA256']) && $row['Hash_SHA256'] !== '' ? (string)$row['Hash_SHA256'] : '';
$filename = safe_filename((string)($row['Nom_Arxiu'] ?: ('rider-'.$uid.'.pdf')));

$etagCore = $uid . ($hash !== '' ? ('-' . $hash) : '') . ($bytes > 0 ? ('-' . $bytes) : '');
$etag = ($hash !== '' ? '"' . $etagCore . '"' : 'W/"' . $etagCore . '"');
header('ETag: ' . $etag);

// Last-Modified: Data_Publicacio o, si no hi és, Data_Pujada
$lastMod = null;
$dtSrc = (string)($row['Data_Publicacio'] ?: $row['Data_Pujada'] ?: '');
if ($dtSrc !== '' && $dtSrc !== '0000-00-00 00:00:00') {
  $ts = strtotime($dtSrc);
  if ($ts !== false) {
    $lastMod = gmdate('D, d M Y H:i:s', $ts) . ' GMT';
    header('Last-Modified: ' . $lastMod);
  }
}

/* ─────────────────────────────────────────────────────────────
   Condicionals (304) i HEAD
   ───────────────────────────────────────────────────────────── */
$inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
$notModified = false;

if ($inm !== '') {
  foreach (array_map('trim', explode(',', $inm)) as $tag) {
    if ($tag === '*' || $tag === $etag) { $notModified = true; break; }
  }
} elseif ($ims !== '' && $lastMod !== null) {
  $imsTs = strtotime($ims);
  $lmTs  = strtotime($lastMod);
  if ($imsTs !== false && $lmTs !== false && $imsTs >= $lmTs) {
    $notModified = true;
  }
}

if ($notModified) {
  http_response_code(304);
  exit;
}
if ($isHead) {
  // HEAD 200 sense cos
  http_response_code(200);
  exit;
}

/* ─────────────────────────────────────────────────────────────
   Resposta JSON
   ───────────────────────────────────────────────────────────── */
$bytesOut = isset($row['Mida_Bytes']) ? (int)$row['Mida_Bytes'] : null;
$hashOut  = $hash !== '' ? $hash : null;

json_out(200, [
  'ok'       => true,
  'id'       => (int)$row['ID_Rider'],
  'uid'      => $uid,
  'seal'     => $sealState,     // cap | pendent | validat | caducat
  'bytes'    => $bytesOut,
  'sha256'   => $hashOut,
  'filename' => $filename,
]);