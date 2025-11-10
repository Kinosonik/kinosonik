<?php
// php/ia_demo.php — Anàlisi heurístic gratuït (usuari anònim)
declare(strict_types=1);

require_once __DIR__ . '/preload.php';
require_once __DIR__ . '/ia_extract_heuristics.php';
require_once __DIR__ . '/ks_pdf.php';
require_once __DIR__ . '/audit.php';

header('Content-Type: application/json; charset=UTF-8');

// ─── Rate limit bàsic ─────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = sys_get_temp_dir() . '/demo_rate_' . md5($ip) . '.txt';
$now = time();
$entries = [];

// Llegeix registres existents i elimina antics (>600 s)
if (is_file($rateFile)) {
  foreach (file($rateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $t = (int)$line;
    if ($now - $t < 600) $entries[] = $t;
  }
}

// Si hi ha massa intents, talla
if (count($entries) >= 5) {
  http_response_code(429);
  echo json_encode(['error' => 'Has fet massa proves. Torna-ho a intentar d’aquí uns minuts.']);
  exit;
}

// Desa el nou intent
$entries[] = $now;
file_put_contents($rateFile, implode("\n", $entries));

// ─── Validació bàsica ─────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}
if (empty($_FILES['file']['tmp_name'])) {
  http_response_code(400);
  echo json_encode(['error' => 'no_file']);
  exit;
}

$maxSize = 20 * 1024 * 1024; // 20 MB límit segur
$srcTmp = $_FILES['file']['tmp_name'];
$name   = basename($_FILES['file']['name']);
$tmp    = sys_get_temp_dir() . '/demo_ia_' . uniqid() . '.pdf';
copy($srcTmp, $tmp);

$mime = mime_content_type($tmp);
$size = filesize($tmp);
error_log("UPLOAD CHECK name=$name mime=$mime size=$size");

$allowedMimes = [
  'application/pdf',
  'application/x-pdf',
  'application/octet-stream'
];
if (!in_array($mime, $allowedMimes, true) || $size > $maxSize) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_file', 'mime' => $mime, 'size' => $size]);
  exit;
}

// ─── Extracció i heurístic ────────────────────────────────────────
$realTmp = sys_get_temp_dir() . '/demo_ia_text.txt';
@unlink($realTmp);

exec('sha256sum ' . escapeshellarg($tmp) . ' 2>&1', $out);

$text = ks_pdf_to_text($tmp);
if ($text === '' || $text === false) {
  error_log("PDF extract failed");
  echo json_encode(['error' => 'pdf_extract_failed']);
  exit;
}
file_put_contents($realTmp, $text);

if (!function_exists('ia_extract_heuristics')) {
  error_log("Function ia_extract_heuristics() missing");
  echo json_encode(['error' => 'function_missing']);
  exit;
}

$result = ia_extract_heuristics($text, [
  'mode'      => 'demo',
  'source'    => 'anonymous',
  'file_name' => $name
]);

$score = (int)round($result['score'] ?? 0);

// ─── Diccionari multillengua ─────────────────────────────
$lang = $_POST['lang'] ?? 'ca';
$labels = [
  'ca' => ['bad' => 'Rider deficient', 'weak' => 'Rider feble', 'good' => 'Rider correcte'],
  'es' => ['bad' => 'Rider deficiente', 'weak' => 'Rider débil', 'good' => 'Rider correcto'],
  'en' => ['bad' => 'Poor rider', 'weak' => 'Weak rider', 'good' => 'Good rider'],
];
if (!isset($labels[$lang])) $lang = 'ca';

if ($score <= 65) {
  $label = $labels[$lang]['bad'];
} elseif ($score <= 80) {
  $label = $labels[$lang]['weak'];
} else {
  $label = $labels[$lang]['good'];
}

// ─── Log anònim ────────────────────────────────────────────────
$hash = hash_file('sha256', $tmp);
$log  = sprintf("%s\t%s\t%d\n", date('Y-m-d H:i:s'), $hash, $score);
file_put_contents('/var/config/logs/riders/demo_ia.log', $log, FILE_APPEND);

// ─── Neteja ───────────────────────────────────────────────────
@unlink($tmp);

// ─── Audit ─────────────────────────────────────────────────────
try {
  if (function_exists('audit_admin')) {
    $pdo = db();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    audit_admin($pdo, $uid, false, 'ia_demo_run', null, null, 'public_demo', [
      'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
      'file'     => $name,
      'size'     => $size,
      'mime'     => $mime,
      'score'    => $score,
      'label'    => $label,
      'version'  => 'demo-v1',
      'php_time' => microtime(true),
    ]);
  } else {
    error_log("audit_admin() no disponible");
  }
} catch (Throwable $e) {
  error_log("audit_admin ia_demo_run: " . $e->getMessage());
}

// ─── Resposta ─────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
  'score'   => $score,
  'label'   => $label,
  'flags'   => $result['flags'] ?? [],
  'version' => 'demo-v1'
]);