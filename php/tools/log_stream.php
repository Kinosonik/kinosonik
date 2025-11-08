<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/preload.php';

$secrets = require dirname(__DIR__) . '/secret.php';
$authKey = $secrets['KS_LOG_VIEW_KEY'] ?? '';

if ($authKey === '' || !isset($_GET['k']) || $_GET['k'] !== $authKey) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Forbidden";
  exit;
}

$logFile        = '/var/config/logs/riders/php-error.log';
$maxTailBytes   = 50000;
$maxRunSeconds  = 600;
$pollIntervalMs = 500;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
ignore_user_abort(true);
@set_time_limit(0);

echo "retry: 1000\n\n"; @ob_flush(); @flush();

function sse_send(string $msg): void {
  echo "data: $msg\n\n";
  @ob_flush(); @flush();
}

if (!is_file($logFile) || !is_readable($logFile)) { sse_send("[ERROR] No puc llegir el log: $logFile"); exit; }
$fp = fopen($logFile, 'r');
if (!$fp) { sse_send("[ERROR] fopen fallit a $logFile"); exit; }

$size  = filesize($logFile) ?: 0;
$start = ($size > $maxTailBytes) ? $size - $maxTailBytes : 0;
fseek($fp, $start);
if ($start > 0) sse_send("…(últims $maxTailBytes bytes)…");
sse_send("[INFO] connectat, fitxer: $logFile (size=$size)");
sse_send('[TICK ' . date('H:i:s') . ']');

$startTime = time();
$lastBeat  = microtime(true);
$lastInode = fileinode($logFile) ?: 0;
$buf = '';

while (true) {
  if (connection_aborted()) break;
  clearstatcache(false, $logFile);

  $inode = fileinode($logFile) ?: 0;
  if ($inode !== $lastInode) {
    fclose($fp);
    $fp = fopen($logFile, 'r');
    $lastInode = $inode;
    sse_send("[INFO] rotació detectada, reobrint…");
  }

  $chunk = fread($fp, 8192);
  if ($chunk !== false && $chunk !== '') {
    $buf .= $chunk;
    $lines = explode("\n", $buf);
    $buf = array_pop($lines);
    if ($lines) sse_send(implode("\n", $lines));
  } else {
    usleep($pollIntervalMs * 1000);
  }

  if ((microtime(true) - $lastBeat) > 3) {
    echo ": hb\n\n"; @ob_flush(); @flush();
    $lastBeat = microtime(true);
  }

  if ((time() - $startTime) > $maxRunSeconds) {
    sse_send("[INFO] timeout sessió, el navegador es reconnectarà…");
    break;
  }
}
fclose($fp);