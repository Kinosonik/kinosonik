<?php declare(strict_types=1);
/**
 * php/cron/ia_healthcheck.php
 */
umask(0002);
require_once __DIR__ . '/../preload.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('Europe/Madrid');
$pdo = db();

$base = defined('KS_SECURE_LOG_DIR') ? rtrim((string)KS_SECURE_LOG_DIR,'/') : '/var/config/logs/riders';
$wlog = $base.'/worker.log';
$statusFile = $base.'/healthcheck.status';

$problems = [];

/* Single-instance lock (evita solapaments de cron) */
$lockFp = @fopen(sys_get_temp_dir().'/ia_healthcheck.lock', 'c');
if ($lockFp && !@flock($lockFp, LOCK_EX|LOCK_NB)) {
  // ja n'hi ha un altre en execució; no és un problema, només sortim
  echo '['.date('c')."] ok=true; another_instance_running\n";
  exit(0);
}

/* 1) running > 20 min */
$st = $pdo->query("SELECT COUNT(*) FROM ia_jobs WHERE status='running' AND started_at < (NOW() - INTERVAL 20 MINUTE)");
$stuck = (int)$st->fetchColumn();
if ($stuck > 0) $problems[] = "running_stuck=$stuck (>20m)";

/* 2) queued massa antiga i massiva */
$st = $pdo->query("SELECT COUNT(*) FROM ia_jobs WHERE status='queued' AND created_at < (NOW() - INTERVAL 10 MINUTE)");
$qold = (int)$st->fetchColumn();
if ($qold > 20) $problems[] = "queue_backlog=$qold (>20 and >10m)";

/* 3) worker.log sense activitat > 10 min */
$wok = true;
if (!is_file($wlog)) { $wok=false; $problems[]='worker_log_missing'; }
else {
  $age = time() - (int)@filemtime($wlog);
  if ($age > 600) { $wok=false; $problems[]='worker_inactive_>10m'; }
}

/* 4) pendents totals i “fa quant del darrer run” */
// llindars (toca'ls si cal)
$MAX_PENDING = 50;   // queued + running
$MAX_AGE_MIN = 120;  // sense runs nous en > 120 min
try {
  $pending = (int)$pdo->query("SELECT COUNT(*) FROM ia_jobs WHERE status IN ('queued','running')")->fetchColumn();
  if ($pending > $MAX_PENDING) { $problems[] = "pending_total=$pending(>$MAX_PENDING)"; }

  $lastTs = (int)$pdo->query("SELECT UNIX_TIMESTAMP(MAX(started_at)) FROM ia_runs")->fetchColumn();
  if ($lastTs > 0) {
    $ageMin = (int)floor((time() - $lastTs)/60);
    if ($ageMin > $MAX_AGE_MIN) { $problems[] = "no_recent_runs=${ageMin}m(>$MAX_AGE_MIN)"; }
  } else {
    // mai no hi ha hagut runs; no el considerem error, però ho anotem
    $problems[] = "no_runs_yet";
  }
} catch (Throwable $e) {
  $problems[] = "db_error=".preg_replace('/\s+/', ' ', $e->getMessage());
}

/* Sortida */
$ok = empty($problems);
$line = sprintf(
  "[%s] ok=%s; %s",
  (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('c'),
  $ok ? 'true' : 'false',
  $ok ? 'healthy' : implode('; ', $problems)
);
@file_put_contents($statusFile, $line."\n", FILE_APPEND);
@chmod($statusFile, 0664);

/* Opcional: correu */
// if (!$ok) { @mail('ops@exemple.com', '[Riders] IA healthcheck FAIL', $line); }

if (!$ok) {
  // codi de sortida !=0 per si tens monitor extern
  fwrite(STDERR, $line.PHP_EOL);
  exit(1);
}
echo $line.PHP_EOL;
// Allibera lock (es fa sol en finalitzar, però per neteja)
if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }