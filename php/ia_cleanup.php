<?php
// php/ia_cleanup.php — purga segura d'IA per a un rider
declare(strict_types=1);

/**
 * Purga informació d'IA d’un rider:
 *  - Esborra files d'ia_runs i ia_jobs (BD)
 *  - Esborra fitxers de log associats al disc (de ia_runs.log_path)
 *  - Esborra estat persistent a ia_state (si existeix)
 *  - Esborra estats temporals /tmp/ai-<job>.json (tant per jobs com per runs)
 *
 * Retorna:
 *  - rows_deleted      : files eliminades d'ia_runs
 *  - jobs_deleted      : files eliminades d'ia_jobs
 *  - logs_deleted      : fitxers .log eliminats
 *  - state_deleted     : suma d'(files a ia_state) + (fitxers /tmp eliminats)
 *  - tmp_deleted       : (info) fitxers /tmp esborrats
 *  - errors            : llista d’errors no crítics
 */
function ia_purge_for_rider(PDO $pdo, int $riderId): array {
  $res = [
    'rows_deleted'  => 0,   // ia_runs
    'jobs_deleted'  => 0,   // ia_jobs
    'logs_deleted'  => 0,   // fitxers .log
    'state_deleted' => 0,   // ia_state + /tmp
    'tmp_deleted'   => 0,   // /tmp
    'errors'        => [],
  ];

  // 0) Directori base de logs (segur)
  $base = defined('KS_SECURE_LOG_DIR') ? rtrim((string)KS_SECURE_LOG_DIR, '/') : '/var/config/logs/riders';
  $baseReal = realpath($base) ?: $base;
  if ($baseReal !== '' && substr($baseReal, -1) !== DIRECTORY_SEPARATOR) {
    $baseReal .= DIRECTORY_SEPARATOR;
  }

  // 1) Llegeix execucions i jobs per capturar log_paths i job_uids
  $stRuns = $pdo->prepare("SELECT job_uid, log_path FROM ia_runs WHERE rider_id = ?");
  $stRuns->execute([$riderId]);
  $runs = $stRuns->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $stJobs = $pdo->prepare("SELECT job_uid FROM ia_jobs WHERE rider_id = ?");
  $stJobs->execute([$riderId]);
  $jobs = $stJobs->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $jobUids = [];
  foreach ($runs as $r) {
    $ju = preg_replace('/[^a-f0-9]/i', '', (string)($r['job_uid'] ?? ''));
    if ($ju !== '') { $jobUids[$ju] = true; }
  }
  foreach ($jobs as $j) {
    $ju = preg_replace('/[^a-f0-9]/i', '', (string)($j['job_uid'] ?? ''));
    if ($ju !== '') { $jobUids[$ju] = true; }
  }

  // 2) Esborra estat persistent (si la taula existeix)
  try {
    $delState = $pdo->prepare("DELETE FROM ia_state WHERE rider_id = ?");
    $delState->execute([$riderId]);
    $stateRows = $delState->rowCount();
  } catch (Throwable $e) {
    $stateRows = 0;
    $res['errors'][] = 'ia_state_delete_failed:' . $e->getMessage();
  }

  // 3) Esborra registres d'ia_runs
  $delRuns = $pdo->prepare("DELETE FROM ia_runs WHERE rider_id = ?");
  $delRuns->execute([$riderId]);
  $res['rows_deleted'] = $delRuns->rowCount();

  // 3b) Esborra registres d'ia_jobs (queued/running/ok/error… tots)
  try {
    $delJobs = $pdo->prepare("DELETE FROM ia_jobs WHERE rider_id = ?");
    $delJobs->execute([$riderId]);
    $res['jobs_deleted'] = $delJobs->rowCount();
  } catch (Throwable $e) {
    $res['errors'][] = 'ia_jobs_delete_failed:' . $e->getMessage();
  }

  // 4) Esborra fitxers de log declarats a ia_runs
  foreach ($runs as $r) {
    $p = (string)($r['log_path'] ?? '');
    if ($p === '' || !is_file($p)) continue;
    $rp = @realpath($p);
    if ($rp === false || strncmp($rp, $baseReal, strlen($baseReal)) !== 0) {
      $res['errors'][] = "skip_outside_base: $p";
      continue;
    }
    if (@unlink($rp)) { $res['logs_deleted']++; }
    else { $res['errors'][] = "unlink_failed: $rp"; }
  }

  // 4b) Neteja de logs orfes (>30 dies) al directori ia/
  try {
    $limit = time() - 30 * 24 * 3600;  // 30 dies (epoch → TZ-agnòstic)
    $known = [];
    foreach ($runs as $r) {
      if (!empty($r['log_path'])) {
        $rp = @realpath((string)$r['log_path']);
        if ($rp) $known[$rp] = true;
      }
    }
    foreach (glob($base . '/ia/run_*.log') ?: [] as $p) {
      if (!is_file($p)) continue;
      $rp = @realpath($p);
      if ($rp === false || strncmp($rp, $baseReal, strlen($baseReal)) !== 0) continue;
      if (isset($known[$rp])) continue; // referenciat
      $mt = @filemtime($rp);
      if ($mt !== false && $mt < $limit) {
        if (@unlink($rp)) { $res['logs_deleted']++; }
      }
    }
  } catch (Throwable $e) {
    $res['errors'][] = 'orphans_cleanup_failed:' . $e->getMessage();
  }

  // 5) Estats temporals a /tmp (ai-<job>.json) — ara per TOTS els job_uids
  $tmpDir = sys_get_temp_dir();
  foreach (array_keys($jobUids) as $ju) {
    $stateFile = $tmpDir . "/ai-$ju.json";
    if (is_file($stateFile) && @unlink($stateFile)) { $res['tmp_deleted']++; }
  }

  // state_deleted = files ia_state + fitxers /tmp
  $res['state_deleted'] = $stateRows + $res['tmp_deleted'];

  return $res;
}