<?php
// php/admin/ia_kpis.php — KPIs d'IA (només ADMIN)
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/i18n.php';
require_once dirname(__DIR__) . '/middleware.php';

function hx($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

/* ── Auth: només admin ─────────────────────────────────── */
$uid = $_SESSION['user_id'] ?? null;
if (!$uid) { header('Location: ' . BASE_PATH . 'index.php?error=login_required'); exit; }

$st = $pdo->prepare('SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari=? LIMIT 1');
$st->execute([$uid]);
$isAdmin = (strcasecmp((string)$st->fetchColumn(), 'admin') === 0);
if (!$isAdmin) {
  http_response_code(403);
  echo '<div class="alert alert-danger my-3">No tens permisos per accedir a aquesta secció.</div>';
  exit;
}

/* ── Paràmetres ─────────────────────────────────────────── */
$days = (int)($_GET['days'] ?? 30);
$days = max(7, min(180, $days)); // 7..180 dies

$tzEu  = new DateTimeZone('Europe/Madrid');

// “avui” en local, tancat a final de dia
$toDT   = (new DateTimeImmutable('now', $tzEu))->setTime(23, 59, 59);
// començament del període (dies-1 per incloure avui)
$fromDT = $toDT->sub(new DateInterval('P'.($days-1).'D'))->setTime(0, 0, 0);

// Strings per a l’SQL (BD guarda en local Europe/Madrid)
$from = $fromDT->format('Y-m-d H:i:s');
$to   = $toDT->format('Y-m-d H:i:s');

/* ── Consulta KPIs per dia ──────────────────────────────── */
$sql = "
  SELECT
    DATE(started_at) AS d,
    COUNT(*) AS total,
    SUM(CASE WHEN status='ok' THEN 1 ELSE 0 END)    AS ok_cnt,
    SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS err_cnt,
    AVG(score) AS avg_score,
    AVG(CASE WHEN finished_at IS NULL THEN NULL
             ELSE TIMESTAMPDIFF(SECOND, started_at, finished_at)*1000 END) AS avg_lat_ms
  FROM ia_runs
  WHERE started_at BETWEEN :from AND :to
  GROUP BY DATE(started_at)
  ORDER BY DATE(started_at) ASC
";
$st = $pdo->prepare($sql);
$st->execute([':from'=>$from, ':to'=>$to]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ── KPIs d’estat agregats ─────────────────────────────── */
$kpiStmt = $pdo->prepare("
  SELECT
    SUM(r.status = 'ok')    AS ok_cnt,
    SUM(r.status = 'error') AS error_cnt,
    SUM(r.finished_at IS NULL) AS running_cnt
  FROM ia_runs r
  WHERE r.started_at BETWEEN :from AND :to
");
$kpiStmt->execute([':from'=>$from, ':to'=>$to]);
$kpis = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['ok_cnt'=>0,'error_cnt'=>0,'running_cnt'=>0];

/* ── Sèries complertes (tots els dies) ──────────────────── */
$map = [];
foreach ($rows as $r) { $map[$r['d']] = $r; }

$labels = $totals = $oks = $errs = $scores = $lats = [];
$cur = $fromDT;
while ($cur <= $toDT) {
  $d = $cur->format('Y-m-d');
  $labels[] = $d;
  if (isset($map[$d])) {
    $row = $map[$d];
    $totals[] = (int)($row['total'] ?? 0);
    $oks[]    = (int)($row['ok_cnt'] ?? 0);
    $errs[]   = (int)($row['err_cnt'] ?? 0);
    $scores[] = is_null($row['avg_score']) ? null : (float)$row['avg_score'];
    $lats[]   = is_null($row['avg_lat_ms']) ? null : (int)$row['avg_lat_ms'];
  } else {
    $totals[] = 0; $oks[] = 0; $errs[] = 0; $scores[] = null; $lats[] = null;
  }
  $cur = $cur->add(new DateInterval('P1D'));
}

/* ── Enllaç export CSV ──────────────────────────────────── */
$csvQS = http_build_query([
  'from'   => $fromDT->format('Y-m-d'),
  'to'     => $toDT->format('Y-m-d'),
  'status' => ''
], '', '&', PHP_QUERY_RFC3986);
$csvUrl = BASE_PATH . 'php/admin/ia_export_csv.php?' . $csvQS;
?>
<div class="container-fluid my-0">
  <div class="card shadow-sm border-0">
    <div class="card-header bg-dark d-flex justify-content-between align-items-center">
      <h5 class="card-title mb-0">KPIs d’IA (últims <?= hx((string)$days) ?> dies)</h5>
      <div class="d-flex align-items-center gap-2">
        <form class="d-flex align-items-end gap-2" method="get" action="<?= hx(BASE_PATH) ?>espai.php" autocomplete="off">
          <input type="hidden" name="seccio" value="ia_kpis">
          <div>
            <label class="form-label small mb-0">Dies</label>
            <input class="form-control form-control-sm" type="number" min="7" max="180" step="1" name="days" value="<?= hx((string)$days) ?>" style="width:90px">
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Actualitza</button>
        </form>
        <a class="btn btn-outline-light btn-sm" href="<?= hx($csvUrl) ?>">
          <i class="bi bi-download me-1"></i> Export CSV
        </a>
      </div>
    </div>
    <div class="card-body">
      <?php
        $sumTotal = array_sum($totals);
        $sumOk    = array_sum($oks);
        $sumErr   = array_sum($errs);
        $avgScore = null;
        $scoreVals = array_values(array_filter($scores, static fn($v) => $v !== null));
        if ($scoreVals) { $avgScore = array_sum($scoreVals)/count($scoreVals); }
        $avgLat = null;
        $latVals = array_values(array_filter($lats, static fn($v) => $v !== null));
        if ($latVals) { $avgLat = array_sum($latVals)/count($latVals); }
        $okPct   = ($sumTotal > 0) ? ($sumOk / $sumTotal * 100) : null;
        $errPct  = ($sumTotal > 0) ? ($sumErr / $sumTotal * 100) : null;
        $runsDay = $days > 0 ? ($sumTotal / $days) : null;
      ?>
      <div class="row row-cols-1 row-cols-md-6 g-3 mb-3 text-center">
  <div class="col">
    <div class="p-3 border rounded-3 h-100">
      <div class="small text-secondary">Runs OK</div>
      <div class="fs-5 fw-semibold mt-1"><?= (int)($kpis['ok_cnt'] ?? 0) ?></div>
    </div>
  </div>
  <div class="col">
    <div class="p-3 border rounded-3 h-100">
      <div class="small text-secondary">Errors</div>
      <div class="fs-5 fw-semibold mt-1"><?= (int)($kpis['error_cnt'] ?? 0) ?></div>
    </div>
  </div>
  <div class="col">
    <div class="p-3 border rounded-3 h-100">
      <div class="small text-secondary">En execució</div>
      <div class="fs-5 fw-semibold mt-1"><?= (int)($kpis['running_cnt'] ?? 0) ?></div>
    </div>
  </div>
  <div class="col">
    <div class="p-3 border rounded-3 h-100">
      <div class="small text-secondary">OK / Error (%)</div>
      <div class="fs-6 fw-semibold mt-1">
        <?= $okPct  !== null ? hx(number_format($okPct,1)).'%'  : '—' ?>
        /
        <?= $errPct !== null ? hx(number_format($errPct,1)).'%' : '—' ?>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="p-3 border rounded-3 h-100">
      <div class="small text-secondary">Score i Latència mitjana</div>
      <div class="fs-6 fw-semibold mt-1">
        <?= $avgScore !== null ? hx(number_format($avgScore,1)) : '—' ?> ·
        <?= $avgLat   !== null ? hx(number_format($avgLat)).' ms' : '—' ?>
      </div>
      <div class="small text-secondary mt-1">
        <?= $runsDay !== null ? '· '.hx(number_format($runsDay,1)).' runs/dia' : '' ?>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="p-3 border rounded-3 h-100">
      <div class="small text-secondary">Període</div>
      <div class="fs-6 fw-semibold mt-1"><?= hx($fromDT->format('d/m')) ?> → <?= hx($toDT->format('d/m')) ?></div>
    </div>
  </div>
</div>

      <!-- Gràfic: Execucions per dia -->
      <div class="border rounded-3 p-3 chart-scroll mb-4">
        <div class="chart-wrap">
          <canvas id="chartRuns" height="220"></canvas>
        </div>
        <div class="text-end mt-2">
          <button class="btn btn-outline-secondary btn-sm" id="dlRunsPng"><i class="bi bi-image"></i> Desa PNG</button>
        </div>
      </div>

      <!-- Gràfic: Score mitjà -->
      <div class="border rounded-3 p-3 chart-scroll mb-4">
        <div class="chart-wrap">
          <canvas id="chartScore" height="220"></canvas>
        </div>
        <div class="text-end mt-2">
          <button class="btn btn-outline-secondary btn-sm" id="dlScorePng"><i class="bi bi-image"></i> Desa PNG</button>
        </div>
      </div>

      <!-- Gràfic: Latència mitjana (ms) -->
      <div class="border rounded-3 p-3 chart-scroll">
        <div class="chart-wrap">
          <canvas id="chartLatency" height="220"></canvas>
        </div>
        <div class="text-end mt-2">
          <button class="btn btn-outline-secondary btn-sm" id="dlLatPng"><i class="bi bi-image"></i> Desa PNG</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  // Dades del servidor
  const labels   = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  const dataAll  = <?= json_encode($totals, JSON_UNESCAPED_UNICODE) ?>;
  const dataOk   = <?= json_encode($oks, JSON_UNESCAPED_UNICODE) ?>;
  const dataErr  = <?= json_encode($errs, JSON_UNESCAPED_UNICODE) ?>;
  const scoreAvg = <?= json_encode($scores, JSON_UNESCAPED_UNICODE) ?>;
  const latAvg   = <?= json_encode($lats, JSON_UNESCAPED_UNICODE) ?>;

  // Loader dinàmic de Chart.js si cal
  function ensureChartJs(cb) {
    if (window.Chart) { cb(); return; }
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4';
    s.async = true;
    s.onload = () => cb();
    s.onerror = () => console.error('No s\'ha pogut carregar Chart.js');
    document.head.appendChild(s);
  }

  function fitCanvasWidth(canvasId, labelsCount, pxPerLabel = 28, minPx = 700) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const target = Math.max(minPx, labelsCount * pxPerLabel);
    canvas.style.width = target + 'px';
  }

  function baseOptions(yBeginZero = true) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top' }, tooltip: { enabled: true } },
      scales: {
        x: {
          ticks: {
            autoSkip: true,
            maxTicksLimit: Math.min(12, labels.length),
            maxRotation: 0, minRotation: 0
          },
          grid: { display: false }
        },
        y: { beginAtZero: yBeginZero, ticks: { precision: 0 } }
      }
    };
  }

  function initCharts() {
    // Amplada dinàmica (després de tenir Chart disponible i el DOM carregat)
    fitCanvasWidth('chartRuns',    labels.length);
    fitCanvasWidth('chartScore',   labels.length);
    fitCanvasWidth('chartLatency', labels.length);

    // Gràfic 1: Execucions
    new Chart(document.getElementById('chartRuns').getContext('2d'), {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'Totals', data: dataAll,  borderWidth: 1, barPercentage: 0.9, categoryPercentage: 0.9 },
          { label: 'OK',     data: dataOk,   borderWidth: 1, barPercentage: 0.9, categoryPercentage: 0.9 },
          { label: 'Errors', data: dataErr,  borderWidth: 1, barPercentage: 0.9, categoryPercentage: 0.9 }
        ]
      },
      options: baseOptions(true)
    });

    // Gràfic 2: Score mitjà
    new Chart(document.getElementById('chartScore').getContext('2d'), {
      type: 'line',
      data: { labels, datasets: [{ label: 'Score mitjà', data: scoreAvg, spanGaps: true, tension: 0.2 }] },
      options: {
        ...baseOptions(true),
        scales: { ...baseOptions(true).scales, y: { beginAtZero: true, suggestedMax: 100 } }
      }
    });

    // Gràfic 3: Latència
    new Chart(document.getElementById('chartLatency').getContext('2d'), {
      type: 'line',
      data: { labels, datasets: [{ label: 'Latència mitjana (ms)', data: latAvg, spanGaps: true, tension: 0.2 }] },
      options: baseOptions(true)
    });

    // Botons "Desa PNG"
    function hookDownload(btnId, canvasId, filename) {
      const btn = document.getElementById(btnId);
      const canvas = document.getElementById(canvasId);
      if (!btn || !canvas) return;
      btn.addEventListener('click', () => {
        const url = canvas.toDataURL('image/png');
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); a.remove();
      });
    }
    hookDownload('dlRunsPng',   'chartRuns',   'ia_kpi_runs.png');
    hookDownload('dlScorePng',  'chartScore',  'ia_kpi_score.png');
    hookDownload('dlLatPng',    'chartLatency','ia_kpi_latency.png');
  }

  // Arrenca
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => ensureChartJs(initCharts));
  } else {
    ensureChartJs(initCharts);
  }
})();
</script>