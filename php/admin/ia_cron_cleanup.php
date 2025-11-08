<?php
// php/admin/ia_cron_cleanup.php — Neteja periòdica d'IA orfes/logs vells
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/../ia_cleanup.php';

$pdo = db();

// --- Paràmetres
$retentionDays = defined('IA_LOG_RETENTION_DAYS') ? (int)IA_LOG_RETENTION_DAYS : 30;
$limitTime = time() - $retentionDays * 86400;

// --- Neteja de logs orfes (vells > X dies)
$base = defined('KS_SECURE_LOG_DIR') ? rtrim((string)KS_SECURE_LOG_DIR, '/') : '/var/config/logs/riders';
$baseReal = realpath($base) ?: $base;
if (substr($baseReal, -1) !== DIRECTORY_SEPARATOR) $baseReal .= DIRECTORY_SEPARATOR;

$deleted = 0;
foreach (glob($base . '/ia/run_*.log') ?: [] as $p) {
  $rp = realpath($p);
  if ($rp === false || strncmp($rp, $baseReal, strlen($baseReal)) !== 0) continue;
  $mt = filemtime($rp);
  if ($mt !== false && $mt < $limitTime) {
    if (@unlink($rp)) $deleted++;
  }
}

// --- Audit opcional
try {
  require_once dirname(__DIR__) . '/audit.php';
  audit_admin($pdo, 0, true, 'ia_cron_cleanup', null, null, 'cron', ['deleted_logs'=>$deleted,'retention_days'=>$retentionDays], 'success');
} catch (Throwable $e) { error_log('audit ia_cron_cleanup failed: '.$e->getMessage()); }

echo "Cleanup complet: $deleted logs eliminats\n";