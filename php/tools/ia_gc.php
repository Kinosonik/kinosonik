<?php declare(strict_types=1);
require_once dirname(__DIR__).'/preload.php';

date_default_timezone_set('Europe/Madrid');

$now = time();
$stateTTL = 7*24*3600;   // 7 dies
$logTTL   = 60*24*3600;  // 60 dies

$stateDir = sys_get_temp_dir();
$logDir   = (defined('KS_SECURE_LOG_DIR') ? rtrim((string)KS_SECURE_LOG_DIR,'/') : '/var/config/logs/riders').'/ia';

$deleted = ['state'=>0,'logs'=>0];

// State files
foreach (glob($stateDir.'/ai-*.json') ?: [] as $f) {
  $age = $now - @filemtime($f);
  if ($age > $stateTTL) { @unlink($f); $deleted['state']++; }
}

// Logs (només run_*.log)
if (is_dir($logDir)) {
  foreach (glob($logDir.'/run_*.log') ?: [] as $f) {
    $age = $now - @filemtime($f);
    if ($age > $logTTL) { @unlink($f); $deleted['logs']++; }
  }
}

echo sprintf("ia_gc: deleted state=%d, logs=%d\n", $deleted['state'], $deleted['logs']);