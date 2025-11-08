<?php
// php/ia_admin_tools.php — eines d'admin per a IA (diagnòstic i fix)
/** Requereix $pdo (PDO) present al context **/
declare(strict_types=1);

// --- CSRF helpers (mínims)
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
  }
}
if (!function_exists('csrf_check')) {
  function csrf_check(string $t): bool {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
  }
}

// --- Format de data europeu (fallback local)
if (!function_exists('dt_eu')) {
  function dt_eu($dt, string $fmt = 'd/m/Y H:i'): string {
    if ($dt instanceof DateTimeInterface) return $dt->format($fmt);
    if (is_string($dt) && $dt !== '') {
      try { return (new DateTimeImmutable($dt))->format($fmt); } catch (Throwable $e) {}
    }
    return '—';
  }
}

// --- Diagnòstic: retorna arrays amb incidències
function ia_diag(PDO $pdo): array {
  // A) Duplicats actius per rider
  $qA = $pdo->query("
    SELECT rider_id, COUNT(*) AS actius
    FROM ia_jobs
    WHERE status IN ('queued','running')
    GROUP BY rider_id
    HAVING COUNT(*) > 1
  ");
  $dup_actius = $qA->fetchAll(PDO::FETCH_ASSOC);

  // B) Jobs acabats sense run
  $qB = $pdo->query("
    SELECT j.*
    FROM ia_jobs j
    LEFT JOIN ia_runs r ON r.job_uid COLLATE utf8mb4_unicode_ci = j.job_uid COLLATE utf8mb4_unicode_ci
    WHERE j.status IN ('ok','error') AND r.job_uid IS NULL
    ORDER BY (j.finished_at IS NULL) ASC, j.finished_at DESC, j.id DESC
    LIMIT 200
  ");
  $finished_without_run = $qB->fetchAll(PDO::FETCH_ASSOC);

  // C) Runs orfes
  $qC = $pdo->query("
    SELECT r.*
    FROM ia_runs r
    LEFT JOIN ia_jobs j ON j.job_uid COLLATE utf8mb4_unicode_ci = r.job_uid COLLATE utf8mb4_unicode_ci
    WHERE j.job_uid IS NULL
    ORDER BY r.started_at DESC, r.id DESC
    LIMIT 200
  ");
  $runs_orfes = $qC->fetchAll(PDO::FETCH_ASSOC);  // ← FALTAVA AIXÒ

  return [
    'dup_actius' => $dup_actius,
    'finished_without_run' => $finished_without_run,
    'runs_orfes' => $runs_orfes,
  ];
}

// --- Auto-neteja segura (en transacció). Retorna ['ok'=>true, 'log'=>[]]
function ia_fix(PDO $pdo): array {
  $log = [];
  try {
    $pdo->beginTransaction();

    // 1) Normalitzar duplicats actius (queued/running) per rider
    $log[] = 'Normalitzant duplicats actius (queued/running) per rider…';

    // a) keep RUNNING més antic
    $keepRunning = $pdo->query("
      SELECT rider_id, MIN(id) AS keep_id
      FROM ia_jobs
      WHERE status='running'
      GROUP BY rider_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    // b) si no hi ha RUNNING, keep QUEUED més antic
    $keepQueued = $pdo->query("
      SELECT j1.rider_id, MIN(j1.id) AS keep_id
      FROM ia_jobs j1
      LEFT JOIN (SELECT DISTINCT rider_id FROM ia_jobs WHERE status='running') r
        ON r.rider_id = j1.rider_id
      WHERE r.rider_id IS NULL AND j1.status='queued'
      GROUP BY j1.rider_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $keeps = $keepRunning + $keepQueued; // preferim RUNNING

    $allActive = $pdo->query("
      SELECT id, rider_id FROM ia_jobs
      WHERE status IN ('queued','running')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $toQueue = [];
    foreach ($allActive as $row) {
      $rid = (int)$row['rider_id'];
      $id  = (int)$row['id'];
      if (isset($keeps[$rid]) && $id !== (int)$keeps[$rid]) $toQueue[] = $id;
    }

    $affDup = 0;
    if ($toQueue) {
      foreach (array_chunk($toQueue, 1000) as $ch) {
        $in = implode(',', array_map('intval', $ch));
        $affDup += $pdo->exec("UPDATE ia_jobs SET status='queued' WHERE id IN ($in)");
      }
    }
    $log[] = "Duplicats normalitzats: afectats ~$affDup";

    // 2) Jobs finished sense ia_run → marcar error si cal
    $log[] = 'Marcant jobs finished sense run com a error…';
    $affB = $pdo->exec("
      UPDATE ia_jobs j
      LEFT JOIN ia_runs r ON r.job_uid COLLATE utf8mb4_unicode_ci = j.job_uid COLLATE utf8mb4_unicode_ci
      SET j.status='error',
          j.error_msg = COALESCE(NULLIF(j.error_msg,''),'no ia_run found')
      WHERE j.status IN ('ok','error') AND r.job_uid IS NULL
    ");
    $log[] = 'Jobs marcats error: ' . (int)$affB;

    // 3) Runs orfes → eliminar
    $log[] = 'Eliminant ia_runs orfes…';
    $affC = $pdo->exec("
      DELETE r FROM ia_runs r
      LEFT JOIN ia_jobs j ON j.job_uid COLLATE utf8mb4_unicode_ci = r.job_uid COLLATE utf8mb4_unicode_ci
      WHERE j.job_uid IS NULL
    ");
    $log[] = 'Runs eliminats: ' . (int)$affC;

    $pdo->commit();
    return ['ok'=>true, 'log'=>$log];

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $log[] = 'ERROR: '.$e->getMessage();
    return ['ok'=>false, 'log'=>$log];
  }
}