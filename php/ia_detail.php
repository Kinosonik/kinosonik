<?php
// php/ia_detail.php — Vista de diagnòstic d’un job d’IA (OWNER o ADMIN)
declare(strict_types=1);
require_once __DIR__ . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_utils.php'; // ai_has_active_job, ai_last_run, rider_can_auto_publish
require_once __DIR__ . '/middleware.php';
ks_require_role('tecnic','admin','productor');

// ───────────────────────── Redirect segur (fallback si ja hi ha output) ─────────────────────────
if (!function_exists('soft_redirect_or_alert')) {
  /**
   * Si no s'han enviat headers: fa header(Location) i exit.
   * Si ja hi ha output: mostra una alerta amb enllaç i exit (evita "headers already sent").
   */
  function soft_redirect_or_alert(string $url, string $msg, int $code = 302): void {
    if (!headers_sent()) {
      if ($code >= 300 && $code < 400) { http_response_code($code); }
      header('Location: ' . $url);
      exit;
    }
    // Fall back visual
    echo '<div class="container my-4"><div class="alert alert-warning d-flex align-items-center" role="alert">'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
        . ' <a class="btn btn-primary btn-sm ms-2" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Continuar</a>'
        . '</div></div>';
    exit;
  }
}

// ───────────────────────── Helpers locals (compat amb admin_logs) ─────────────────────────
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt_bytes')) {
  function fmt_bytes(int $b): string {
    $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < count($u)-1) { $b = (int)round($b/1024); $i++; }
    return $b . ' ' . $u[$i];
  }
}
if (!function_exists('dt_eu')) {
  function dt_eu(DateTimeInterface $dt): string { return $dt->format('d/m/Y H:i'); }
}
if (!function_exists('ago_short')) {
  function ago_short(DateTimeInterface $dt): string {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
    $diff = $now->getTimestamp() - $dt->getTimestamp();
    if ($diff < 60) return $diff.'s';
    if ($diff < 3600) return intdiv($diff,60).' min';
    if ($diff < 86400) return intdiv($diff,3600).' h';
    return intdiv($diff,86400).' d';
  }
}
if (!function_exists('safe_tail_bytes')) {
  function safe_tail_bytes(string $path, int $bytes = 8000): string {
    if (!is_file($path) || !is_readable($path)) return '';
    $size = @filesize($path);
    if (!is_int($size) || $size <= 0) return '';
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    if ($size > $bytes) fseek($fh, -$bytes, SEEK_END);
    $data = stream_get_contents($fh);
    fclose($fh);
    return (string)$data;
  }
}

// ───────────────────────── Modes lleugers (AJAX): estat i tail ─────────────────────────
$mode = (string)($_GET['mode'] ?? '');
if ($mode !== '') {
  $jobUid = (string)($_GET['job'] ?? '');
  // ✳️ Validació estricta del job (evita path traversal / noms maliciosos)
  $jobUid = strtolower(trim($jobUid));
  if ($jobUid !== '' && !preg_match('/^[a-z0-9_-]{1,64}$/', $jobUid)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'bad_job_uid'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($jobUid === '') { http_response_code(400); echo 'missing job'; exit; }

  // Carrega mínim per validar permisos (trobar rider_id i owner)
  $pdo = db();
  $st = $pdo->prepare("
    SELECT runs.rider_id, u.ID_Usuari AS owner_id, u.Tipus_Usuari AS role,
           runs.log_path
      FROM ia_runs runs
      JOIN Riders r  ON r.ID_Rider = runs.rider_id
      JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
     WHERE runs.job_uid = ?
     LIMIT 1
  ");
  $st->execute([$jobUid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  // 2n intent: ia_jobs (encara en cua, run inexistent)
  if (!$row) {
    $st2 = $pdo->prepare("
      SELECT j.rider_id, u.ID_Usuari AS owner_id, u.Tipus_Usuari AS role,
             NULL AS log_path
        FROM ia_jobs j
        JOIN Riders r  ON r.ID_Rider = j.rider_id
        JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
       WHERE j.job_uid = ?
       LIMIT 1
    ");
    $st2->execute([$jobUid]);
    $row = $st2->fetch(PDO::FETCH_ASSOC);
  }
  if (!$row) { http_response_code(404); echo 'job not found'; exit; }

  // Permisos OWNER/ADMIN (basat en l'usuari actual, no en l'owner)
  $uid  = $_SESSION['user_id'] ?? null;
  $curRole = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
  $isAdmin = ($curRole === 'admin' || (!empty($_SESSION['is_admin'])));
  if (!$uid) {
    // En modes AJAX respon JSON, no HTML
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $isOwner = ((int)$uid === (int)$row['owner_id']);
  if (!($isOwner || $isAdmin)) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Estat i/o tail / report
  $stateFile = "/tmp/ai-" . $jobUid . ".json";
  $logPath   = (string)($row['log_path'] ?? '');
  if ($logPath === '' && defined('KS_SECURE_LOG_DIR')) {
    $logPath = rtrim((string)KS_SECURE_LOG_DIR, '/')."/ia/run_".$jobUid.".log";
  }

  // Endpoint lleuger per saber si ja existeix a ia_runs
  if ($mode === 'hasrun') {
    header('Content-Type: application/json; charset=UTF-8');
    $chk = $pdo->prepare("SELECT 1 FROM ia_runs WHERE job_uid=? LIMIT 1");
    $chk->execute([$jobUid]);
    echo json_encode(['ok'=>true, 'exists'=>(bool)$chk->fetchColumn()], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($mode === 'state') {
    header('Content-Type: application/json; charset=UTF-8');
    $state = null;
    if (is_file($stateFile)) {
      $raw = @file_get_contents($stateFile);
      if (is_string($raw) && $raw !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $state = $tmp;
      }
    }
    echo json_encode([
      'ok'    => true,
      'state' => $state,
      'exists'=> ['state' => (bool)$state, 'log' => (is_file($logPath) ? true : false)],
      'now'   => (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($mode === 'tail') {
    header('Content-Type: application/json; charset=UTF-8');
    $tail = is_file($logPath) ? safe_tail_bytes($logPath, 12000) : '';
    echo json_encode([
      'ok'   => true,
      'size' => is_file($logPath) ? (int)@filesize($logPath) : 0,
      'tail' => $tail,
      'path' => $logPath,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ───────────── Informe enriquit (JSON/CSV) ─────────────
  if ($mode === 'report') {
    $format = strtolower((string)($_GET['format'] ?? 'json'));
    $pdo = db();
    $stR = $pdo->prepare("
      SELECT runs.id AS run_id, runs.job_uid, runs.summary_text, runs.details_json ,runs.rider_id, runs.status, runs.score,
             runs.started_at, runs.finished_at, runs.log_path,
             r.Rider_UID, r.Descripcio, r.Referencia, r.Estat_Segell, r.Valoracio,
             u.Email_Usuari
        FROM ia_runs runs
        JOIN Riders r  ON r.ID_Rider = runs.rider_id
        JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
       WHERE runs.job_uid = ?
       LIMIT 1
    ");
    $stR->execute([$jobUid]);
    $run = $stR->fetch(PDO::FETCH_ASSOC) ?: null;

    // carrega estat lleuger
    $state = null;
    if (is_file($stateFile)) {
      $raw = @file_get_contents($stateFile);
      if (is_string($raw) && $raw !== '') { $tmp = json_decode($raw, true); if (is_array($tmp)) $state = $tmp; }
    }

   $summary = [
      'job_uid'     => $jobUid,
      'rider_uid'   => $run['Rider_UID']   ?? null,
      'rider_id'    => isset($run['rider_id']) ? (int)$run['rider_id'] : null,
      'owner_email' => $run['Email_Usuari']?? null,
      'desc'        => $run['Descripcio']  ?? null,
      'ref'         => $run['Referencia']  ?? null,
      'run_status'  => $run['status']      ?? null,
      'run_score'   => isset($run['score']) ? (int)$run['score'] : null,
      'started_at'  => $run['started_at']  ?? null,
      'finished_at' => $run['finished_at'] ?? null,
      'seal'        => $run['Estat_Segell']?? null,
      'ai_state'    => [
        'pct'   => isset($state['pct'])   ? (int)$state['pct']   : null,
        'stage' => isset($state['stage']) ? (string)$state['stage'] : null,
        'score' => isset($state['score']) ? (int)$state['score'] : null,
        'notes' => isset($state['notes']) && is_array($state['notes']) ? $state['notes'] : null,
        'metrics' => isset($state['metrics']) && is_array($state['metrics']) ? $state['metrics'] : null,
      ],
      'log_exists'  => is_file($logPath),
      'log_path'    => $logPath ?: null,
      'generated_at'=> (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('c'),
    ];

    if ($format === 'csv') {
      // ✳️ BOM per Excel
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="ia_report_'.$jobUid.'.csv"');
      echo "\xEF\xBB\xBF";
      $out = fopen('php://output', 'w');
      // capçalera plana
      fputcsv($out, [
        'job_uid','rider_uid','rider_id','owner_email','desc','ref',
        'run_status','run_score','started_at','finished_at','seal',
        'ai_pct','ai_stage','ai_score','log_exists','generated_at'
      ]);
      fputcsv($out, [
        $summary['job_uid'], $summary['rider_uid'], $summary['rider_id'], $summary['owner_email'],
        $summary['desc'], $summary['ref'], $summary['run_status'], $summary['run_score'],
        $summary['started_at'], $summary['finished_at'], $summary['seal'],
        $summary['ai_state']['pct'], $summary['ai_state']['stage'], $summary['ai_state']['score'],
        $summary['log_exists'] ? '1':'0', $summary['generated_at']
      ]);
      // si hi ha metrics, bolquem clau;valor
      if (is_array($summary['ai_state']['metrics'])) {
        fputcsv($out, []); fputcsv($out, ['metrics_key','metrics_value']);
        foreach ($summary['ai_state']['metrics'] as $k=>$v) {
          $val = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
          fputcsv($out, [$k, $val]);
        }
      }
      // si hi ha notes, una per línia
      if (is_array($summary['ai_state']['notes'])) {
        fputcsv($out, []); fputcsv($out, ['notes']);
        foreach ($summary['ai_state']['notes'] as $n) {
          fputcsv($out, [is_scalar($n) ? (string)$n : json_encode($n, JSON_UNESCAPED_UNICODE)]);
        }
      }
      fclose($out);
      exit;
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>true,'report'=>$summary], JSON_UNESCAPED_UNICODE);
    exit;
  }

  http_response_code(400); echo 'bad mode'; exit;
}

// ───────────────────────── Vista principal ─────────────────────────
try {
  $pdo = db();

  // Inputs
  $jobUid  = (string)($_GET['job'] ?? '');
  $jobUid = strtolower(trim($jobUid));
  if ($jobUid !== '' && !preg_match('/^[a-z0-9_-]{1,64}$/', $jobUid)) {
    http_response_code(400);
    echo '<div class="container my-5 alert alert-danger">Job UID invàlid.</div>';
    exit;
  }
  $riderId = (int)($_GET['rider'] ?? 0);

  if ($jobUid === '' && $riderId > 0) {
    $st = $pdo->prepare("SELECT job_uid FROM ia_runs WHERE rider_id=? ORDER BY started_at DESC, id DESC LIMIT 1");
    $st->execute([$riderId]);
    $jobUid = (string)($st->fetchColumn() ?: '');
  }
  if ($jobUid === '') { http_response_code(404); echo '<div class="container my-5 alert alert-danger">Job no trobat.</div>'; exit; }

  // Carrega run + rider + owner (alineat amb admin_logs.php)
  $st = $pdo->prepare("
  SELECT
    runs.id            AS run_id,
    runs.job_uid,
    runs.rider_id,
    runs.status,
    runs.score,
    runs.summary_text,
    runs.details_json,
    NULL AS stage,
    runs.started_at,
    runs.log_path,
    r.ID_Rider         AS rider_pk,
    r.Rider_UID,
    r.Descripcio,
    r.Estat_Segell,
    r.Valoracio,
    u.ID_Usuari        AS owner_id,
    u.Email_Usuari,
    u.Tipus_Usuari     AS role
  FROM ia_runs runs
  JOIN Riders r  ON r.ID_Rider = runs.rider_id
  JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
  WHERE runs.job_uid = ?
  LIMIT 1
");
$st->execute([$jobUid]);
$run = $st->fetch(PDO::FETCH_ASSOC);

// Si encara no hi ha run a ia_runs, mira a ia_jobs i prepara “waiting view”
$waiting = false;
if (!$run) {
  $st2 = $pdo->prepare("
    SELECT
      j.job_uid,
      j.rider_id,
      j.status,
      j.created_at,
      r.ID_Rider         AS rider_pk,
      r.Rider_UID,
      r.Descripcio,
      r.Estat_Segell,
      r.Valoracio,
      u.ID_Usuari        AS owner_id,
      u.Email_Usuari,
      u.Tipus_Usuari     AS role
    FROM ia_jobs j
    JOIN Riders r  ON r.ID_Rider = j.rider_id
    JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
    WHERE j.job_uid = ?
    LIMIT 1
  ");
  $st2->execute([$jobUid]);
  $job = $st2->fetch(PDO::FETCH_ASSOC);
  if ($job) {
    $waiting = true;
    // Construeix un $run mínim perquè la vista funcioni
    $run = [
      'run_id'=>null,'job_uid'=>$job['job_uid'],'rider_id'=>$job['rider_id'],
      'status'=>$job['status'] ?? 'queued','score'=>null,'stage'=>null,
      'started_at'=>$job['created_at'] ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s'),
      'log_path'=>'',
      'summary_text'=>null,
      'details_json'=>null,
      'rider_pk'=>$job['rider_pk'],'Rider_UID'=>$job['Rider_UID'],'Descripcio'=>$job['Descripcio'],
      'Estat_Segell'=>$job['Estat_Segell'],'Valoracio'=>$job['Valoracio'],
      'owner_id'=>$job['owner_id'],'Email_Usuari'=>$job['Email_Usuari'],'role'=>$job['role'],
    ];
  } else {
    echo '<div class="alert alert-danger m-4">Job no trobat.</div>'; exit;
  }
}

  // Permisos OWNER/ADMIN
  $uid = $_SESSION['user_id'] ?? null;
  if (!$uid) {
    soft_redirect_or_alert(BASE_PATH . 'index.php?error=login_required', 'Cal iniciar sessió per veure aquest detall', 302);
  }
  $isOwner = ((int)$uid === (int)$run['owner_id']);
  // ✳️ Mira rol de l'usuari actual, no del propietari del rider
  $curRole = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
  $isAdmin = ($curRole === 'admin' || (!empty($_SESSION['is_admin'])));
  if (!($isOwner || $isAdmin)) {
    soft_redirect_or_alert(BASE_PATH . 'espai.php?seccio=dades&error=forbidden', 'No tens permisos per accedir a aquest job', 302);
  }
  $adminViewingForeign = ($isAdmin && !$isOwner);


  // Files: estat i log
  $stateFile = "/tmp/ai-" . $jobUid . ".json";
  $state = null;
  if (is_file($stateFile)) {
    $raw = @file_get_contents($stateFile);
    if (is_string($raw) && $raw !== '') {
      $tmp = json_decode($raw, true);
      if (is_array($tmp)) $state = $tmp;
    }
  }
  $logPath = (string)($run['log_path'] ?? '');
  if ($logPath === '' && defined('KS_SECURE_LOG_DIR')) {
    $logPath = rtrim((string)KS_SECURE_LOG_DIR, '/')."/ia/run_".$jobUid.".log";
  }
  $logExists = ($logPath !== '' && is_file($logPath));
  $logTail   = $logExists ? safe_tail_bytes($logPath, 12000) : '';

  // Flag utilitzat a la UI per mostrar “En cua…” i auto-reload
  // ── Progrés i etapa (càlcul abans d'imprimir HTML)
  $pct   = null;
  $stage = null;
  if (is_array($state)) {
    if (isset($state['pct']))   { $pct   = (int)$state['pct']; }
    if (isset($state['stage'])) { $stage = (string)$state['stage']; }
  }
  if ($pct === null) {
    if ((string)$run['status'] === 'ok')        { $pct = 100; $stage = $stage ?: 'Fet'; }
    elseif ((string)$run['status'] === 'error') { $pct = 100; $stage = $stage ?: 'Error'; }
    else                                        { $pct = 0;   $stage = $stage ?: 'Inici'; }
  }
  if ($pct < 0)   $pct = 0;
  if ($pct > 100) $pct = 100;

  // Classe de color per a la barra de progrés
  $barClass = 'bg-info'; // estat informatiu per defecte
  if (!empty($waiting)) {
    $barClass = 'bg-secondary';               // en cua
  } elseif ((string)$run['status'] === 'ok') {
    $barClass = 'bg-success';                 // verd
  } elseif ((string)$run['status'] === 'error') {
    $barClass = 'bg-danger';                  // vermell
  } elseif ($pct < 100) {
    $barClass = 'bg-info';                    // en curs
  }


  // Polítiques de publicació
  $rider = [
    'ID'                    => (int)$run['rider_pk'],
    'Estat_Segell'          => (string)($run['Estat_Segell'] ?? ''),
    'Valoracio'             => isset($run['Valoracio']) ? (int)$run['Valoracio'] : null,
    'Validacio_Manual_Pendent' => (int)($run['Validacio_Manual_Pendent'] ?? 0),
  ];
  $pub = rider_can_auto_publish($pdo, $rider);

  // Històric ràpid del rider
  $st = $pdo->prepare("
  SELECT job_uid, status, score, started_at
  FROM ia_runs
  WHERE rider_id = ?
  ORDER BY started_at DESC, id DESC
  LIMIT 10
");
$st->execute([(int)$run['rider_id']]);
$history = $st->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  http_response_code(500);
  echo '<pre style="padding:1rem">ERROR: '.h($e->getMessage()).'</pre>';
  exit;
}

// ───────────────────────── IA: resum i detalls (summary_text + details_json) ─────────────────────────
$aiSummaryText = '';
$aiDetails     = [];
$aiComments    = [];
$aiSuggestion  = null;
try {
  $aiSummaryText = (string)($run['summary_text'] ?? '');
  if (!empty($run['details_json'])) {
    $aiDetails = json_decode((string)$run['details_json'], true) ?: [];
    if (!empty($aiDetails['comments']) && is_array($aiDetails['comments'])) {
      $aiComments = $aiDetails['comments'];
    }
    if (!empty($aiDetails['suggestion_block']) && is_string($aiDetails['suggestion_block'])) {
      $aiSuggestion = $aiDetails['suggestion_block'];
    }
  }
} catch (Throwable $e) {
  // silenciós: si el JSON no és vàlid, simplement no mostrem el bloc enriquit
}
?>
<!-- Capçalera -->
<div class="container w-75">
  <!-- Títol -->
  <div class="d-flex justify-content-between mb-3 border-bottom border-1 border-secondary">
    <h4><i class="bi bi-robot"></i>&nbsp;&nbsp;<?= __('iadetail.titol') ?></h4> 
  </div>
  <!-- Avís ADMIN que no es el seu rider -->
  <?php if (!empty($adminViewingForeign)): ?>
  <div class="card-body w-100">
    <div class="alert alert-warning d-flex align-items-center py-2 small mb-3">
      <i class="bi bi-shield-lock me-2"></i>
      <div>
        <strong>Mode administrador:</strong> estàs veient el job d’un altre usuari
        (<span class="text-secondary"><?= h((string)$run['Email_Usuari']) ?></span>).
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Informació tècnica del rider analitzat -->
  <div class="d-flex mb-1 small text-secondary">
    <div class="w-100">
      <div class="row row-cols-auto justify-content-start text-start">
        <!-- RIDER i OBRIR PDF-->
        <div class="col">
          <?= __('iadetail.descripcio') ?>: 
          <span class="text-light"><?= h((string)($run['Descripcio'] ?? '—')) ?></span>&nbsp;
          <a target="_blank"
            href="<?= h(BASE_PATH) ?>php/rider_file.php?ref=<?= h((string)$run['Rider_UID']) ?>">
            <i class="bi bi-filetype-pdf"></i>
          </a>
        </div>
        <!-- JOB UID -->
        <div class="col">
          Job UID:
          <span class="text-light"
                title="Clica per copiar" style="cursor:pointer"
                data-copy="<?= h($run['job_uid']) ?>"
                onclick="navigator.clipboard.writeText(this.dataset.copy).then(()=>showCopyToast('Job UID copiat'));">
            <?= h($run['job_uid']) ?>
          </span>
        </div>
        <!-- Rider UID -->
        <div class="col">Rider UID: <span class="text-light"><?= h((string)$run['Rider_UID']) ?></span></div>
        <!-- E-mail propietari -->
        <div class="col">E-mail: <span class="text-light"><?= h((string)$run['Email_Usuari']) ?></span></div>
        <!-- Score IA -->
        <div class="col">Score IA: <span class="text-light" id="ai-score-pill"><?= isset($state['score'])? (int)$state['score'] : '—' ?></span></div>
      </div>
    </div>
  </div>

  <div class="d-flex mb-1 small text-secondary">
    <div class="w-100">
      <div class="row row-cols-auto justify-content-start text-start">
        <!-- Data anàlisi -->
        <?php
          $whenEU='—'; $whenRel=''; 
          try { $dt = new DateTimeImmutable((string)$run['started_at'], new DateTimeZone('Europe/Madrid')); $whenEU=dt_eu($dt); $whenRel=' ('.ago_short($dt).')'; } catch(Throwable $e){}
        ?>
        <div class="col"><?= __('iadetail.dataanalisi') ?>: <span class="text-light"><?= h($whenEU.$whenRel) ?></span></div>
        <!-- Estat anàlisi -->
        <div class="col"><?= __('iadetail.estat') ?>: <span class="text-light"><?= h((string)$run['status']) ?></span></div>
        <!-- Etapa anàlisi -->
        <div class="col"><?= __('iadetail.etapa') ?>: <span class="text-light" id="stage-now"><?= h($stage) ?></span></div>
        <!-- Publicació -->
        <div class="col">
          <?= __('iadetail.publicacio') ?>:
          <?php if (!empty($pub['enabled'])): ?>
            <span class="text-success"><?= __('iadetail.apunt') ?></span>
          <?php else: ?>
            <span class="text-warning" title="<?= h((string)($pub['reason'] ?? '—')) ?>">No (<?= h((string)($pub['reason'] ?? '—')) ?>)</span>
          <?php endif; ?>
        </div>
        <!-- Darrera publicació -->
        <div class="col"><?= __('iadetail.darreraactualitzacio') ?>: <span class="text-light" id="ai-last-update">—:—:—</span></div>
      </div>
    </div>
  </div>

  <!-- BARRA DE PROGRÉS + INFO -->
  <div class="card border-0 bg-light-subtle mt-3 mb-3">
    <div class="card-body py-2">
      <!-- Barra de progrés molt fina de punta a punta -->
      <div class="w-100">
        <div class="progress mt-1" style="height:2px">
          <div class="progress-bar <?= h($barClass) ?>" role="progressbar"
               style="width: <?= $pct ?>%;" id="bar-pct"
               aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"
               aria-label="Progrés anàlisi" aria-live="polite">
               <span id="bar-pct-text" class="visually-hidden"><?= $pct ?>%</span>
          </div>
        </div>
      </div>
      <!-- Mètriques clau (una sola línia) -->
      <div class="d-flex small gap-3 mt-2">
        <div><span class="text-secondary"><?= __('iadetail.etapa') ?>:</span> <span id="ai-stage-pill"><?= h($stage ?? '—') ?></span></div>
        <div><span class="text-secondary"><?= __('iadetail.progres') ?>:</span> <span><span id="ai-pct-pill"><?= (int)$pct ?></span> %</span></div>
        <div>
          <?php if (isset($state['score'])): ?>
          <span class="text-secondary"><?= __('iadetail.scoreprovisional') ?>:</span>
          <span id="score-now"><?= h((string)$state['score']) ?></span>
          <span class="text-secondary">/100</span>
          <?php else: ?>
          <span id="score-now">—</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($state['metrics']) && is_array($state['metrics'])):
  // Tria 5 claus “clau” ordenades pel seu valor (si és numèric).
  $metrics = $state['metrics'];
  uasort($metrics, function($a, $b){
    $na = is_numeric($a) ? (float)$a : (is_bool($a) ? ($a?1:0) : -1);
    $nb = is_numeric($b) ? (float)$b : (is_bool($b) ? ($b?1:0) : -1);
    return $nb <=> $na;
  });
  $top = array_slice($metrics, 0, 5, true);
  ?>
  <div class="mt-2 mb-2 small">
    <span class="text-secondary"><?= __('iadetail.quemes') ?>:</span>
    <?php foreach ($top as $k=>$v): ?>
    <span class="text-bg-secondary me-1 mb-1">
      <?= h((string)$k) ?><?= (is_scalar($v)? ': '.h((string)$v) : '') ?>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- EL CUADRE DE SOTA -->
  <!-- Resultats heurístics (summary_text + comments + suggestion_block) -->
  <?php if ($aiSummaryText !== '' || !empty($aiComments) || $aiSuggestion !== null): ?>
  <div class="card border-0 bg-light-subtle mb-3">
    <div class="card-header bg-transparent pb-0">
      <!-- Títol Resultats de l'anàlisis -->
      <div class="d-flex align-items-center justify-content-between">
        <h6 class="mb-2"><?= __('iadetail.resultats') ?></h6>
      </div>
    </div>

    <div class="card-body pt-2">
      <?php if ($aiSummaryText !== ''): ?>
      <div class="mb-2">
        <div class="small text-secondary mb-1"><?= __('iadetail.resum') ?>:</div>
        <div class="small"><?= h($aiSummaryText) ?></div>
      </div>
      <?php endif; ?>

      <?php if (!empty($aiComments)): ?>
      <div class="mb-2">
        <div class="small text-secondary mb-1"><?= __('iadetail.comentaris') ?>:</div>
        <ul class="mb-0">
        <?php foreach ($aiComments as $c): ?>
          <li class="small text-light"><?= h(is_scalar($c) ? (string)$c : json_encode($c, JSON_UNESCAPED_UNICODE)) ?></li>
        <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <!-- Convertir en ACORDION -->
      <?php if (!empty($aiSuggestion)): ?>
      <div class="mb-2">
        <div class="small text-secondary mb-1"><?= __('iadetail.suggerencies') ?>:</div>
        <p id="aiSuggestion" class="small text-light bg-dark-subtle rounded p-2" style="white-space:pre-wrap; word-break:break-word; font-size:0.9em;"><?= h($aiSuggestion) ?></p>
      </div>
      <?php endif; ?>

      <?php if (!empty($aiDetails) && empty($aiComments) && $aiSummaryText===''): ?>
      <!-- Si no hi ha cap camp “bonic”, oferim el JSON sencer en collapsible com a fallback -->
      <details class="mt-2">
        <summary class="small text-secondary"><?= __('iadetail.detallsjson') ?>Detalls (JSON cru)</summary>
        <pre class="small bg-kinosonik p-2 rounded"
          style="white-space:pre-wrap; max-height:40vh; overflow:auto;"><?= h(json_encode($aiDetails, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
      </details>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Accions -->
  <div class="d-flex mb-1 small text-secondary gap-2">
    <button class="btn btn-primary btn-sm" id="btn-reenqueue"><i class="bi bi-robot"></i> <?= __('iadetail.reia') ?></button>
    <a class="btn btn-primary btn-sm" target="_blank"
      href="<?= h(BASE_PATH) ?>php/ia_detail.php?job=<?= h($run['job_uid']) ?>&mode=state">
      <?= __('iadetail.exportajson') ?>
    </a>
    <a class="btn btn-primary btn-sm" target="_blank"
      href="<?= h(BASE_PATH) ?>php/ia_detail.php?job=<?= h($run['job_uid']) ?>&mode=report&format=csv">
      <?= __('iadetail.exportacsv') ?>
    </a>
  </div>
  <hr>
  <!-- AVÍS ANÀLISIS CREANT-SE -->
  <?php if (!empty($waiting)): ?>
  <div class="alert alert-info py-2 small mb-3">
    <?= __('iadetail.jobcreantse') ?>
  </div>
  <?php endif; ?>

  <!-- Històric ràpid -->
  <?php if (!$waiting): ?>
  <?php if ($history): ?>
  <div class="d-flex align-items-center gap-2 mb-3">
    <label class="small text-secondary"><?= __('iadetail.altres') ?>: </label>
    <select id="sel-history" class="form-select form-select-sm text-secondary" style="max-width: 25em;">
      <?php foreach ($history as $hrow):
        $dtEU=''; try { $dtH = new DateTimeImmutable((string)$hrow['started_at'], new DateTimeZone('Europe/Madrid')); $dtEU=dt_eu($dtH); } catch(Throwable $e) {}
        $label = sprintf('%s — %s — score %s', $dtEU ?: '—', (string)$hrow['status'], (string)($hrow['score'] ?? '—'));
      ?>
      <option value="<?= h((string)$hrow['job_uid']) ?>" <?= $hrow['job_uid']===$run['job_uid']?'selected':''; ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary btn-sm" id="btn-go-history"><?= __('iadetail.anar') ?></button>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Log (tail) -->
  <div class="card bg-dark border-tertiary mb-4 small">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="text-secondary"><?= __('iadetail.log') ?> <i class="bi bi-arrow-down"></i></span>
      <span class="small text-secondary">
      <?php if ($logExists): ?>
        <?= h($logPath) ?> — <?= h(fmt_bytes((int)@filesize($logPath))) ?>
      <?php else: ?>
        <?= __('iadetail.lognotrobat') ?>
      <?php endif; ?>
      </span>
    </div>
    <div class="card-body p-2">
      <pre id="log-tail" class="mb-0 text-secondary" style="max-height:50vh;overflow:auto;white-space:pre-wrap"><?= h($logTail) ?></pre>
    </div>
  </div>

  <!-- Toasts -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
    <div id="toast-ok" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-header">
        <strong class="me-auto"><?= __('iadetail.operaciocompletada') ?></strong>
        <small><?= __('iadetail.ara') ?></small>
        <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
      </div>
      <div class="toast-body">Fet.</div>
    </div>

    <div id="toast-err" class="toast text-bg-danger" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-header">
        <strong class="me-auto"><?= __('iadetail.error') ?></strong>
        <small><?= __('iadetail.ara') ?></small>
        <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
      </div>
      <div class="toast-body"><?= __('iadetail.errorinesperat') ?></div>
    </div>
  </div>

  <div id="copyToast" style="position:fixed;top:12px;left:50%;transform:translateX(-50%);
    background:#222;color:#fff;padding:8px 12px;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.25);
    font-size:.85rem;z-index:1080;opacity:0;transition:opacity .2s, transform .2s; pointer-events:none;">
    Copiat!
  </div>
</div>

<script>
// Utils toasts (mateix patró que admin_logs.php)
(function(){
  window.showCopyToast = function(msg){
    const el = document.getElementById('copyToast'); if (!el) return;
    el.textContent = msg || 'Copiat!';
    el.style.opacity = '1'; el.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(window.__copyToastTimer);
    window.__copyToastTimer = setTimeout(()=>{
      el.style.opacity = '0'; el.style.transform = 'translateX(-50%) translateY(-6px)';
    }, 1200);
  };
  window.toastOk = function(msg){
    const t=document.getElementById('toast-ok'); if(!t)return;
    t.querySelector('.toast-body').textContent=msg||'Fet.'; new bootstrap.Toast(t).show();
  };
  window.toastErr = function(msg){
    const t=document.getElementById('toast-err'); if(!t)return;
    t.querySelector('.toast-body').textContent=msg||'Error.'; new bootstrap.Toast(t).show();
  };
})();

// Navegar a un altre job del mateix rider
document.getElementById('btn-go-history')?.addEventListener('click', ()=>{
  const sel = document.getElementById('sel-history');
  if (!sel) return;
  const job = sel.value;
  if (job) location.href = '<?= h(BASE_PATH) ?>espai.php?seccio=ia_detail&job=' + encodeURIComponent(job);
});

// Auto-refresh suau de l’estat i del tail (amb aturada quan finalitza)
(function(){
  const job = <?= json_encode((string)$run['job_uid']) ?>;
  const bar = document.getElementById('bar-pct');
  const stageNow = document.getElementById('stage-now');
  const scoreNow = document.getElementById('score-now');
  const tailEl = document.getElementById('log-tail');
  const pctText = document.getElementById('bar-pct-text');
  const lastUpdate = document.getElementById('ai-last-update');
  const pctPill = document.getElementById('ai-pct-pill');
  const stagePill = document.getElementById('ai-stage-pill');
  const scorePill = document.getElementById('ai-score-pill');

  let done = false;
  let emptyRuns = 0; // per detectar cues llargues

  function setBarClass(cls){
    if (!bar) return;
    bar.classList.remove('bg-success','bg-danger','bg-info','bg-secondary');
    if (cls) bar.classList.add(cls);
  }

  // Map curt d’etapes -> etiqueta humana
  function humanStage(s){
    if (!s) return 'Fet';
    const k = (''+s).toLowerCase();
    if (k.includes('queue') || k.includes('cua')) return <?= json_encode(__('iamsg.encua') ?: 'En cua', JSON_UNESCAPED_UNICODE) ?>;
    if (k.includes('init') || k.includes('pre')) return <?= json_encode(__('iamsg.inicialitzant') ?: 'Inicialitzant', JSON_UNESCAPED_UNICODE) ?>;
    if (k.includes('download') || k.includes('r2')) return <?= json_encode(__('iamsg.descarregant') ?: 'Descarregant', JSON_UNESCAPED_UNICODE) ?>;
    if (k.includes('pdf') && (k.includes('text') || k.includes('pdftotext'))) return <?= json_encode(__('iamsg.extracciotxt') ?: 'Extracció de text', JSON_UNESCAPED_UNICODE) ?>;
    if (k.includes('analy') || k.includes('heur')) return <?= json_encode(__('iamsg.analitzant') ?: 'Analitzant', JSON_UNESCAPED_UNICODE) ?>;
    if (k.includes('report') || k.includes('summary')) return <?= json_encode(__('iamsg.generantinforme') ?: 'Generant informe', JSON_UNESCAPED_UNICODE) ?>;
    if (k.includes('error') || k.includes('fail')) return <?= json_encode(__('iamsg.error') ?: 'Error', JSON_UNESCAPED_UNICODE) ?>;
    if (k.includes('done') || k.includes('fet') || k.includes('final')) return <?= json_encode(__('iamsg.finalitzant') ?: 'Finalitzant', JSON_UNESCAPED_UNICODE) ?>;
    return s;
  }

  function markDone(){
    if (done) return;
    done = true;
    (window.__iaDetailTimers||[]).forEach(t=>clearInterval(t));
    setBarClass(/error/i.test(stageNow?.textContent||'') ? 'bg-danger' : 'bg-success');
  }

  function refreshState(){
    fetch('<?= h(BASE_PATH) ?>php/ia_detail.php?mode=state&job=' + encodeURIComponent(job), {cache:'no-store'})
      .then(r=>r.ok?r.json():null)
      .then(j=>{
        if(!j||!j.ok) return;
        const st = j.state||{};
        let pct = parseInt(st.pct ?? 0, 10); if (isNaN(pct)) pct = 0;
        pct = Math.max(0, Math.min(100, pct));

        // Darrera actualització (HH:mm:ss)
        if (lastUpdate && j.now) {
          const d = new Date(j.now);
          lastUpdate.textContent = d.toLocaleTimeString('ca-ES', {hour12:false});
        }

        // Barra i pastilles
        if (bar) {
          bar.style.width = pct+'%';
          bar.setAttribute('aria-valuenow', String(pct));
        }
        if (pctText) pctText.textContent = pct+'%';
        if (pctPill) pctPill.textContent = pct;

        const stageHuman = humanStage(st.stage);
        if (stageNow) stageNow.textContent = stageHuman;
        if (stagePill) stagePill.textContent = stageHuman;

        if (typeof st.score !== 'undefined') {
          if (scoreNow) scoreNow.textContent = st.score;
          if (scorePill) scorePill.textContent = st.score;
        }

        // Color dinàmic
        if (/error/i.test(stageHuman)) {
          setBarClass('bg-danger');
        } else if (pct >= 100 || /final|fet|done/i.test(stageHuman)) {
          setBarClass('bg-success');
          markDone();
        } else if (pct === 0 && /cua|queue/i.test(stageHuman)) {
          setBarClass('bg-secondary');
        } else {
          setBarClass('bg-info');
        }

        // Si no hi ha cap estat ni log repetidament, mostra un avís suau
        if (!st || (Object.keys(st).length===0)) {
          emptyRuns++;
          if (emptyRuns === 75) { // ~5 minuts si cada 4s
            console.warn(<?= json_encode(__('iamsg.cualenta') ?: 'Cua aparentment lenta. Pots re-executar o esperar.', JSON_UNESCAPED_UNICODE) ?>);

          }
        } else {
          emptyRuns = 0;
        }
      }).catch(()=>{});
  }

  function refreshTail(){
    fetch('<?= h(BASE_PATH) ?>php/ia_detail.php?mode=tail&job=' + encodeURIComponent(job), {cache:'no-store'})
      .then(r=>r.ok?r.json():null)
      .then(j=>{
        if(!j||!j.ok) return;
        if (typeof j.tail === 'string' && tailEl) {
          const atBottom = (tailEl.scrollTop + tailEl.clientHeight >= tailEl.scrollHeight - 8);
          tailEl.textContent = j.tail;
          if (atBottom) tailEl.scrollTop = tailEl.scrollHeight;
        }
      }).catch(()=>{});
  }

  refreshState(); refreshTail();
  window.__iaDetailTimers = [
    setInterval(refreshState, 4000),
    setInterval(refreshTail, 6000),
  ];

  // Si el job encara no existeix a ia_runs, segueix amb el ping lleuger per fer reload
  <?php if (!empty($waiting)): ?>
  (function(){
    let tries = 0;
    function pingHasRun(){
      fetch('<?= h(BASE_PATH) ?>php/ia_detail.php?mode=hasrun&job=' + encodeURIComponent(job), {cache:'no-store'})
        .then(r=>r.ok?r.json():null)
        .then(j=>{
          if (j && j.ok && j.exists === true) {
            location.reload();
          } else if (++tries < 60) {
            setTimeout(pingHasRun, 5000);
          }
        }).catch(()=>{ if (++tries < 60) setTimeout(pingHasRun, 5000); });
    }
    setTimeout(pingHasRun, 3000);
  })();
  <?php endif; ?>
})();

// Acció: Re-executa IA (segons contracte d'api: POST id, retorna { ok, job, poll })
document.getElementById('btn-reenqueue')?.addEventListener('click', ()=>{
  const url = '<?= h(BASE_PATH) ?>php/ai_start.php';
  const fd  = new FormData();
  const btn = document.getElementById('btn-reenqueue');
  // CSRF
  fd.append('csrf', '<?= h((string)($_SESSION['csrf'] ?? '')) ?>');
  // ⚠️ El backend espera 'id' (ID_Rider)
  fd.append('id', '<?= (int)$run['rider_id'] ?>');

  // UI: spinner + disable
  if (btn) {
    btn.disabled = true;
    btn.dataset._oldHtml = btn.innerHTML;
    btn.innerHTML =
      '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
      + <?= json_encode(__('iamsg.reencua') ?: 'Re-encua', JSON_UNESCAPED_UNICODE) ?>;
  }

  fetch(url, {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json, text/plain, */*', 'X-Requested-With':'XMLHttpRequest' },
  })
  .then(async (r) => {
    const txt = await r.text();
    let data = null; try { data = JSON.parse(txt); } catch(_e){}
    if (!r.ok) {
      const m = (data && data.error) ? data.error : ('HTTP '+r.status+' — '+txt.slice(0,180));
      throw new Error(m);
    }
    if (data && data.ok && data.job) {
      toastOk('Job re-enqueuat');
      // petit delay per evitar “Job no trobat” si el worker encara no ha creat ia_runs
      setTimeout(()=>{
        location.href = '<?= h(BASE_PATH) ?>espai.php?seccio=ia_detail&job=' + encodeURIComponent(data.job);
      }, 1200);
      return;
    }
    throw new Error((data && data.error) ? data.error : 'Resposta inesperada');
  })
  .catch((err) => {
    console.error('ai_start error:', err);
    // Missatges amables pels codis més comuns del backend
    const map = {
      method_not_allowed: <?= json_encode(__('iamsg.nopermes') ?: 'Mètode no permès', JSON_UNESCAPED_UNICODE) ?>,
      csrf_invalid: <?= json_encode(__('iamsg.csrfcaducat') ?: 'Sessió caducada (CSRF). Torna a carregar la pàgina.', JSON_UNESCAPED_UNICODE) ?>,
      login_required: <?= json_encode(__('iamsg.iniciarsessio') ?: 'Has d’iniciar sessió', JSON_UNESCAPED_UNICODE) ?>,
      bad_id: <?= json_encode(__('iamsg.rideridinvalid') ?: 'Rider ID invàlid', JSON_UNESCAPED_UNICODE) ?>,
      not_found: <?= json_encode(__('iamsg.not_found') ?: 'Rider no trobat', JSON_UNESCAPED_UNICODE) ?>,
      forbidden: <?= json_encode(__('iamsg.forbidden') ?: 'No tens permisos per aquest rider', JSON_UNESCAPED_UNICODE) ?>,
      locked: <?= json_encode(__('iamsg.locked') ?: 'El rider és definitiu (validat/caducat)', JSON_UNESCAPED_UNICODE) ?>,
      already_running_or_queued: <?= json_encode(__('iamsg.already_running_or_queued') ?: 'Ja hi ha un job en marxa o en cua', JSON_UNESCAPED_UNICODE) ?>,
      queue_stalled: <?= json_encode(__('iamsg.queue_stalled') ?: 'La cua sembla aturada. Torna-ho a provar en uns minuts.', JSON_UNESCAPED_UNICODE) ?>,
      rate_limited: <?= json_encode(__('iamsg.rate_limited') ?: 'Massa intents seguits. Espera uns segons i torna-ho a provar.', JSON_UNESCAPED_UNICODE) ?>,
    };
    const msg = map[err.message] || err.message || <?= json_encode(__('iamsg.error_xarxa') ?: 'Error de xarxa.', JSON_UNESCAPED_UNICODE) ?>;
    toastErr(msg);
  })
  .finally(()=>{
    // UI: restore button si hi ha error (si no s’ha redirigit)
    if (btn) {
      if (!/espai\.php\?seccio=ia_detail/.test(location.href)) {
        btn.disabled = false;
        btn.innerHTML = btn.dataset._oldHtml || <?= json_encode(__('iadetail.reia') ?: 'Re-executa la IA.', JSON_UNESCAPED_UNICODE) ?>;
      }
    }
  });
});
</script>
<script>
// Acció: Publica amb segell
document.getElementById('btn-publish')?.addEventListener('click', ()=>{
  const url = '<?= h(BASE_PATH) ?>php/auto_publish_seal.php';
  const btn = document.getElementById('btn-publish');
  const fd  = new FormData();

  // CSRF
  fd.append('csrf', '<?= h((string)($_SESSION['csrf'] ?? '')) ?>');

  // ⬅️ BACKEND REQUEREIX AQUESTS DOS CAMPS
  fd.append('rider_id',  '<?= (int)$run['rider_id'] ?>');
  fd.append('rider_uid', '<?= h((string)$run['Rider_UID']) ?>');

  // UX: spinner + disable
  if (btn) {
    btn.disabled = true;
    btn.dataset._oldHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Publicant…';
  }

  fetch(url, {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json, text/plain, */*', 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(async (r) => {
    const txt = await r.text();
    let data = null; try { data = JSON.parse(txt); } catch(_) {}
    if (!r.ok) {
      const m = (data && data.error) ? data.error : ('HTTP '+r.status+' — '+txt.slice(0,180));
      throw new Error(m);
    }
    if (data && (data.ok === true || data.status === 'ok')) {
      toastOk('Publicat amb segell');
      setTimeout(()=>location.reload(), 800);
      return;
    }
    throw new Error((data && data.error) ? data.error : 'Resposta inesperada');
  })
  .catch((err) => {
    console.error('auto_publish_seal error:', err);
    const map = {
      bad_method: <?= json_encode(__('iamsg.nopermes') ?: 'Mètode no permès', JSON_UNESCAPED_UNICODE) ?>,
      csrf: <?= json_encode(__('iamsg.csrfcaducat') ?: 'Sessió caducada (CSRF). Torna a carregar la pàgina.', JSON_UNESCAPED_UNICODE) ?>,
      login_required: <?= json_encode(__('iamsg.iniciarsessio') ?: 'Has d’iniciar sessió', JSON_UNESCAPED_UNICODE) ?>,
      missing_params: <?= json_encode(__('iamsg.missing_params') ?: 'Falten paràmetres (id i uid)', JSON_UNESCAPED_UNICODE) ?>,
      not_found: <?= json_encode(__('iamsg.not_found') ?: 'Rider no trobat', JSON_UNESCAPED_UNICODE) ?>,
      forbidden: <?= json_encode(__('iamsg.forbidden') ?: 'No tens permisos per aquest rider', JSON_UNESCAPED_UNICODE) ?>,

      low_score: <?= json_encode(__('iamsg.low_score') ?: 'Puntuació insuficient per segellar', JSON_UNESCAPED_UNICODE) ?>,
      already_final: <?= json_encode(__('iamsg.already_final') ?: 'El rider ja està validat/caducat', JSON_UNESCAPED_UNICODE) ?>,
      tech_validation_pending: <?= json_encode(__('iamsg.tech_validation_pending') ?: 'Validació tècnica pendent', JSON_UNESCAPED_UNICODE) ?>,
      ai_job_active: <?= json_encode(__('iamsg.ai_job_active') ?: 'Hi ha un job IA actiu', JSON_UNESCAPED_UNICODE) ?>,
      image_stack_missing: <?= json_encode(__('iamsg.image_stack_missing') ?: 'Stack d’imatge no disponible (GD/Imagick)', JSON_UNESCAPED_UNICODE) ?>,
      r2_download: <?= json_encode(__('iamsg.r2_download') ?: 'Error baixant l’arxiu de R2', JSON_UNESCAPED_UNICODE) ?>,
      seal_process_failed: <?= json_encode(__('iamsg.seal_process_failed') ?: 'Error generant el segell', JSON_UNESCAPED_UNICODE) ?>,
      r2_upload_failed: <?= json_encode(__('iamsg.r2_upload_failed') ?: 'Error pujant el PDF segellat', JSON_UNESCAPED_UNICODE) ?>,
      internal: <?= json_encode(__('iamsg.internal') ?: 'Error intern', JSON_UNESCAPED_UNICODE) ?>,
    };
    const msg = map[err.message] || err.message || <?= json_encode(__('iamsg.error_xarxa') ?: 'Error de xarxa.', JSON_UNESCAPED_UNICODE) ?>;
    toastErr(msg);
  })
  .finally(()=>{
    if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset._oldHtml || 'Publica amb segell'; }
  });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el=>{
    const title = el.getAttribute('title');
    if (title && title.trim() !== '') {
      new bootstrap.Tooltip(el);
    }
  });
});
</script>