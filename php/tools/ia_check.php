<?php
// php/tools/ia_check.php — prova ràpida del ruleset IA sobre un rider (per ID o fitxer)
declare(strict_types=1);
// ia_check.php — comprovacions ràpides de la IA (CLI-first, tolera execució web)

// Evita warnings si s'executa fora de CLI
$ARG = [];
if (PHP_SAPI === 'cli') {
  $ARG = array_slice($argv ?? [], 1);
}

// Petit helper per parsejar flags tipus --foo=bar
function arg_get(string $name, array $ARG, ?string $default=null): ?string {
  foreach ($ARG as $a) {
    if (strpos($a, '--'.$name.'=') === 0) {
      return (string)substr($a, strlen($name)+3);
    }
  }
  return $default;
}

// Si s’invoca via web, mostra recordatori curt i surt amb 400
if (PHP_SAPI !== 'cli') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "This tool is CLI-only.\n";
  exit(1);
}


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../r2.php';
require_once __DIR__ . '/../ia_extract_heuristics.php';

// Helper local: descarrega objecte de R2 a /tmp i retorna [ruta_pdf, bytes]
if (!function_exists('r2_download_to_tmp')) {
  function r2_download_to_tmp(string $objectKey): array {
    $cli    = r2_client();
    $bucket = r2_bucket();
    $resp   = $cli->getObject(['Bucket' => $bucket, 'Key' => $objectKey]);
    $tmpPdf = tempnam(sys_get_temp_dir(), 'rider_');
    // StreamInterface -> string
    $body   = (string)$resp['Body'];
    file_put_contents($tmpPdf, $body);
    @chmod($tmpPdf, 0600);
    return [$tmpPdf, strlen($body)];
  }
}

function fail(string $msg, int $code = 1): never {
  fwrite(STDERR, "[ERR] $msg\n");
  exit($code);
}

$argvList = $_SERVER['argv'] ?? [];
$args = implode(' ', array_slice($argvList, 1));
$pdfPath = null;
$riderId = null;

if (PHP_SAPI !== 'cli') {
  http_response_code(400);
  echo "CLI only.\n";
  exit(1);
}

// Opcions d'ús
if (preg_match('/--file=(?<f>.+\.pdf)\b/i', $args, $m)) {
  $pdfPath = (string)$m['f'];
} elseif (preg_match('/--id=(?<id>\d+)/i', $args, $m)) {
  $riderId = (int)$m['id'];
} else {
  fail("Ús: php php/tools/ia_check.php --file=/abs/path/file.pdf  o  --id=123");
}

/* ─────────────── Carrega des de R2 si s'ha passat --id ─────────────── */
if ($riderId !== null) {
  $pdo = db();
  $st = $pdo->prepare("SELECT Object_Key FROM Riders WHERE ID_Rider = ? LIMIT 1");
  $st->execute([$riderId]);
  $key = $st->fetchColumn();
  if (!$key) fail("No s'ha trobat cap Rider amb ID=$riderId o sense Object_Key", 2);

  echo "Descarregant de R2: $key\n";
  [$tmp, $bytes] = r2_download_to_tmp($key);
  $pdfPath = $tmp;
  echo "PDF temporal: $pdfPath ($bytes bytes)\n";
}

/* ─────────────── Extreu text amb pdftotext ─────────────── */
if (!is_readable($pdfPath)) fail("No es pot llegir el fitxer: $pdfPath", 3);

$outTxt = $pdfPath . '.txt';
$cmd = sprintf(
  "/usr/bin/pdftotext -enc UTF-8 -layout -nopgbrk -q %s %s 2>&1",
  escapeshellarg($pdfPath),
  escapeshellarg($outTxt)
);
exec($cmd, $lines, $rc);
if ($rc !== 0 || !is_file($outTxt)) {
  fail("pdftotext ha fallat:\n" . implode("\n", $lines), 4);
}
$text = file_get_contents($outTxt) ?: '';

/* ─────────────── Avalua heurístiques ─────────────── */
$res = run_heuristics($text, $pdfPath);

/* ─────────────── Sortida ─────────────── */
echo "\n== IA CHECK ==\n";
if ($riderId !== null) echo "Rider ID: $riderId\n";
echo "Fitxer: $pdfPath\n";
echo "Caràcters: " . mb_strlen($text, 'UTF-8') . "\n";
echo "Puntuació: " . ($res['score'] ?? 0) . "/100\n\n";

echo "-- RULES --\n";
foreach (($res['rules'] ?? []) as $k => $v) {
  $st = $v === true ? 'OK' : ($v === false ? 'NO' : 'N/A');
  printf("  %-16s : %s\n", $k, $st);
}

echo "\n-- COMENTARIS --\n";
foreach (($res['comments'] ?? []) as $c) {
  echo "  - $c\n";
  // Bloc suggerit (si disponible)
    if (!empty($res['suggestion_block'])) {
      echo "\n-- BLOC SUGGERIT --\n";
      echo $res['suggestion_block'] . "\n";
    }
}

echo "\n-- JSON DEBUG --\n";
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if (!empty($res['meta']['suggestion_block'])) {
    echo "\n-- SUGGERIMENT PER ENGANXAR --\n";
    echo $res['meta']['suggestion_block'] . "\n";
  }

/* ─────────────── Neteja si era de R2 ─────────────── */
if ($riderId !== null && is_file($pdfPath)) {
  @unlink($pdfPath);
  @unlink($outTxt);
}