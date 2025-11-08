<?php
// php/rider_file.php — proxy/redirect cap al PDF d'un rider a R2, amb Range, HEAD i condicionals (ETag/Last-Modified)
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/metrics/rider_views.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/audit.php';
$pdo = db();

/* ─────────────────────────────────────────────────────────────
   Helpers
   ───────────────────────────────────────────────────────────── */
function safe_filename(string $s): string {
  $s = str_replace(["\r","\n"], '', $s);
  $s = preg_replace('/[^A-Za-z0-9.\-_ ]+/', '_', $s);
  return $s !== '' ? $s : 'file.pdf';
}
function audit_pdf(PDO $pdo, string $status, ?int $riderId = null, ?string $riderUid = null, array $meta = [], ?string $err = null): void {
  try {
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0),
      'rider_pdf_access',
      $riderId,
      $riderUid,
      'rider_file',
      $meta,
      $status,
      $err
    );
  } catch (Throwable $e) {
    error_log('audit rider_pdf_access failed: ' . $e->getMessage());
  }
}

/* ─────────────────────────────────────────────────────────────
   Inputs
   ───────────────────────────────────────────────────────────── */
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isHead     = strcasecmp($method, 'HEAD') === 0;
$ref        = isset($_GET['ref']) ? trim((string)$_GET['ref']) : '';
$dl         = isset($_GET['dl']) ? (int)$_GET['dl'] : 0;
$mode       = $dl ? 'download' : 'inline';
$httpRange  = $_SERVER['HTTP_RANGE'] ?? '';               // e.g. "bytes=0-"
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';      // ETag condicional
$ifModified  = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';  // Last-Modified condicional
$ifRange     = $_SERVER['HTTP_IF_RANGE'] ?? '';           // condicional per Range

if ($ref === '' || !preg_match(
  '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
  $ref
)) {
  audit_pdf($pdo, 'error', null, null, ['mode' => $mode], 'bad_ref');
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Bad request.";
  exit;
}

/* ─────────────────────────────────────────────────────────────
   Consulta rider
   ───────────────────────────────────────────────────────────── */
$sql = "
  SELECT r.ID_Rider, r.ID_Usuari, r.Rider_UID, r.Object_Key, r.Nom_Arxiu,
         r.Mida_Bytes, r.Estat_Segell
  FROM Riders r
  WHERE r.Rider_UID = :ref
  LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([':ref' => $ref]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_pdf($pdo, 'error', null, $ref, ['mode' => $mode], 'rider_not_found');
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Rider no trobat.";
  exit;
}

/* ─────────────────────────────────────────────────────────────
   Permisos d’accés
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
  audit_pdf($pdo, 'error', (int)$row['ID_Rider'], (string)$row['Rider_UID'], [
    'mode' => $mode,
    'estat' => (string)$row['Estat_Segell'],
    'is_owner' => $isOwner,
    'is_admin' => $isAdmin,
    'public_allowed' => $isValidated
  ], 'forbidden');
  http_response_code(404); // amaga existència
  header('Content-Type: text/plain; charset=utf-8');
  echo "Rider no disponible.";
  exit;
}

  /* ── Comptador de vistes (no comptem HEAD) ───────────── */
  if (!$isHead) {
    try {
      // logEvent=false (pots posar true si vols escriure també a Rider_Views)
      record_rider_view($pdo, (int)$row['ID_Rider'], true);
    } catch (Throwable $e) { /* silent */ }
  }

/* ─────────────────────────────────────────────────────────────
   Construeix URL pública R2
   ───────────────────────────────────────────────────────────── */
$base = rtrim((string)($_ENV['R2_PUBLIC_BASEURL'] ?? getenv('R2_PUBLIC_BASEURL') ?? ''), '/');
if ($base === '') {
  audit_pdf($pdo, 'error', (int)$row['ID_Rider'], (string)$row['Rider_UID'], ['mode' => $mode], 'r2_public_base_missing');
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "R2_PUBLIC_BASEURL no està configurat al servidor.";
  exit;
}
$key = ltrim((string)$row['Object_Key'], '/');
if ($key === '') {
  audit_pdf($pdo, 'error', (int)$row['ID_Rider'], (string)$row['Rider_UID'], ['mode' => $mode], 'object_key_missing');
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "No s'ha pogut determinar la clau d'objecte del rider.";
  exit;
}
$publicUrl = $base . '/' . $key;

/* ─────────────────────────────────────────────────────────────
   Hardening & Cache headers (abans de cos)
   ───────────────────────────────────────────────────────────── */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'");
header('Vary: Cookie');

// Política de caché: públic si validat; sinó no-store
if ($isValidated) {
  header('Cache-Control: public, max-age=86400');
} else {
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex, noarchive');
}

/* ─────────────────────────────────────────────────────────────
   Si no podem fer stream, redirigim només si és públic (validat)
   ───────────────────────────────────────────────────────────── */
$allowUrlFopen = filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
if (!$allowUrlFopen) {
  if ($isValidated) {
    audit_pdf($pdo, 'success', (int)$row['ID_Rider'], (string)$row['Rider_UID'], [
      'mode' => $mode,
      'via'  => 'redirect',
      'public_allowed' => $isValidated,
      'is_owner' => $isOwner,
      'is_admin' => $isAdmin,
      'object_key_sha1' => sha1($key),
    ]);
    header('Location: ' . $publicUrl, true, 302);
    exit;
  }
  audit_pdf($pdo, 'error', (int)$row['ID_Rider'], (string)$row['Rider_UID'], ['mode' => $mode], 'stream_unavailable');
  http_response_code(502);
  header('Content-Type: text/plain; charset=utf-8');
  echo "No s'ha pogut lliurar el PDF del rider.";
  exit;
}

/* ─────────────────────────────────────────────────────────────
   Construcció de headers cap a l’origen (R2)
   ───────────────────────────────────────────────────────────── */
$httpHeaders = [
  'User-Agent: riders-proxy/1.1',
];

// Range
$rangeAsked = false;
if ($httpRange && preg_match('/^bytes=\d*-\d*(,\d*-\d*)*$/', $httpRange)) {
  $httpHeaders[] = 'Range: ' . $httpRange;
  $rangeAsked = true;
}

// Condicionals
if ($ifNoneMatch !== '')   { $httpHeaders[] = 'If-None-Match: ' . $ifNoneMatch; }
if ($ifModified  !== '')   { $httpHeaders[] = 'If-Modified-Since: ' . $ifModified; }
// If-Range només té sentit si demanem Range
if ($rangeAsked && $ifRange !== '') { $httpHeaders[] = 'If-Range: ' . $ifRange; }

$context = stream_context_create([
  'http' => [
    'method'          => $isHead ? 'HEAD' : 'GET',
    'timeout'         => 30,
    'follow_location' => 1,
    'header'          => implode("\r\n", $httpHeaders),
  ],
  'ssl' => [
    'verify_peer'      => true,
    'verify_peer_name' => true,
  ],
]);

/* ─────────────────────────────────────────────────────────────
   Obrim connexió amb l’origen
   ───────────────────────────────────────────────────────────── */
$in = @fopen($publicUrl, 'rb', false, $context);
if ($in === false) {
  audit_pdf($pdo, 'error', (int)$row['ID_Rider'], (string)$row['Rider_UID'], ['mode' => $mode, 'range' => $httpRange ?: null], 'open_failed');
  http_response_code(502);
  header('Content-Type: text/plain; charset=utf-8');
  echo "No s'ha pogut obrir el PDF del rider.";
  exit;
}

/* Llegeix headers de resposta de l’origen */
$meta = stream_get_meta_data($in);
$respHeaders = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];
$statusLine = '';
foreach ($respHeaders as $h) {
  if (stripos($h, 'HTTP/') === 0) { $statusLine = $h; break; }
}
$originStatus = 200;
if ($statusLine && preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
  $originStatus = (int)$m[1];
}

/* Extraiem alguns headers per propagar-los */
$contentLength = null;
$contentRange  = null;
$etagResp      = null;
$lastModResp   = null;
foreach ($respHeaders as $h) {
  if (stripos($h, 'Content-Length:') === 0) {
    $contentLength = trim(substr($h, 15));
  } elseif (stripos($h, 'Content-Range:') === 0) {
    $contentRange = trim(substr($h, 13));
  } elseif (stripos($h, 'ETag:') === 0) {
    $etagResp = trim(substr($h, 5));
  } elseif (stripos($h, 'Last-Modified:') === 0) {
    $lastModResp = trim(substr($h, 14));
  }
}

/* ─────────────────────────────────────────────────────────────
   Si l’origen respon 304 Not Modified → nosaltres també
   (no enviem cos ni Content-Type/Disposition)
   ───────────────────────────────────────────────────────────── */
if ($originStatus === 304) {
  http_response_code(304);
  if ($etagResp)    header('ETag: ' . $etagResp);
  if ($lastModResp) header('Last-Modified: ' . $lastModResp);

  audit_pdf($pdo, 'success', (int)$row['ID_Rider'], (string)$row['Rider_UID'], [
    'mode' => $mode,
    'via'  => 'stream',
    'seal_state' => $sealState,
    'public_allowed' => $isValidated,
    'is_owner' => $isOwner,
    'is_admin' => $isAdmin,
    'range' => $rangeAsked ? $httpRange : null,
    'result' => 'not_modified',
    'object_key_sha1' => sha1($key),
  ]);
  fclose($in);
  exit;
}

/* ─────────────────────────────────────────────────────────────
   Capçaleres d’entitat (ara que sabem que no és 304)
   ───────────────────────────────────────────────────────────── */
$filename = (string)($row['Nom_Arxiu'] ?: ('rider-'.$row['Rider_UID'].'.pdf'));
$filename = safe_filename($filename);
$dispo    = $dl ? 'attachment' : 'inline';

if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }

header('Content-Type: application/pdf');
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . $dispo . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));

// Propaguem ETag/Last-Modified si l’origen els ha enviat
if ($etagResp)    header('ETag: ' . $etagResp);
if ($lastModResp) header('Last-Modified: ' . $lastModResp);

/* Estableix codi de resposta segons origen (206 si cal) */
if ($originStatus === 206) {
  http_response_code(206);
  if ($contentRange) header('Content-Range: ' . $contentRange);
} else {
  http_response_code(200);
}

/* Content-Length: si l’origen n’envia, el propaguem; si no, fem servir Mida_Bytes quan no hi ha Range */
if ($contentLength !== null) {
  header('Content-Length: ' . $contentLength);
} elseif (!$rangeAsked && !empty($row['Mida_Bytes'])) {
  header('Content-Length: ' . (string)$row['Mida_Bytes']);
}

/* Audita abans d’enviar cos */
audit_pdf($pdo, 'success', (int)$row['ID_Rider'], (string)$row['Rider_UID'], [
  'mode' => $mode,
  'via'  => 'stream',
  'seal_state' => $sealState,
  'public_allowed' => $isValidated,
  'is_owner' => $isOwner,
  'is_admin' => $isAdmin,
  'range' => $rangeAsked ? $httpRange : null,
  'object_key_sha1' => sha1($key),
]);

/* HEAD no envia cos */
if ($isHead) {
  fclose($in);
  exit;
}

/* Stream del cos */
@set_time_limit(0);
$chunk = 8192;
while (!feof($in)) {
  $buf = fread($in, $chunk);
  if ($buf === false) break;
  echo $buf;
  flush();
}
fclose($in);
exit;