<?php
declare(strict_types=1);

// Producció: rotació de /var/config/logs/riders/php-error.log
// - Auth amb KS_LOG_VIEW_KEY (mateixa clau que el visor)
// - Configurable via secret.php: LOG_ROTATE_MAX_BYTES, LOG_ROTATE_KEEP, LOG_ROTATE_COMPRESS
// - Sense dependències del projecte (no carrega preload.php)

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
header('Content-Type: text/plain; charset=UTF-8');

// --- Paths bàsics ---
$secureDir = '/var/config/logs/riders/logs';
$logFile   = $secureDir . '/php-error.log';

// --- Secrets / Auth ---
$secretPath = dirname(__DIR__) . '/secret.php'; // /riders/php/secret.php
if (!is_file($secretPath)) { http_response_code(500); echo "ERROR: secret.php not found\n"; exit; }
$secrets   = require $secretPath;
$authKey   = $secrets['KS_LOG_VIEW_KEY'] ?? '';
$providedK = $_POST['k'] ?? $_GET['k'] ?? '';
if (PHP_SAPI === 'cli' && $providedK === '' && isset($argv[1]) && str_starts_with($argv[1], 'k=')) {
  $providedK = substr($argv[1], 2);
}
if ($authKey === '' || $providedK !== $authKey) { http_response_code(403); echo "Forbidden\n"; exit; }

// --- Config ---
$MAX_BYTES        = (int)($secrets['LOG_ROTATE_MAX_BYTES'] ?? (10 * 1024 * 1024)); // 10MB
$KEEP_FILES       = (int)($secrets['LOG_ROTATE_KEEP']       ?? 15);
$DO_COMPRESS      = (($secrets['LOG_ROTATE_COMPRESS'] ?? '0') === '1');            // 0/1
$FALLBACK_TRUNCATE= true; // si rename impossible, es fa truncate a 0 per evitar creixement

// --- Checks ràpids ---
if (!is_file($logFile)) { echo "NOOP\n"; exit; }
clearstatcache(false, $logFile);
$size = filesize($logFile) ?: 0;
if ($size <= $MAX_BYTES) { echo "NOOP\n"; exit; }

// --- LOCK no-bloquejant ---
$fp = @fopen($logFile, 'c+');
if (!$fp) { echo "ERROR: fopen\n"; exit; }
if (!@flock($fp, LOCK_EX | LOCK_NB)) { @fclose($fp); echo "BUSY\n"; exit; }

// Re-check mida amb lock
clearstatcache(false, $logFile);
$size2 = filesize($logFile) ?: 0;
if ($size2 <= $MAX_BYTES) { @flock($fp, LOCK_UN); @fclose($fp); echo "NOOP\n"; exit; }

// --- ROTATE (rename + recrea buit) ---
$dir  = dirname($logFile);
$base = basename($logFile, '.log');
$rot  = sprintf('%s/%s-%s.%d.log', $dir, $base, date('Ymd-His'), getmypid());

if (is_dir($dir) && is_writable($dir) && @rename($logFile, $rot)) {
  // re-crea el principal immediatament
  $nf = @fopen($logFile, 'a'); if ($nf) @fclose($nf);
  @flock($fp, LOCK_UN); @fclose($fp);

  // Compressió opcional (streaming, sense carregar a memòria)
  if ($DO_COMPRESS) {
    $gz = $rot . '.gz';
    $in = @fopen($rot, 'rb'); $out = @gzopen($gz, 'wb6');
    if ($in && $out) {
      while (!feof($in)) {
        $chunk = fread($in, 16384);
        if ($chunk === '' || $chunk === false) break;
        gzwrite($out, $chunk);
      }
      fclose($in); gzclose($out);
      @unlink($rot);
      $rot = $gz; // per informar al final
    } else {
      if ($in) fclose($in);
      if ($out) gzclose($out);
      // si compressió falla, deixem el .log sense tocar
    }
  }

  // Retenció: conserva només els $KEEP_FILES més recents (.log i .log.gz)
  $list  = glob("$dir/$base-*.log") ?: [];
  $listG = glob("$dir/$base-*.log.gz") ?: [];
  $files = array_unique(array_merge($list, $listG));
  if ($files) {
    usort($files, fn($a,$b) => (filemtime($b) <=> filemtime($a)));
    $toDelete = array_slice($files, $KEEP_FILES);
    foreach ($toDelete as $f) { @unlink($f); }
  }

  echo "OK $rot\n";
  exit;
}

// --- Fallback (truncate) si no podem renombrar però tenim lock obert ---
if ($FALLBACK_TRUNCATE) {
  @ftruncate($fp, 0);
  @fflush($fp);
  @flock($fp, LOCK_UN); @fclose($fp);
  echo "OK TRUNCATED\n";
  exit;
}

@flock($fp, LOCK_UN); @fclose($fp);
echo "ERROR: rename\n";