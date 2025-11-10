<?php
// php/ia_demo.php — Anàlisi heurístic gratuït (usuari anònim)
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', '/var/config/logs/riders/demo_ia_error.log');

require_once __DIR__ . '/preload.php';
require_once __DIR__ . '/ia_extract_heuristics.php';  // ← versió real
require_once __DIR__ . '/ks_pdf.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

if (empty($_FILES['file']['tmp_name'])) {
  http_response_code(400);
  echo json_encode(['error' => 'no_file']);
  exit;
}

$tmp = $_FILES['file']['tmp_name'];
$name = basename($_FILES['file']['name']);
$mime = mime_content_type($tmp);
$maxSize = 10 * 1024 * 1024; // 10 MB

if (!in_array($mime, ['application/pdf']) || filesize($tmp) > $maxSize) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_file']);
  exit;
}

// ─── Extracció i heurístic ───────────────────────────────
$text = ks_pdf_to_text($tmp);
if (!$text) {
  echo json_encode(['error' => 'pdf_extract_failed']);
  exit;
}

// ─── Comprovació de la funció ─────────────────────────────
if (!function_exists('ia_extract_heuristics')) {
  echo json_encode(['error' => 'function_missing']);
  exit;
}

$result = ia_extract_heuristics($text, [
  'mode' => 'demo',
  'source' => 'anonymous',
  'file_name' => $name
]);

$score = (int)round($result['score'] ?? 0);
$label = 'Rider dubtós';
if ($score > 80) $label = 'Rider correcte';
elseif ($score >= 60) $label = 'Rider millorable';
elseif ($score < 60) $label = 'Rider feble';

// ─── Log mínim anònim ─────────────────────────────────────
$hash = hash_file('sha256', $tmp);
$log = sprintf("%s\t%s\t%d\n", date('Y-m-d H:i:s'), $hash, $score);
file_put_contents('/var/config/logs/riders/demo_ia.log', $log, FILE_APPEND);

// ─── Elimina el fitxer temporal ────────────────────────────
@unlink($tmp);

// ─── Resposta ─────────────────────────────────────────────
echo json_encode([
  'score' => $score,
  'label' => $label,
  'flags' => $result['flags'] ?? [],
  'version' => 'demo-v1'
]);