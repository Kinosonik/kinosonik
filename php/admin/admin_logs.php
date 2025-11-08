<?php
// php/admin/admin_logs.php — Visor global de logs IA (només ADMIN)
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once dirname(__DIR__) . '/db.php';

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

if (!function_exists('abs_url')) {
  function abs_url(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $path;
  }
}

try {
  // ── Seguretat: només admins
  $uid = $_SESSION['user_id'] ?? null;
  if (!$uid) { header('Location: ' . BASE_PATH . 'index.php?error=login_required'); exit; }
  $pdo = db();
  $st = $pdo->prepare('SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari=? LIMIT 1');
  $st->execute([$uid]);
  $role = (string)($st->fetchColumn() ?: '');
  if (strcasecmp($role, 'admin') !== 0) {
    header('Location: ' . BASE_PATH . 'espai.php?seccio=dades&error=forbidden'); exit;
  }

  // ── Filtres
  $riderNum  = (int)($_GET['rider'] ?? 0);          // Rider #
  $q         = trim((string)($_GET['q'] ?? ''));    // Rider_UID / descripció / email
  $dateFrom  = trim((string)($_GET['from'] ?? '')); // YYYY-MM-DD
  $dateTo    = trim((string)($_GET['to'] ?? ''));   // YYYY-MM-DD
  $export    = (string)($_GET['export'] ?? '');     // 'csv' per exportar
  $perPage   = max(10, min(200, (int)($_GET['per'] ?? 50)));
  $page      = max(1, (int)($_GET['page'] ?? 1));
  $offset    = ($page - 1) * $perPage;

  $where = [];
  $params = [];

  // Helper per filtrar només els paràmetres usats dins el SQL real
  function filterParams(string $sql, array $params): array {
    $out = [];
    foreach ($params as $k => $v) {
      $key = ltrim($k, ':');
      if (str_contains($sql, ':' . $key)) {
        $out[$k] = $v;
      }
    }
    return $out;
  }

  // ── Filtre per status
  $status  = trim((string)($_GET['status'] ?? ''));  // ok, error, running, queued
  if ($status !== '') {
    $where[] = "runs.status = :status";
    $params[':status'] = $status;
  }

  if ($riderNum > 0) {
    $where[] = "runs.rider_id = :rid";
    $params[':rid'] = $riderNum;
  }
  if ($q !== '') {
  $where[] = "(r.Rider_UID LIKE :q OR r.Descripcio LIKE :q OR u.Email_Usuari LIKE :q OR runs.job_uid LIKE :q)";
  $params[':q'] = '%' . $q . '%';
  }
  // Dates (Europe/Madrid) — filtre directe a BD en local
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
  $where[] = "runs.started_at >= :from";
  $params[':from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
  $where[] = "runs.started_at <= :to";
  $params[':to'] = $dateTo . ' 23:59:59';
}

  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  // ── SQL base (reutilitzable)
  $sqlBase = "
    FROM ia_runs runs
    JOIN Riders r  ON r.ID_Rider = runs.rider_id
    JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
    $whereSql
  ";
  
  // ── Export CSV (mateixos filtres)
if ($export === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="ia_logs.csv"');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  while (ob_get_level() > 0) { ob_end_clean(); }

  $sqlCsv = "
    SELECT
      runs.rider_id,
      r.Rider_UID,
      r.Descripcio,
      u.Email_Usuari,
      runs.job_uid,
      runs.started_at,
      runs.log_path,
      runs.status
    $sqlBase
    ORDER BY runs.started_at DESC
    LIMIT 10000
  ";
  $stmt = $pdo->prepare($sqlCsv);
  foreach ($params as $k=>$v) { $stmt->bindValue($k, $v); }
  $stmt->execute();

  $out = fopen('php://output', 'w');
  // BOM per Excel
  fprintf($out, "\xEF\xBB\xBF");

  // capçaleres
  fputcsv($out, [
    'Rider#','Rider_UID','Descripció','E-mail',
    'Job_UID','Started_At(UTC)','Started_At(EU)',
    'Log_Path','Log_Exists','Log_Size_Bytes','Status'
  ]);

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $startedLocal  = (string)$row['started_at']; // a BD està en hora local (Europe/Madrid)
    $startedUtcIso = $startedLocal;
    $startedEu     = '';

    try {
      $dtLocal       = new DateTimeImmutable($startedLocal, new DateTimeZone('Europe/Madrid'));
      $dtUtc         = $dtLocal->setTimezone(new DateTimeZone('UTC'));
      $startedUtcIso = $dtUtc->format('c');   // ISO-8601 en UTC per analítica
      $startedEu     = dt_eu($dtLocal);       // bonic en local
    } catch (Throwable $e) {
      // si falla el parse, deixa els valors tal qual
    }

    $path   = (string)$row['log_path'];
    $exists = ($path !== '' && is_file($path));
    $size   = $exists ? (int)@filesize($path) : '';

    fputcsv($out, [
      (int)$row['rider_id'],
      (string)$row['Rider_UID'],
      (string)($row['Descripcio'] ?? ''),
      (string)$row['Email_Usuari'],
      (string)$row['job_uid'],
      $startedUtcIso,        // ✅ ISO UTC consistent
      $startedEu,            // ✅ lecture-friendly (local)
      $path,
      $exists ? 'yes' : 'no',
      $size,
      (string)($row['status'] ?? '')
    ]);
  }

  fclose($out);
  exit;
}

  // ── Count
  $sqlCount = "SELECT COUNT(*) $sqlBase";
  $stc = $pdo->prepare($sqlCount);
  $stc->execute(filterParams($sqlCount, $params));
  $total = (int)$stc->fetchColumn();
  $totalPages = max(1, (int)ceil($total / $perPage));

  // ── Dades
  $sql = "
  SELECT
    runs.rider_id, runs.job_uid, runs.started_at, runs.log_path, runs.status,
    r.Rider_UID, r.Descripcio, u.Email_Usuari,
    u.ID_Usuari AS owner_id
  $sqlBase
  ORDER BY runs.started_at DESC
  LIMIT :lim OFFSET :ofs
";
  $st = $pdo->prepare($sql);
  $execParams = filterParams($sql, $params);
  $execParams[':lim'] = $perPage;
  $execParams[':ofs'] = $offset;
  $st->execute($execParams);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  if (!headers_sent()) {
    http_response_code(500);
  }
  echo '<div class="container my-3"><div class="alert alert-danger small mb-0">'
     . '<strong>Error:</strong> ' . h($e->getMessage())
     . '</div></div>';
  exit;
}
?>
<div class="container-fluid my-0">
  <div class="card shadow-sm border-0">
    <div class="card-header bg-dark d-flex align-items-center justify-content-between">
      <h5 class="card-title mb-0">Logs IA (recents)</h5>
      <div>
        <?php
          // Construeix URL d'export amb filtres actuals
          $qsExport = [
            'seccio' => 'admin_logs',
            'rider'  => $riderNum ?: '',
            'q'      => $q,
            'from'   => $dateFrom,
            'to'     => $dateTo,
            'export' => 'csv'
          ];
        ?>
        <a class="btn btn-outline-secondary btn-sm"
          href="<?= h(BASE_PATH) ?>php/admin/admin_logs.php?<?= h(http_build_query($qsExport, '', '&', PHP_QUERY_RFC3986)) ?>">
          Exporta CSV
        </a>
        <form method="POST" action="<?= h(BASE_PATH) ?>php/admin/ia_purge_manual.php" class="d-inline"
              onsubmit="return confirm('Vols realment netejar els logs antics d’IA?');">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-trash-3"></i> Neteja logs antics
          </button>
        </form>
      </div>
    </div>
    <div class="card-body">

      <!-- Filtres -->
      <form class="row row-cols-auto g-2 align-items-end mb-3" method="get" action="<?= h(BASE_PATH) ?>espai.php">
        <input type="hidden" name="seccio" value="admin_logs">

        <div class="col">
          <label class="form-label small mb-0">Rider #</label>
          <input class="form-control form-control-sm" type="text" name="rider"
                 value="<?= $riderNum > 0 ? h((string)$riderNum) : '' ?>"
                 inputmode="numeric" pattern="\d*" style="max-width:110px">
        </div>

        <div class="col">
          <label class="form-label small mb-0">Cerca</label>
          <input class="form-control form-control-sm" type="text" name="q" value="<?= h($q) ?>"
                 placeholder="Rider UID, descripció, e-mail o Job UID">
        </div>

        <div class="col">
          <label class="form-label small mb-0">Des de</label>
          <input class="form-control form-control-sm" type="date" name="from" value="<?= h($dateFrom) ?>">
        </div>

        <div class="col">
          <label class="form-label small mb-0">Fins</label>
          <input class="form-control form-control-sm" type="date" name="to" value="<?= h($dateTo) ?>">
        </div>
  
        <div class="col">
          <label class="form-label small mb-0">Status</label>
          <select name="status" class="form-select form-select-sm">
            <?php
              $opts = ['' => 'Tots','ok'=>'ok','error'=>'error','running'=>'running','queued'=>'queued'];
              foreach($opts as $k=>$v){
                $sel = ($status === $k) ? 'selected' : '';
                echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
              }
            ?>
          </select>
        </div>

        <div class="col">
          <label class="form-label small mb-0 d-block">Rang ràpid</label>
          <div class="btn-group btn-group-sm" role="group">
            <button class="btn btn-outline-secondary" type="button" data-qpick="today">Avui</button>
            <button class="btn btn-outline-secondary" type="button" data-qpick="7">7 dies</button>
            <button class="btn btn-outline-secondary" type="button" data-qpick="30">30 dies</button>
          </div>
        </div>

        <div class="col">
          <label class="form-label small mb-0">Per pàgina</label>
          <select name="per" class="form-select form-select-sm">
            <?php foreach ([25,50,100,200] as $n): ?>
              <option value="<?= $n ?>" <?= $perPage===$n?'selected':''; ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col">
          <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
          <a class="btn btn-secondary btn-sm" href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_logs">Neteja</a>
        </div>
      </form>

      <!-- Resum -->
      <div class="d-flex justify-content-between align-items-center small text-secondary mb-2">
        <div>Resultats: <span class="text-body"><?= h((string)$total) ?></span></div>
        <div>Pàgina <span class="text-body"><?= h((string)$page) ?></span> / <span class="text-body"><?= h((string)$totalPages) ?></span></div>
      </div>

      <!-- Taula -->
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle small">
          <thead class="table-dark">
            <tr>
              <th class="text-center">#Rider</th>
              <th>Rider UID / Descripció</th>
              <th>E-mail</th>
              <th class="text-nowrap">Job UID</th>
              <th class="text-nowrap">Data</th>
              <th class="text-center">Fitxer</th>
              <th class="text-center">Status</th>
              <th class="text-center">Accés</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-secondary py-5">
              <div class="mb-2"><i class="bi bi-x-circle"></i></div>
              <div class="fw-semibold">No hi ha logs que coincideixin amb els filtres.</div>
            </td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r):
              $rid   = (int)$r['rider_id'];
              $ruid  = (string)$r['Rider_UID'];
              $desc  = trim((string)$r['Descripcio'] ?? '');
              $mail  = (string)$r['Email_Usuari'];
              $job   = (string)$r['job_uid'];
              $when    = (string)$r['started_at'];
              $ownerId = (int)($r['owner_id'] ?? 0);
              $whenEU  = '—';
              $whenRel = '';

              try {
                $dtLocal = new DateTimeImmutable($when, new DateTimeZone('Europe/Madrid'));
                $whenEU  = dt_eu($dtLocal);        // dd/mm/aaaa hh:mm (local)
                $whenRel = ago_short($dtLocal);    // "2 min", "3 h", "ahir", etc.
              } catch (Throwable $e) {
                // deixa '—' si falla
              }  
              $path  = (string)$r['log_path'];
              $exists = ($path !== '' && is_file($path));
              $sizeTxt = '—';
              if ($exists) {
                $sz = @filesize($path);
                if (is_int($sz)) $sizeTxt = fmt_bytes($sz);
              }
              
              $status = (string)($r['status'] ?? '');
              $badge  = match (strtolower($status)) {
                'ok'      => 'success',
                'error'   => 'danger',
                'running' => 'warning',
                'queued'  => 'secondary',
                default   => 'secondary',
              };
            ?>
            <tr>
              <td class="text-center text-secondary"><?= $rid ?></td>
              <td>
                <div class="fw-semibold"><?= h($ruid) ?></div>
                <div class="text-secondary small"><?= h($desc !== '' ? $desc : '—') ?></div>
              </td>
              <td><span class="text-body-secondary"><?= h($mail) ?></span></td>
              <td class="text-nowrap">
                <code title="Clica per copiar" style="cursor:pointer"
                      data-copy="<?= h($job) ?>"
                      onclick="navigator.clipboard.writeText(this.dataset.copy).then(()=>showCopyToast('Job UID copiat'));">
                  <?= h($job) ?>
                </code>
              </td>
              <td class="text-nowrap">
                <span class="me-1"><?= h($whenEU) ?></span>
                <span class="text-secondary small">(<?= h($whenRel) ?>)</span>
              </td>
              <td class="text-center">
              <?php if ($exists): ?>
                <span class="d-inline-flex align-items-center justify-content-center gap-1">
                  <i class="bi bi-check-circle text-success"></i>
                  <small class="text-secondary"><?= h($sizeTxt) ?></small>
                </span>
              <?php else: ?>
                <i class="bi bi-x-circle text-danger"></i>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php
                $statusIcon = '<i class="bi bi-question-circle text-warning"></i>'; // per defecte
                switch (strtolower(trim((string)$status))) {
                  case 'ok':
                    $statusIcon = '<i class="bi bi-check-circle text-success"></i>';
                    break;
                  case 'error':
                    $statusIcon = '<i class="bi bi-x-circle text-danger"></i>';
                    break;
                  case 'running':
                    $statusIcon = '<i class="bi bi-play-circle-fill text-warning"></i>';
                    break;
                  case 'queued':
                    $statusIcon = '<i class="bi bi-clock-history text-warning"></i>';
                    break;
                }
              ?>
              <span class="d-inline-flex align-items-center justify-content-center gap-1">
                <?= $statusIcon ?>
                
              </span>
            </td>
            <?php
                // Link a la secció de riders amb el filtre pel Rider UID
                $ridersLink = BASE_PATH . 'espai.php?' . http_build_query(
                  ['seccio' => 'riders', 'q' => $ruid],
                  '', '&', PHP_QUERY_RFC3986
                );
              ?>
              <td class="text-center">
                <div class="btn-group btn-group-sm">
                  <!-- Log HTML -->
                  <a class="btn btn-primary <?= $exists ? '' : 'disabled' ?>"
                    href="<?= h(BASE_PATH) ?>php/admin/log_view.php?job=<?= h($job) ?>&mode=html"
                    target="_blank" title="Veure log (HTML)">
                    <i class="bi bi-journal-text"></i>
                  </a>

                  <!-- Log RAW -->
                  <a class="btn btn-primary <?= $exists ? '' : 'disabled' ?>"
                    href="<?= h(BASE_PATH) ?>php/admin/log_view.php?job=<?= h($job) ?>&mode=raw"
                    target="_blank" title="RAW">
                    <i class="bi bi-file-text"></i>
                  </a>

                  <!-- Detall del job -->
                  <a class="btn btn-primary"
                    href="<?= h(BASE_PATH) ?>espai.php?seccio=ia_detail&job=<?= h($job) ?>"
                    title="Detall job IA (estat + log)">
                    <i class="bi bi-robot"></i>
                  </a>

                  <!-- Rider associat -->
                  <a class="btn btn-primary"
                    href="<?= h($ridersLink) ?>"
                    target="_blank" rel="noopener"
                    title="Obrir Rider a espai.php (filtrat)">
                    <i class="bi bi-person-badge"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginació -->
      <?php if ($totalPages > 1):
        $qsBase = [
          'seccio'=>'admin_logs',
          'rider'=>$riderNum ?: '',
          'q'=>$q,
          'from'=>$dateFrom,
          'to'=>$dateTo,
          'per'=>$perPage
        ];
        $pageUrl = function(int $p) use ($qsBase) {
          $qs = $qsBase; $qs['page']=$p;
          return BASE_PATH . 'espai.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
        };
      ?>
      <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center mb-0">
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= h($pageUrl(max(1,$page-1))) ?>">«</a></li>
          <?php
            $start = max(1, $page-2); $end = min($totalPages, $page+2);
            if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="'.h($pageUrl(1)).'">1</a></li>';
              if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
            for ($p=$start; $p<=$end; $p++) {
              $active = $p===$page ? 'active' : '';
              echo '<li class="page-item '.$active.'"><a class="page-link" href="'.h($pageUrl($p)).'">'.$p.'</a></li>';
            }
            if ($end < $totalPages) {
              if ($end < $totalPages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              echo '<li class="page-item"><a class="page-link" href="'.h($pageUrl($totalPages)).'">'.$totalPages.'</a></li>';
            }
          ?>
          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= h($pageUrl(min($totalPages,$page+1))) ?>">»</a></li>
        </ul>
      </nav>
      <?php endif; ?>
      <div id="copyToast" style="position:fixed;top:12px;left:50%;transform:translateX(-50%);
        background:#222;color:#fff;padding:8px 12px;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.25);
        font-size:.85rem;z-index:1080;opacity:0;transition:opacity .2s, transform .2s; pointer-events:none;">
        Copiat!
      </div>
      <script>
        let copyToastTimer;
        function showCopyToast(msg){
          const el = document.getElementById('copyToast');
          if (!el) return;
          el.textContent = msg || 'Copiat!';
          el.style.opacity = '1';
          el.style.transform = 'translateX(-50%) translateY(0)';
          clearTimeout(copyToastTimer);
          copyToastTimer = setTimeout(()=>{
            el.style.opacity = '0';
            el.style.transform = 'translateX(-50%) translateY(-6px)';
          }, 1200);
        }
      </script>
    </div>
  </div>
</div>
<script>
(function(){
  const sel = document.querySelector('select[name="per"]');
  if (!sel) return;
  const url = new URL(location.href);
  if (!url.searchParams.has('per')) {
    const saved = localStorage.getItem('admin_logs_per');
    if (saved && [...sel.options].some(o => o.value === saved)) sel.value = saved;
  }
  sel.addEventListener('change', ()=> localStorage.setItem('admin_logs_per', sel.value));
})();
</script>
<!-- Helpers + Quick Date Pickers -->
<script>
(function(){
  const from = document.querySelector('input[name="from"]');
  const to   = document.querySelector('input[name="to"]');
  document.querySelectorAll('[data-qpick]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (!from || !to) return;
      const today = new Date();
      const pad = n => String(n).padStart(2,'0');
      const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
      const mode = btn.getAttribute('data-qpick');
      let start = new Date(today);
      if (mode !== 'today') {
        const days = parseInt(mode, 10) || 7;
        start.setDate(today.getDate() - (days-1));
      }
      from.value = fmt(start);
      to.value   = fmt(today);
    });
  });
})();
</script>

<!-- Sticky header & compact rows -->
<style>
  .table-responsive thead th { position: sticky; top: 0; z-index: 2; }
  .table-sm td, .table-sm th { padding-top: .4rem; padding-bottom: .4rem; }
</style>

<?php
// Fallbacks segurs si falta dt_eu o ago_short
if (!function_exists('dt_eu')) {
  function dt_eu(DateTimeInterface $dt): string {
    return $dt->format('d/m/Y H:i');
  }
}
if (!function_exists('ago_short')) {
  function ago_short(DateTimeInterface $dt): string {
    $now = new DateTimeImmutable('now', $dt->getTimezone());
    $diff = $now->getTimestamp() - $dt->getTimestamp();
    if ($diff < 60) return $diff . ' s';
    if ($diff < 3600) return floor($diff/60) . ' min';
    if ($diff < 86400) return floor($diff/3600) . ' h';
    if ($diff < 172800) return 'ahir';
    return floor($diff/86400) . ' d';
  }
}
?>