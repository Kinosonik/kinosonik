<?php declare(strict_types=1);
umask(0002);
// php/cron/ia_worker.php — processa ia_jobs → escriu ia_runs + logs + state file, amb reintents
// VERSIÓ MILLORADA v2.0

require_once dirname(__DIR__) . '/preload.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/r2.php';
require_once dirname(__DIR__) . '/ia_extract_heuristics.php';

date_default_timezone_set('Europe/Madrid');

$pdo   = db();
$BATCH = 5; // quants jobs per tick

/* --------------------------- Utils (logs) --------------------------- */

function ks_log_cli(string $msg): void {
  error_log('[ia_worker] ' . $msg);
}

function job_log_path(string $job_uid): string {
  $base = defined('KS_SECURE_LOG_DIR') ? rtrim((string)KS_SECURE_LOG_DIR, '/') : '/var/config/logs/riders';
  $dir  = $base . '/ia';
  if (!is_dir($dir)) {
    @mkdir($dir, 02775, true);
  }
  @chmod($dir, 02775);
  if (function_exists('posix_getgrnam')) {
    @chgrp($dir, 'www-data');
  }
  return $dir . '/run_' . $job_uid . '.log';
}

function log_line(string $path, string $line): void {
  $ts = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('c');
  @file_put_contents($path, '[' . $ts . '] ' . $line . PHP_EOL, FILE_APPEND);
  @chmod($path, 0644);
  if (function_exists('posix_getgrnam')) {
    @chgrp($path, 'www-data');
  }
}

function wlog(string $event, array $extra = [], string $lvl = 'info'): void {
  static $path = null;
  if ($path === null) {
    $base = defined('KS_SECURE_LOG_DIR') ? rtrim((string)KS_SECURE_LOG_DIR, '/') : '/var/config/logs/riders';
    $path = getenv('KS_WORKER_LOG') ?: ($base . '/worker.log');
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 02775, true); }
    @chmod($dir, 02775);
    if (function_exists('posix_getgrnam')) { @chgrp($dir, 'www-data'); }
    if (!file_exists($path)) { @touch($path); }
    @chmod($path, 0664);
    if (function_exists('posix_getgrnam')) { @chgrp($path, 'www-data'); }
  }
  $base = [
    'ts'    => (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('c'),
    'lvl'   => $lvl,
    'event' => $event,
    'pid'   => getmypid(),
    'host'  => php_uname('n'),
    'ver'   => 'ia-worker-2.0',
  ];
  $line = json_encode($base + $extra, JSON_UNESCAPED_SLASHES);
  @error_log($line . PHP_EOL, 3, $path);
}

/* ------------------------- State file (/tmp) ------------------------ */

function state_file(string $job_uid): string {
  return sys_get_temp_dir() . "/ai-$job_uid.json";
}

function put_state(string $job_uid, array $patch): void {
  $file = state_file($job_uid);
  $cur  = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
  if (isset($patch['log'])) {
    $cur['logs'] = isset($cur['logs']) && is_array($cur['logs']) ? $cur['logs'] : [];
    $cur['logs'][] = (string)$patch['log'];
    unset($patch['log']);
  }
  $new = array_merge($cur, $patch, [
    'ts'     => time(),
    'ts_iso' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('c'),
  ]);
  @file_put_contents($file, json_encode($new, JSON_UNESCAPED_UNICODE), LOCK_EX);
  @chmod($file, 0600);
}

function safe_unlink(?string $path): void {
  if (!$path) return;
  if (is_file($path)) { @unlink($path); }
}

/* ----------------------- Helpers DB de cua -------------------------- */

function claim_job(PDO $pdo, int $id): bool {
  $st = $pdo->prepare(
    "UPDATE ia_jobs
        SET status='running', started_at=NOW()
      WHERE id=:id AND status='queued'"
  );
  $st->execute([':id' => $id]);
  return $st->rowCount() === 1;
}

function finish_job(PDO $pdo, int $id, string $final, ?string $err = null): void {
  $st = $pdo->prepare(
    "UPDATE ia_jobs
        SET status=:st, finished_at=NOW(), error_msg=:err
      WHERE id=:id"
  );
  $st->execute([':st' => $final, ':err' => $err, ':id' => $id]);
}

/* ----------------------- R2 Download amb retry ---------------------- */

function r2_download_to_tmp(string $objectKey, int $maxRetries = 3): array {
  $cli = r2_client();
  $bucket = r2_bucket();
  
  $lastError = null;
  for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    try {
      // ✨ Validació d'existència
      $cli->headObject(['Bucket' => $bucket, 'Key' => $objectKey]);
      
      $resp = $cli->getObject(['Bucket' => $bucket, 'Key' => $objectKey]);
      $body = (string)$resp['Body'];
      
      if (empty($body)) {
        throw new RuntimeException('empty_object');
      }
      
      $tmpPdf = tempnam(sys_get_temp_dir(), 'rider_');
      file_put_contents($tmpPdf, $body);
      @chmod($tmpPdf, 0600);
      
      return [$tmpPdf, strlen($body)];
      
    } catch (Throwable $e) {
      $lastError = $e->getMessage();
      if ($attempt < $maxRetries) {
        usleep(1000000 * $attempt); // 1s, 2s, 3s backoff
        continue;
      }
    }
  }
  
  throw new RuntimeException("r2_download_failed after $maxRetries attempts: $lastError");
}

/* ----------------------- PDF to Text amb timeout -------------------- */

function pdf_to_text_paths(string $pdfPath, int $timeout = 30): array {
  $out = $pdfPath . '.txt';
  $bin = is_file('/usr/bin/pdftotext') ? '/usr/bin/pdftotext' : 'pdftotext';
  
  // ✨ proc_open amb timeout
  $descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
  ];
  
  $start = time();
  $proc = proc_open(
    [$bin, '-enc', 'UTF-8', '-layout', '-nopgbrk', '-q', $pdfPath, $out],
    $descriptors,
    $pipes
  );
  
  if (!is_resource($proc)) {
    throw new RuntimeException('pdftotext_proc_failed');
  }
  
  fclose($pipes[0]);
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  
  $stdout = '';
  $stderr = '';
  
  while (time() - $start < $timeout) {
    $status = proc_get_status($proc);
    if (!$status['running']) break;
    
    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    usleep(100000); // 0.1s
  }
  
  $status = proc_get_status($proc);
  if ($status['running']) {
    // ⚠️ Timeout! Kill process
    proc_terminate($proc, 9);
    proc_close($proc);
    throw new RuntimeException("pdftotext_timeout after {$timeout}s");
  }
  
  fclose($pipes[1]);
  fclose($pipes[2]);
  $rc = proc_close($proc);
  
  if ($rc !== 0 || !is_file($out)) {
    throw new RuntimeException('pdftotext_failed: ' . ($stderr ?: 'unknown error'));
  }
  
  $txt = file_get_contents($out) ?: '';
  return [$out, $txt];
}

/* ================================ MAIN ================================ */

try {
  $t0 = microtime(true);
  $processed = 0;
  wlog('tick_start');

  // ✨ Lock global (si vols múltiples workers, canvia a lock per rider)
  $lk = $pdo->query("SELECT GET_LOCK('ia_worker_lock', 1)")->fetchColumn();
  if ((string)$lk !== '1') {
    ks_log_cli('skip: no lock');
    wlog('lock_skip', ['reason' => 'mysql_get_lock_busy'], 'warn');
    exit(0);
  }

  // ✨ Neteja state files > 24h
  try {
    $tmpDir = sys_get_temp_dir();
    $files = glob($tmpDir . '/ai-*.json') ?: [];
    $now = time();
    $cleaned = 0;
    foreach ($files as $f) {
      if (($now - filemtime($f)) > 86400) {
        @unlink($f);
        $cleaned++;
      }
    }
    if ($cleaned > 0) {
      wlog('state_cleanup', ['cleaned' => $cleaned]);
    }
  } catch (Throwable $e) {
    wlog('cleanup_failed', ['error' => $e->getMessage()], 'warn');
  }

  // Jobs en cua
  $st = $pdo->prepare(
    "SELECT id, rider_id, job_uid, attempts, max_attempts, payload_json
       FROM ia_jobs
      WHERE status='queued' AND attempts < max_attempts
      ORDER BY created_at ASC
      LIMIT :n"
  );
  $st->bindValue(':n', $BATCH, PDO::PARAM_INT);
  $st->execute();
  $jobs = $st->fetchAll(PDO::FETCH_ASSOC);

  wlog('queue_scan', ['queue_len' => count($jobs)]);

  foreach ($jobs as $J) {
    $id       = (int)$J['id'];
    $rid      = (int)$J['rider_id'];
    $job_uid  = (string)$J['job_uid'];
    $attempts = (int)$J['attempts'];
    $maxAtt   = (int)$J['max_attempts'];

    // Evita més d'un actiu per rider
    $chk = $pdo->prepare("SELECT COUNT(*) FROM ia_jobs WHERE rider_id=? AND status IN ('queued','running')");
    $chk->execute([$rid]);
    if ((int)$chk->fetchColumn() > 1) {
      ks_log_cli("defer job#$id (rider $rid): another active job exists");
      wlog('job_deferred', ['job_id' => $id, 'rider_id' => $rid, 'reason' => 'another_active']);
      continue;
    }

    // Claim
    if (!claim_job($pdo, $id)) {
      ks_log_cli("skip claim job#$id");
      wlog('job_claim_skip', ['job_id' => $id]);
      continue;
    }

    $log = job_log_path($job_uid);
    wlog('job_start', ['job_id' => $id, 'rider_id' => $rid, 'job_uid' => $job_uid, 'attempts' => $attempts]);
    log_line($log, "JOB START id=$id rider=$rid");

    put_state($job_uid, ['pct'=>5, 'stage'=>'Validant rider…', 'log'=>'Worker engegat']);

    $startedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s');
    
    // ✨ Límit de memòria per job
    $memLimit = ini_get('memory_limit');
    ini_set('memory_limit', '512M');
    
    $tmpPdf = null;
    $txtPath = null;

    try {
      /* -----------------------------------------
         1) Validacions bàsiques del rider
      ------------------------------------------*/
      $rs = $pdo->prepare("SELECT ID_Usuari, Rider_UID, Estat_Segell, Object_Key FROM Riders WHERE ID_Rider=? LIMIT 1");
      $rs->execute([$rid]);
      $r = $rs->fetch(PDO::FETCH_ASSOC);

      put_state($job_uid, [
        'pct'       => 5,
        'stage'     => 'Validació de rider…',
        'log'       => 'Verificant estat',
        'uid'       => (int)($r['ID_Usuari'] ?? 0),
        'rider_uid' => (string)($r['Rider_UID'] ?? ''),
      ]);

      if (!$r) { throw new RuntimeException('rider_not_found'); }
      
      $estat = strtolower((string)($r['Estat_Segell'] ?? ''));
      if ($estat === 'validat' || $estat === 'caducat') {
        throw new RuntimeException('rider_locked');
      }
      
      $objectKey = trim((string)($r['Object_Key'] ?? ''));
      if ($objectKey === '') { throw new RuntimeException('no_object_key'); }

      /* -----------------------------------------
         2) Baixada amb validació de mida
      ------------------------------------------*/
      put_state($job_uid, ['pct'=>20, 'stage'=>'Downloading PDF…', 'log'=>'R2 getObject']);
      [$tmpPdf, $bytes] = r2_download_to_tmp($objectKey);
      
      // ✨ Validació de mida
      $maxSize = 50 * 1024 * 1024; // 50MB
      if ($bytes > $maxSize) {
        throw new RuntimeException("pdf_too_large: {$bytes} bytes (max {$maxSize})");
      }
      
      log_line($log, "downloaded: $objectKey -> $tmpPdf ($bytes bytes)");

      /* -----------------------------------------
         3) Extracció amb timeout
      ------------------------------------------*/
      put_state($job_uid, ['pct'=>50, 'stage'=>'Extraient text…', 'log'=>'pdftotext (max 30s)']);
      [$txtPath, $plain] = pdf_to_text_paths($tmpPdf, 30);
      $chars = mb_strlen($plain, 'UTF-8');
      log_line($log, "extracted: $txtPath ($chars chars)");

      /* -----------------------------------------
         4) Heurístiques i scoring
      ------------------------------------------*/
      put_state($job_uid, ['pct'=>80, 'stage'=>'Puntuant…', 'log'=>'scoring v2']);
      $heu = run_heuristics($plain, $tmpPdf);
      $score = (int)$heu['score'];
      $comments = isset($heu['comments']) && is_array($heu['comments']) ? $heu['comments'] : [];
      $suggestion = isset($heu['suggestion_block']) && is_string($heu['suggestion_block']) ? $heu['suggestion_block'] : null;
      $status = 'ok';

      if ($chars === 0) {
        $heu['rules']['text_extracted'] = false;
        if ($score > 60) $score = 50;
      }

      $missing = array_keys(array_filter($heu['rules'] ?? [], fn($v) => $v === false));
      $summaryParts = ['Anàlisi heurístic'];
      if (!empty($missing)) {
        $summaryParts[] = 'mancances: ' . implode(', ', $missing);
      }
      $summaryParts[] = "score=$score";
      if (!empty($comments)) {
        $top2 = array_slice($comments, 0, 2);
        $summaryParts[] = 'notes: ' . implode(' | ', $top2);
      }
      $summary = implode(' · ', $summaryParts);

      $details = json_encode($heu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $statePatch = ['pct'=>92, 'stage'=>'Generant resum…', 'score'=>$score];
      if (!empty($comments))   $statePatch['comments'] = $comments;
      if (!empty($suggestion)) $statePatch['suggestion'] = mb_substr($suggestion, 0, 4000, 'UTF-8');
      put_state($job_uid, $statePatch);

      $finishedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s');

      /* -----------------------------------------
         5) Persistir run
      ------------------------------------------*/
      $ins = $pdo->prepare(
        "INSERT INTO ia_runs
           (rider_id, job_uid, started_at, finished_at, status, score, bytes, chars, log_path, error_msg, summary_text, details_json)
         VALUES
           (:rid, :job, :st, :fin, :status, :score, :bytes, :chars, :logp, :err, :sum, :det)"
      );
      $ins->execute([
        ':rid'   => $rid, ':job' => $job_uid,
        ':st'    => $startedAt, ':fin' => $finishedAt,
        ':status'=> $status, ':score' => $score, ':bytes' => $bytes, ':chars' => $chars,
        ':logp'  => $log, ':err' => null, ':sum' => $summary, ':det' => $details
      ]);

      // ✨ Neteja temporals
      safe_unlink($tmpPdf);
      safe_unlink($txtPath);

      /* -----------------------------------------
         6) Desa resultat: valoració i estat 'pendent'
      ------------------------------------------*/
      $nouEstat = 'pendent'; // default
      $dataPublicacio = null;

      $sql = "UPDATE Riders 
              SET Valoracio = :v, 
                  Estat_Segell = :estat,
                  Data_Publicacio = " . ($dataPublicacio ?: 'NULL') . ",
                  Data_IA = NOW()
              WHERE ID_Rider = :rid
              LIMIT 1";

      $pdo->prepare($sql)->execute([
        ':v' => $score, 
        ':estat' => $nouEstat,
        ':rid' => $rid
      ]);

      log_line($log, "Rider updated: estat=$nouEstat score=$score");

      /* -----------------------------------------
         7) Estat final UI + marcar job OK
      ------------------------------------------*/
      put_state($job_uid, [
        'pct'=>100, 
        'stage'=>'Fet', 
        'done'=>true, 
        'score'=>$score,
        'estat'=>$nouEstat,
        'log'=>'Job completat'
      ]);
      
      wlog('job_done', ['job_id' => $id, 'rider_id' => $rid, 'status' => 'ok', 'score' => $score, 'estat' => $nouEstat]);
      finish_job($pdo, $id, 'ok', null);
      $processed++;
      log_line($log, "JOB DONE status=ok score=$score estat=$nouEstat");
      
    } catch (Throwable $e) {
      // Neteja temporals en error
      safe_unlink($tmpPdf);
      safe_unlink($txtPath);
      
      $up = $pdo->prepare("UPDATE ia_jobs SET attempts=attempts+1, error_msg=:m WHERE id=:id");
      $up->execute([':m' => $e->getMessage(), ':id' => $id]);
      $attempts++;

      put_state($job_uid, [
        'pct'=>100, 
        'stage'=>'Error', 
        'done'=>true, 
        'error'=>$e->getMessage(), 
        'log'=>'ERROR: '.$e->getMessage()
      ]);
      
      wlog('job_error', [
        'job_id' => $id,
        'rider_id' => $rid,
        'attempt' => $attempts,
        'max' => $maxAtt,
        'message' => $e->getMessage()
      ], 'error');

      if ($attempts >= $maxAtt) {
        finish_job($pdo, $id, 'error', $e->getMessage());
        $processed++;
        log_line($log, "JOB FAIL (final): " . $e->getMessage());
      } else {
        $bof = min(300, 15 * $attempts);
        log_line($log, "JOB FAIL: " . $e->getMessage() . " — retry in ~{$bof}s");
        usleep(200_000);
        $pdo->prepare("UPDATE ia_jobs SET status='queued' WHERE id=?")->execute([$id]);
      }
    } finally {
      // ✨ Restaura límit de memòria
      ini_set('memory_limit', $memLimit);
    }
  }

  // ✨ Health check
  try {
    $healthFile = sys_get_temp_dir() . '/ia_worker_health.json';
    file_put_contents($healthFile, json_encode([
      'last_run' => time(),
      'last_run_iso' => date('c'),
      'processed' => $processed,
      'pid' => getmypid(),
      'version' => 'ia-worker-2.0'
    ]));
  } catch (Throwable $e) {
    wlog('health_check_failed', ['error' => $e->getMessage()], 'warn');
  }

  $pdo->query("SELECT RELEASE_LOCK('ia_worker_lock')");
  $dur = (int)round((microtime(true) - $t0) * 1000);
  $rss = (int)round(memory_get_usage(true) / 1048576);
  wlog('tick_end', ['processed' => $processed, 'duration_ms' => $dur, 'rss_mb' => $rss]);

} catch (Throwable $e) {
  wlog('fatal', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'error');
  ks_log_cli('fatal: ' . $e->getMessage());
  try { $pdo->query("SELECT RELEASE_LOCK('ia_worker_lock')"); } catch (Throwable $ignored) {}
  exit(1);
}