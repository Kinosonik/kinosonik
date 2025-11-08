<?php declare(strict_types=1);
/**
 * php/cron/ia_housekeeping.php
 */
umask(0002);
require_once __DIR__ . '/../preload.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('Europe/Madrid');
$pdo = db();

function ks_log($m){
  $line = '[ia_housekeeping] '.$m;
  error_log($line);
  // també a stdout per execucions manuals
  if (PHP_SAPI === 'cli') { echo $line, PHP_EOL; }
}

/* 1) /tmp state files (>48h) */
$now = time();
$purged=0;
foreach (glob(sys_get_temp_dir().'/ai-*.json') ?: [] as $p) {
  $age = $now - @filemtime($p);
  if ($age > 48*3600) { @unlink($p); $purged++; }
}
ks_log("purged_state_files=$purged");

/* 2) Logs de run (>30 dies) */
$base = defined('KS_SECURE_LOG_DIR') ? rtrim((string)KS_SECURE_LOG_DIR,'/') : '/var/config/logs/riders';
$dir  = $base.'/ia';
$deleted=0;
if (is_dir($dir)) {
  $dh = opendir($dir);
  while ($dh && ($f = readdir($dh)) !== false) {
    if (!preg_match('/^run_[a-f0-9]{12}\.log$/', $f)) continue;
    $path="$dir/$f"; $age=$now-@filemtime($path);
    if ($age > 30*86400) { @unlink($path); $deleted++; }
  }
  if ($dh) closedir($dh);
}
ks_log("purged_run_logs=$deleted");

/* 3) ia_jobs tancats (>90 dies) */
$pdo->exec("DELETE FROM ia_jobs
             WHERE status IN ('ok','error')
               AND created_at < (NOW() - INTERVAL 90 DAY)");
$rows = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
ks_log("deleted_old_jobs=${rows}");

/* 4) worker.log: confiem en logrotate; no fem cap compactació aquí */
ks_log("done");