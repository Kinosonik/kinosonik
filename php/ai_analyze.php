<?php
// php/ai_analyze.php — Etapa 2: extreu text del PDF amb pdftotext i calcula score heurístic.
// Flux i contractes idèntics a Etapa 0 (helpers, permisos, flash, UPDATE).
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/r2.php';

$pdo = db();

/* ---------- Helpers (mateixos que Etapa 0) ---------- */
function flash_set(string $type, string $key, array $extra = []): void {
  $_SESSION['flash'] = ['type' => $type, 'key' => $key, 'extra' => $extra];
}
function redirect_to(string $path, array $params = []): never {
  $qs = $params ? ('?' . http_build_query($params)) : '';
  $path = ltrim($path, '/');
  header('Location: ' . BASE_PATH . $path . $qs, true, 302);
  exit;
}

/* ---------- Guard d'autenticació ---------- */
if (empty($_SESSION['user_id'])) {
  redirect_to('index.php', ['error' => 'login_required', 'modal' => 'login']);
}

/* ---------- Input: ?id=ID_Rider ---------- */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  flash_set('error','invalid_request');
  redirect_to('espai.php', ['seccio'=>'riders']);
}

/* ---------- Permisos ---------- */
$me      = (int)($_SESSION['user_id'] ?? 0);
$tipus   = (string)($_SESSION['tipus_usuari'] ?? '');
$isAdmin = (strcasecmp($tipus, 'admin') === 0);

/* Carreguem propietari + estat + camps útils */
$st = $pdo->prepare("
  SELECT ID_Usuari, Estat_Segell, Rider_UID, Nom_Arxiu, Object_Key, Mida_Bytes
    FROM Riders
   WHERE ID_Rider = :id
   LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  flash_set('error','rider_not_found');
  redirect_to('espai.php', ['seccio'=>'riders']);
}

$uidOwner = (int)$row['ID_Usuari'];
if (!$isAdmin && $uidOwner !== $me) {
  flash_set('error','forbidden');
  redirect_to('espai.php', ['seccio'=>'riders']);
}

/* ---------- Tallafoc d’estat ---------- */
$estat = strtolower(trim((string)($row['Estat_Segell'] ?? '')));
if ($estat === 'validat' || $estat === 'caducat') {
  flash_set('error', 'ai_locked_state');
  redirect_to('espai.php', ['seccio' => 'riders']);
}

/* ---------- Setup paths ---------- */
$uid = (string)($row['Rider_UID'] ?? '');

// --- Descarrega del PDF directament de R2 amb URL presignada ---
$objectKey = (string)($row['Object_Key'] ?? '');
if ($objectKey === '') {
  error_log('[AI] missing Object_Key');
  flash_set('error','invalid_request');
  redirect_to('espai.php', ['seccio'=>'riders']);
}

try {
  $client = r2_client();
  $bucket = getenv('R2_BUCKET') ?: ($_ENV['R2_BUCKET'] ?? '');
  if ($bucket === '') { throw new RuntimeException('R2_BUCKET no configurat'); }

  $cmd = $client->getCommand('GetObject', [
    'Bucket' => $bucket,
    'Key'    => $objectKey,
    'ResponseContentType' => 'application/pdf',
  ]);
  $request   = $client->createPresignedRequest($cmd, '+5 minutes');
  $signedUrl = (string)$request->getUri();
} catch (Throwable $e) {
  error_log('[AI] presign error: ' . $e->getMessage());
  throw new RuntimeException('download_failed');
}

// Baixa bytes del PDF (SENSE cookies)
$ch = curl_init($signedUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT        => 30,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_HTTPHEADER     => ['Accept: application/pdf'],
]);
$body = curl_exec($ch);
$errno = curl_errno($ch);
$err   = curl_error($ch);
$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct    = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($errno || $code >= 400 || $body === false || $body === '' || stripos((string)$ct, 'pdf') === false || strncmp((string)$body, '%PDF', 4) !== 0) {
  error_log("[AI] download_failed errno=$errno code=$code ct=$ct bytes=" . strlen((string)$body) . " err=$err");
  throw new RuntimeException('download_failed');
}

// ---------- 2) Extreu text del PDF amb pdftotext ----------
$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
if (in_array('shell_exec', $disabled, true)) {
  error_log('[AI] shell_exec disabled; fallback etapa 0');
  try { $score = random_int(0,100); } catch (Throwable) { $score = mt_rand(0,100); }
  goto SAVE_AND_EXIT;
}
$pdftotextBin = dirname(__DIR__) . '/bin/pdftotext'; // /var/www/html/bin/pdftotext

if (!is_file($pdftotextBin) || !is_executable($pdftotextBin)) {
  error_log("[AI] pdftotext not usable at $pdftotextBin");
  try { $score = random_int(0,100); } catch (Throwable) { $score = mt_rand(0,100); }
  goto SAVE_AND_EXIT;
}

// Desa PDF a /tmp
$pdfTmp = tempnam(sys_get_temp_dir(), 'rider_pdf_');
file_put_contents($pdfTmp, $body);

// Extreu text a stdout (- enc UTF-8, silenciós)
$cmd = escapeshellarg($pdftotextBin) . ' -enc UTF-8 -layout -nopgbrk -q ' . escapeshellarg($pdfTmp) . ' -';
$txt = shell_exec($cmd);
@unlink($pdfTmp);

if (!is_string($txt) || trim($txt) === '') {
  error_log("[AI] pdftotext returned empty");
  try { $score = random_int(0,100); } catch (Throwable) { $score = mt_rand(0,100); }
  goto SAVE_AND_EXIT;
}

/* ---------- 3) Heurística de puntuació (simple, transparent) ---------- */
$T = strtolower($txt);
$score = 0;

$sections = [
  'inputs'       => 10,
  'outputs'      => 10,
  'stage plot'   => 10,
  'patch list'   => 10,
  'backline'     => 10,
  'hospitality'  => 10,
];
foreach ($sections as $needle => $pts) {
  if (str_contains($T, $needle)) $score += $pts;
}

if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $txt)) $score += 10;
if (preg_match('/\+?[0-9][0-9 ().-]{6,}/', $txt))             $score += 10;

$logistics = [
  'soundcheck'    => 10,
  'load-in'       => 10,
  'curfew'        => 10,
  'running order' => 10,
];
$logiFound = 0;
foreach ($logistics as $needle => $pts) {
  if (str_contains($T, $needle)) { $logiFound += 10; }
}
$score += min(20, $logiFound);

/* Clamp per seguretat */
$score = max(0, min(100, $score));

/* ---------- 4) Guarda i surt (mateix UPDATE i flash que Etapa 0) ---------- */
SAVE_AND_EXIT:
try {
  // Hora local Europe/Madrid per coherència amb la resta de pantalles
  $nowLocal = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s');

  $up = $pdo->prepare("
    UPDATE Riders
       SET Valoracio       = :v,
           Estat_Segell    = 'pendent',
           Data_Publicacio = NULL,
           Data_IA         = :now
     WHERE ID_Rider = :id
     LIMIT 1
  ");
  $up->execute([
    ':v'   => $score,
    ':now' => $nowLocal,
    ':id'  => $id
  ]);

  flash_set('success','ai_scored', ['score' => $score]);
  redirect_to('espai.php', ['seccio'=>'riders']);

} catch (Throwable $e) {
  error_log('[AI] UPDATE Riders error: ' . $e->getMessage());
  flash_set('error','db_error');
  redirect_to('espai.php', ['seccio'=>'riders']);
}