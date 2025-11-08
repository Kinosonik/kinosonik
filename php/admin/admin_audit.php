<?php
// php/admin/admin_audit.php — Llistat + export d’auditories (Admin_Audit)
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/audit.php';

$pdo = db();

// Wrapper local tolerant a mixed per evitar TypeError amb h()
if (!function_exists('hx')) {
  function hx($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

/* ── Helpers ───────────────────────────────────────────── */

function ip_to_text(?string $bin): string {
  if ($bin === null || $bin === '') return '—';
  $txt = function_exists('inet_ntop') ? @inet_ntop($bin) : false;
  return $txt !== false ? $txt : '—';
}
function strw(string $s, int $w, string $end='…'): string {
  if (function_exists('mb_strimwidth')) return mb_strimwidth($s, 0, $w, $end, 'UTF-8');
  return (strlen($s) > $w) ? substr($s, 0, $w) . $end : $s;
}

function mark_q(string $text, string $q): string {
  // Si no hi ha q, només escapem
  if ($q === '') return hx($text);

  // Escapa el text i ressalta sobre el text escap(HTML)
  $escaped = hx($text);
  $pattern = '/' . preg_quote($q, '/') . '/i';

  // Evita /u si la teva build PHP/PCRE dona problemes de unicode
  return preg_replace($pattern, '<mark>$0</mark>', $escaped) ?? $escaped;
}

/* ── Seguretat: només admins ───────────────────────────── */
$currentUserId = $_SESSION['user_id'] ?? null;
if (!$currentUserId) { header('Location: ' . BASE_PATH . 'index.php?error=login_required'); exit; }
$st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$st->execute([$currentUserId]);
$isAdmin = ($row = $st->fetch(PDO::FETCH_ASSOC)) && strcasecmp((string)$row['Tipus_Usuari'], 'admin') === 0;
if (!$isAdmin) { header('Location: ' . BASE_PATH . 'espai.php?seccio=dades&error=forbidden'); exit; }

/* ── Filtres / ordenació / pàgina ──────────────────────── */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(200, (int)($_GET['per'] ?? 50)));
$offset  = ($page - 1) * $perPage;

$q        = trim((string)($_GET['q'] ?? ''));
$userId   = trim((string)($_GET['user_id'] ?? ''));
$riderId  = trim((string)($_GET['rider_id'] ?? ''));
$riderUid = trim((string)($_GET['rider_uid'] ?? ''));
$action   = trim((string)($_GET['action'] ?? ''));
$context  = trim((string)($_GET['context'] ?? ''));
$status   = trim((string)($_GET['status'] ?? ''));    // success|error
$http      = trim((string)($_GET['http'] ?? ''));      // codi HTTP
$methodF   = trim((string)($_GET['m'] ?? ''));         // GET/POST…
$routeF    = trim((string)($_GET['route'] ?? ''));     // ruta (/php/rider_file.php)
$isAdm    = trim((string)($_GET['is_admin'] ?? ''));  // '', '1', '0'
$from     = trim((string)($_GET['from'] ?? ''));      // YYYY-MM-DD
$to       = trim((string)($_GET['to'] ?? ''));

$sort = (string)($_GET['sort'] ?? 'ts');
$dir  = strtolower((string)($_GET['dir'] ?? 'desc'));
$dir  = in_array($dir, ['asc','desc'], true) ? $dir : 'desc';
$sortMap = [
  'id'      => 'id',
  'ts'      => 'ts',
  'user'    => 'user_id',
  'action'  => 'action',
  'status'  => 'status',
  'rider'   => 'rider_id',
  'isadm'   => 'is_admin',
  'context' => 'context',
  'route'   => 'route',
];
$orderBy  = $sortMap[$sort] ?? 'ts';
$orderSql = $orderBy . ' ' . strtoupper($dir);

/* ── WHERE ─────────────────────────────────────────────── */
$where  = [];
$params = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $where[] =
    "(action LIKE :q1 OR rider_uid LIKE :q2 OR user_agent LIKE :q3
      OR error_msg LIKE :q4 OR CAST(meta_json AS CHAR) LIKE :q5
      OR route LIKE :q6 OR request_id LIKE :q7)";
  $params[':q1'] = $like;
  $params[':q2'] = $like;
  $params[':q3'] = $like;
  $params[':q4'] = $like;
  $params[':q5'] = $like;
  $params[':q6'] = $like;
  $params[':q7'] = $like;
}
if ($userId !== '' && ctype_digit($userId)) { $where[] = "user_id = :uid"; $params[':uid'] = (int)$userId; }
if ($riderId !== '' && ctype_digit($riderId)) { $where[] = "rider_id = :rid"; $params[':rid'] = (int)$riderId; }
if ($riderUid !== '') { $where[] = "rider_uid = :ruid"; $params[':ruid'] = $riderUid; }
if ($action !== '') { $where[] = "action = :act"; $params[':act'] = $action; }
if ($context !== '') { $where[] = "context = :ctx"; $params[':ctx'] = $context; }
if ($status !== '' && in_array($status, ['success','error'], true)) { $where[] = "status = :st"; $params[':st'] = $status; }
if ($isAdm !== '' && in_array($isAdm, ['0','1'], true)) { $where[] = "is_admin = :ia"; $params[':ia'] = (int)$isAdm; }
if ($routeF !== '') { $where[] = "route = :route"; $params[':route'] = $routeF; }
if ($methodF !== '') { $where[] = "method = :mtd"; $params[':mtd'] = strtoupper($methodF); }
if ($http !== '' && ctype_digit($http)) { $where[] = "http_status = :hs"; $params[':hs'] = (int)$http; }
// Converteix dates locals (Europe/Madrid) → UTC per filtrar a BD
$fromUtc = $toUtc = null;
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
  try {
    $fromLocal = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('Europe/Madrid'));
    $fromUtc   = to_utc($fromLocal)->format('Y-m-d H:i:s');
  } catch (Throwable $e) {}
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
  try {
    $toLocal = new DateTimeImmutable($to . ' 23:59:59', new DateTimeZone('Europe/Madrid'));
    $toUtc   = to_utc($toLocal)->format('Y-m-d H:i:s');
  } catch (Throwable $e) {}
}
if ($fromUtc) { $where[] = "ts >= :from"; $params[':from'] = $fromUtc; }
if ($toUtc)   { $where[] = "ts <= :to";   $params[':to']   = $toUtc; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ── Base QS per conservar filtres ─────────────────────── */
$baseQS = [
  'seccio'    => 'admin_audit',
  'q'         => $q,
  'user_id'   => $userId,
  'rider_id'  => $riderId,
  'rider_uid' => $riderUid,
  'action'    => $action,
  'context'   => $context,
  'status'    => $status,
  'is_admin'  => $isAdm,
  'route'     => $routeF,
  'm'         => $methodF,
  'http'      => $http,
  'from'      => $from,
  'to'        => $to,
  'per'       => $perPage,
];
$sortUrl = function (string $key) use ($baseQS, $sort, $dir) {
  $qs = $baseQS; $qs['sort'] = $key; $qs['dir'] = ($sort === $key && $dir==='asc') ? 'desc' : 'asc';
  return BASE_PATH . 'espai.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
};
$sortIcon = function (string $key) use ($sort, $dir) {
  if ($sort !== $key) return '<i class="bi bi-arrow-down-up ms-1 text-secondary"></i>';
  return $dir === 'asc' ? '<i class="bi bi-arrow-up-short ms-1"></i>' : '<i class="bi bi-arrow-down-short ms-1"></i>';
};
$filterUrl = function(array $kv) use ($baseQS) {
  $qs = $baseQS;
  foreach ($kv as $k => $v) { $qs[$k] = $v; }
  $qs['page'] = 1;
  return BASE_PATH . 'espai.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
};

/* ── Export CSV/JSON (límit dur) ───────────────────────── */
const MAX_EXPORT_ROWS = 100000;
$export = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($export, ['csv','json'], true)) {
  $stc = $pdo->prepare("SELECT COUNT(*) FROM Admin_Audit $whereSql");
  $stc->execute($params);
  $cnt = (int)$stc->fetchColumn();
  $truncated = $cnt > MAX_EXPORT_ROWS;

  $sqlAll = "
    SELECT id, ts, user_id, is_admin, action, rider_id, rider_uid, ip,
           user_agent, context, meta_json, status, error_msg,
           route, method, http_status, latency_ms, request_id
      FROM Admin_Audit
      $whereSql
      ORDER BY ts DESC, id DESC
      LIMIT " . MAX_EXPORT_ROWS . "
  ";
  $sthAll = $pdo->prepare($sqlAll);
  foreach ($params as $k => $v) { $sthAll->bindValue($k, $v); }
  $sthAll->execute();

  $rows = [];
  while ($r = $sthAll->fetch(PDO::FETCH_ASSOC)) {
    $r['ip'] = ip_to_text($r['ip']);
    if (is_string($r['meta_json']) && $r['meta_json'] !== '') {
      $decoded = json_decode($r['meta_json'], true);
      if (json_last_error() === JSON_ERROR_NONE) $r['meta_json'] = $decoded;
    }
    // Normalitza ts a ISO-8601 UTC per export
    try {
      $dtUtc = new DateTimeImmutable((string)$r['ts'], new DateTimeZone('UTC'));
      $r['ts'] = $dtUtc->format('c');
    } catch (Throwable $e) { /* deixa tal qual si falla */ }
    $rows[] = $r;
  }

  $filenameBase = 'admin_audit_' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Ymd_His');

  // ——— AUDIT: export d'auditoria
  try {
    $filters = $baseQS;
    $filters['sort'] = $sort;
    $filters['dir']  = $dir;

    audit_admin(
      $pdo,
      (int)$currentUserId,
      true,                    // estem dins admin_audit
      'audit_export',
      null,                    // sense rider
      null,
      'admin_audit',
      [
        'format'    => $export,          // 'csv' | 'json'
        'count'     => count($rows),
        'truncated' => $truncated,
        'filters'   => $filters,
      ],
      'success',
      null
    );
  } catch (Throwable $e) {
    error_log('audit_export failed: ' . $e->getMessage());
  }

  // ——— JSON
  if ($export === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filenameBase.'.json"');
    header('X-Export-Truncated: ' . ($truncated ? '1' : '0'));
    echo json_encode([
      'truncated' => $truncated,
      'count'     => count($rows),
      'filters'   => [
        // Incloem filtres al JSON com a camps (no com a comentari)
        'q' => $q, 'user_id' => $userId, 'rider_id' => $riderId, 'rider_uid' => $riderUid,
        'action' => $action, 'context' => $context, 'status' => $status, 'is_admin' => $isAdm,
        'route' => $routeF, 'm' => $methodF, 'http' => $http, 'from' => $from, 'to' => $to,
        'sort' => $sort, 'dir' => $dir, 'per' => $perPage
      ],
      'data'      => $rows
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  }

  // ——— CSV
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filenameBase.'.csv"');
  header('X-Export-Truncated: ' . ($truncated ? '1' : '0'));

  $out = fopen('php://output', 'w');

  if ($truncated) {
    fwrite($out, "# TRUNCATED to ".MAX_EXPORT_ROWS." rows (total ~= $cnt)\n");
  }

  // Línia comentada de filtres (comentari al CSV)
  $filtersForHeader = [
    'q' => $q, 'user_id' => $userId, 'rider_id' => $riderId, 'rider_uid' => $riderUid,
    'action' => $action, 'context' => $context, 'status' => $status, 'is_admin' => $isAdm,
    'route' => $routeF, 'm' => $methodF, 'http' => $http, 'from' => $from, 'to' => $to,
    'sort' => $sort, 'dir' => $dir, 'per' => $perPage
  ];
  $filterPairs = [];
  foreach ($filtersForHeader as $k => $v) {
    if ($v !== '' && $v !== null) $filterPairs[] = $k.'='.str_replace(["\n","\r"], ' ', (string)$v);
  }
  if ($filterPairs) {
    fwrite($out, "# FILTERS: " . implode('; ', $filterPairs) . "\n");
  }

  // Capçalera CSV
  fputcsv($out, [
    'id','ts','user_id','is_admin','action','rider_id','rider_uid','ip',
    'user_agent','context','meta_json','status','error_msg','route','method',
    'http_status','latency_ms','request_id'
  ]);

  // Files
  foreach ($rows as $r) {
    $meta = $r['meta_json'];
    if (is_array($meta) || is_object($meta)) {
      $meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
    } elseif (!is_string($meta)) {
      $meta = '';
    }
    fputcsv($out, [
      $r['id'], $r['ts'], $r['user_id'], (int)$r['is_admin'], $r['action'],
      $r['rider_id'], $r['rider_uid'], $r['ip'], $r['user_agent'], $r['context'],
      $meta, $r['status'], $r['error_msg'], $r['route'], $r['method'],
      $r['http_status'], $r['latency_ms'], $r['request_id']
    ]);
  }

  if ($truncated) {
    fwrite($out, "# TRUNCATED\n");
  }
  fclose($out);
  exit;
}
/* ── Count i query paginada ────────────────────────────── */
$stc = $pdo->prepare("SELECT COUNT(*) FROM Admin_Audit $whereSql");
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$sql = "
  SELECT id, ts, user_id, is_admin, action, rider_id, rider_uid, ip,
       user_agent, context, meta_json, status, error_msg,
       route, method, http_status, latency_ms, request_id
    FROM Admin_Audit
    $whereSql
    ORDER BY $orderSql
    LIMIT :lim OFFSET :ofs
";
$sth = $pdo->prepare($sql);
foreach ($params as $k => $v) { $sth->bindValue($k, $v); }
$sth->bindValue(':lim', $perPage, PDO::PARAM_INT);
$sth->bindValue(':ofs', $offset,  PDO::PARAM_INT);
$sth->execute();
$rows = $sth->fetchAll(PDO::FETCH_ASSOC);

/* ── Enllaços ──────────────────────────────────────────── */
$pageUrl = function(int $p) use ($baseQS, $sort, $dir) {
  $qs = $baseQS; $qs['page'] = $p; $qs['sort'] = $sort; $qs['dir'] = $dir;
  return BASE_PATH . 'espai.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
};
$exportCsvUrl  = BASE_PATH . 'espai.php?' . http_build_query($baseQS + ['export'=>'csv'],  '', '&', PHP_QUERY_RFC3986);
$exportJsonUrl = BASE_PATH . 'espai.php?' . http_build_query($baseQS + ['export'=>'json'], '', '&', PHP_QUERY_RFC3986);
?>
<div class="container-fluid my-0">
  <div class="card shadow-sm border-0">
    <div class="card-header bg-dark d-flex justify-content-between align-items-center">
      <h5 class="card-title mb-0">Auditoria d’accions</h5>
      <div class="d-flex gap-2">
        <a class="btn btn-primary btn-sm fw-lighter" href="<?= hx($exportCsvUrl) ?>"><i class="bi bi-filetype-csv me-1"></i> Exporta CSV</a>
        <a class="btn btn-primary btn-sm fw-lighter" href="<?= hx($exportJsonUrl) ?>"><i class="bi bi-filetype-json me-1"></i> Exporta JSON</a>
      </div>
    </div>
    <div class="card-body">

      <!-- Filtres -->
      <form class="row g-2 align-items-end mb-3" method="get" action="<?= hx(BASE_PATH) ?>espai.php">
        <input type="hidden" name="seccio" value="admin_audit">

        <div class="col-12 col-md-3">
          <label class="form-label small mb-0">Cerca</label>
          <input type="text" class="form-control form-control-sm" name="q" value="<?= hx($q) ?>" placeholder="acció, UID, agent, error, meta…">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">User ID</label>
          <input type="text" class="form-control form-control-sm" name="user_id" value="<?= hx($userId) ?>" pattern="\d*">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">Rider ID</label>
          <input type="text" class="form-control form-control-sm" name="rider_id" value="<?= hx($riderId) ?>" pattern="\d*">
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label small mb-0">Rider UID</label>
          <input type="text" class="form-control form-control-sm" name="rider_uid" value="<?= hx($riderUid) ?>">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">Acció</label>
          <input type="text" class="form-control form-control-sm" name="action" value="<?= hx($action) ?>" placeholder="delete_rider…">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">Ruta</label>
          <input type="text" class="form-control form-control-sm" name="route" value="<?= hx($routeF) ?>" placeholder="/php/rider_file.php">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">Mètode</label>
          <input type="text" class="form-control form-control-sm" name="m" value="<?= hx($methodF) ?>" placeholder="GET, POST…">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">HTTP</label>
          <input type="text" class="form-control form-control-sm" name="http" value="<?= hx($http) ?>" pattern="\d*">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">Context</label>
          <input type="text" class="form-control form-control-sm" name="context" value="<?= hx($context) ?>" placeholder="admin_riders…">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">Estat</label>
          <select class="form-select form-select-sm" name="status">
            <?php $opts = [''=>'(tots)','success'=>'success','error'=>'error'];
              foreach ($opts as $k=>$v): ?>
              <option value="<?= hx($k) ?>" <?= $status===$k?'selected':''; ?>><?= hx($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">És admin?</label>
          <select class="form-select form-select-sm" name="is_admin">
            <?php $opts = [''=>'(tots)','1'=>'sí','0'=>'no'];
              foreach ($opts as $k=>$v): ?>
              <option value="<?= hx($k) ?>" <?= $isAdm===$k?'selected':''; ?>><?= hx($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">Des de</label>
          <input type="date" class="form-control form-control-sm" name="from" value="<?= hx($from) ?>">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-0">Fins a</label>
          <input type="date" class="form-control form-control-sm" name="to" value="<?= hx($to) ?>">
        </div>

        <div class="col-12 col-md-3">
          <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
          <a class="btn btn-secondary btn-sm" href="<?= hx(BASE_PATH) ?>espai.php?seccio=admin_audit">Neteja</a>
        </div>
      </form>

      <?php
      $chips = [];
      $mk = fn($lbl,$val,$key) => '<span class="badge text-bg-secondary me-1">'.$lbl.': <strong>'.hx($val).'</strong></span>'
        . ' <a class="small me-2" href="'.hx(BASE_PATH.'espai.php?'.http_build_query(array_diff_key($baseQS, [$key=>1]), '', '&', PHP_QUERY_RFC3986)).'">✕</a>';

      if ($routeF!=='')  $chips[] = $mk('ruta', $routeF, 'route');
      if ($methodF!=='') $chips[] = $mk('mètode', strtoupper($methodF), 'm');
      if ($http!=='')    $chips[] = $mk('HTTP', $http, 'http');
      if ($status!=='')  $chips[] = $mk('estat', $status, 'status');
      if ($action!=='')  $chips[] = $mk('acció', $action, 'action');
      if ($context!=='') $chips[] = $mk('context', $context, 'context');
      if ($q!=='')       $chips[] = $mk('cerca', $q, 'q');
      if ($from!=='')    $chips[] = $mk('des de', $from, 'from');
      if ($to!=='')      $chips[] = $mk('fins a', $to, 'to');

      if ($chips) {
        echo '<div class="mb-2">'.implode('',$chips).'</div>';
      }
      ?>

      <!-- Resum + per pàgina -->
      <div class="d-flex justify-content-between align-items-center small text-secondary mb-2">
        <div>
          Resultats: <span class="text-body"><?= hx((string)$total) ?></span>
          · Pàgina <span class="text-body"><?= hx((string)$page) ?></span>
          de <span class="text-body"><?= hx((string)$totalPages) ?></span>
        </div>
        <form method="get" class="d-inline">
        <?php foreach ($baseQS as $k => $v): ?>
          <input type="hidden" name="<?= hx($k) ?>" value="<?= hx((string)$v) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="sort" value="<?= hx($sort) ?>">
        <input type="hidden" name="dir"  value="<?= hx($dir) ?>">

        <select name="per"
                class="form-select form-select-sm d-inline-block w-auto"
                onchange="this.form.submit()">
          <?php foreach ([25,50,100,200] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage===$n?'selected':''; ?>><?= $n ?>/pàg</option>
          <?php endforeach; ?>
        </select>
      </form>
      </div>
      <div class="small text-secondary mb-2">
        <span class="badge text-bg-success">2xx</span> OK
        · <span class="badge text-bg-warning">4xx</span> Client
        · <span class="badge text-bg-danger">5xx</span> Servidor
        · <span class="badge text-bg-success ms-2">≤200 ms</span> ràpid
        · <span class="badge text-bg-warning">≤1000 ms</span> mitjà
        · <span class="badge text-bg-danger">>1000 ms</span> lent
      </div>
      <!-- Taula -->
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle fw-lighter small mb-0">
          <thead class="table-dark content-align-center">
            <tr>
              <th><a class="link-light text-decoration-none" href="<?= hx($sortUrl('id')) ?>"><?= $sortIcon('id') ?></a></th>
              <th class="text-center"><a class="link-light text-decoration-none" href="<?= hx($sortUrl('ts')) ?>"><i class="bi bi-clock"></i> <?= $sortIcon('ts') ?></a></th>
              <th class="text-center"><a class="link-light text-decoration-none" href="<?= hx($sortUrl('user')) ?>"><i class="bi bi-person"></i><?= $sortIcon('user') ?></a></th>
              <th class="text-center"><a class="link-light text-decoration-none" href="<?= hx($sortUrl('isadm')) ?>"><i class="bi bi-person-circle"></i> <?= $sortIcon('isadm') ?></a></th>
              <th class="text-center"><a class="link-light text-decoration-none" href="<?= hx($sortUrl('action')) ?>">Acció <?= $sortIcon('action') ?></a></th>
              <th class="text-center"><a class="link-light text-decoration-none" href="<?= hx($sortUrl('rider')) ?>">Rider <?= $sortIcon('rider') ?></a></th>
              <th class="text-center">UID</th>
              <th class="text-center"><a class="link-light text-decoration-none" href="<?= hx($sortUrl('context')) ?>">Context <?= $sortIcon('context') ?></a></th>
              <th class="text-center"><a class="link-light text-decoration-none" href="<?= hx($sortUrl('status')) ?>"><i class="bi bi-circle"></i><?= $sortIcon('status') ?></a></th>
              <th class="text-center">Mèt</th>
              <th class="text-center"><a class="link-light text-decoration-none" href="<?= hx($sortUrl('route')) ?>">Ruta <?= $sortIcon('route') ?></a></th>
              <th class="text-center">HTTP</th>
              <th class="text-center">ms</th>
              <th class="text-center">ReqID</th>
              <th class="text-center">IP</th>
              <th class="text-center">Error</th>
              <th class="text-center">Meta</th>
              <th class="text-center">Accions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="18" class="text-center text-secondary py-4">Cap registre.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r):
              $metaPreview = '';
              if (!empty($r['meta_json'])) {
                $raw = is_string($r['meta_json']) ? $r['meta_json'] : json_encode($r['meta_json'], JSON_UNESCAPED_UNICODE);
                $metaPreview = strw($raw, 120);
              }
               // Enllaços ràpids
                $userIdRow   = (int)$r['user_id'];
                $riderIdRow  = isset($r['rider_id']) ? (int)$r['rider_id'] : 0;
                $riderUidRow = (string)($r['rider_uid'] ?? '');

                // Fitxa interna de l’usuari
                $userLink = BASE_PATH . 'espai.php?seccio=dades&user=' . $userIdRow;

                // Pàgina pública del rider (si tenim UID)
                $riderPublicLink = $riderUidRow !== ''
                ? (rtrim((string)BASE_PATH, '/') . '/visualitza.php?ref=' . rawurlencode($riderUidRow))
                : '';

                // Opcional: enllaç a admin_riders filtrant per UID o Rider ID (per ajudar a trobar-lo)
                $adminRidersFilterLink = '';
                if ($riderUidRow !== '') {
                    $adminRidersFilterLink = BASE_PATH . 'espai.php?' . http_build_query(
                    ['seccio' => 'admin_riders', 'q' => $riderUidRow],
                    '', '&', PHP_QUERY_RFC3986
                    );
                }
            ?>
            <tr>
              <td class="text-secondary text-center"><?= hx((string)$r['id']) ?></td>
              <td class="text-center">
                <span class="me-1"><?= dt_eu(from_utc($r['ts'])) ?></span>
                <span class="text-secondary small">(<?= ago_short(from_utc($r['ts'])) ?>)</span>
              </td>
              <td class="text-center">
                <?php if (!empty($r['user_id'])): ?>
                  <a href="<?= hx($filterUrl(['user_id' => (string)$r['user_id']])) ?>" class="text-decoration-none">
                    <?= hx((string)$r['user_id']) ?>
                  </a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-center">
                <?php
                  $isA = (int)$r['is_admin'];
                  $icon = $isA
                    ? '<i class="bi bi-person-circle text-success" title="Administrador"></i>'
                    : '<i class="bi bi-x text-danger" title="Usuari normal"></i>';
                ?>
                <a href="<?= hx($filterUrl(['is_admin' => (string)$isA])) ?>"
                  class="text-decoration-none">
                  <?= $icon ?>
                </a>
              </td>
              <td>
                <?php if (!empty($r['action'])): ?>
                  <a href="<?= hx($filterUrl(['action' => (string)$r['action']])) ?>" class="text-decoration-none">
                    <code><?= mark_q((string)$r['action'], $q) ?></code>
                  </a>
                <?php else: ?>
                  <code>—</code>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($r['rider_id']!==null): ?>
                  <a href="<?= hx($filterUrl(['rider_id' => (string)$r['rider_id']])) ?>" class="text-decoration-none">
                    <?= hx((string)$r['rider_id']) ?>
                  </a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-break">
                <?php if (!empty($r['rider_uid'])): 
                  $uid = (string)$r['rider_uid'];
                ?>
                  <code title="<?= hx($uid) ?>" style="cursor:pointer"
                        data-copy="<?= hx($uid) ?>"
                        onclick="navigator.clipboard.writeText(this.dataset.copy)">
                    <?= hx(substr($uid,0,12)) ?>…
                  </code>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-center">
                <?php if (!empty($r['context'])): ?>
                  <a href="<?= hx($filterUrl(['context' => (string)$r['context']])) ?>" class="text-decoration-none">
                    <?= mark_q((string)$r['context'], $q) ?>
                  </a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-center">
              <?php if (!empty($r['status'])): ?>
                <a href="<?= hx($filterUrl(['status' => (string)$r['status']])) ?>" class="text-decoration-none">
                  <?= ($r['status']==='success')
                      ? '<i class="bi bi-check-circle-fill text-success"></i>'
                      : '<i class="bi bi-x-circle-fill text-danger"></i>' ?>
                </a>
              <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-center">
                <?php if (!empty($r['method'])): ?>
                <a href="<?= hx($filterUrl(['m' => strtoupper((string)$r['method'])])) ?>" class="text-decoration-none">
                  <?= hx((string)$r['method']) ?>
                </a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-break text-center">
                <?php if (!empty($r['route'])): ?>
                  <a href="<?= hx($filterUrl(['route' => (string)$r['route']])) ?>" class="text-decoration-none">
                    <?= hx((string)$r['route']) ?>
                  </a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php
                $hs  = $r['http_status'];
                $cls = 'text-bg-secondary';
                if (is_numeric($hs)) {
                  $hs = (int)$hs;
                  if ($hs >= 200 && $hs < 300) $cls = 'text-bg-success';
                  elseif ($hs >= 400 && $hs < 500) $cls = 'text-bg-warning';
                  elseif ($hs >= 500) $cls = 'text-bg-danger';
                }
                $httpLink = isset($r['http_status']) ? $filterUrl(['http' => (string)$r['http_status']]) : null;
                ?>
                <?php if ($httpLink): ?>
                <a href="<?= hx($httpLink) ?>" class="text-decoration-none">
                  <span class="badge <?= $cls ?>"><?= hx((string)$r['http_status']) ?></span>
                </a>
                <?php else: ?>
                <span class="badge <?= $cls ?>">—</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($r['latency_ms'] !== null): 
                  $ms = (int)$r['latency_ms'];
                  $cls = 'text-bg-secondary';
                  if ($ms < 200)       $cls = 'text-success';
                  elseif ($ms < 1000)  $cls = 'text-warning';
                  else                 $cls = 'text-danger';
                ?>
                  <span class="badge <?= $cls ?>" title="<?= hx($ms) ?> ms">
                    <?= hx($ms) ?>
                  </span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-break text-center">
                <?php if (!empty($r['request_id'])):
                  $rid = (string)$r['request_id'];
                ?>
                  <code title="<?= hx($rid) ?>" style="cursor:pointer"
                        data-copy="<?= hx($rid) ?>"
                        onclick="navigator.clipboard.writeText(this.dataset.copy)">
                    <?= hx(substr($rid,0,12)) ?>…
                  </code>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-break text-center">
                <?php 
                  $ipTxt = ip_to_text($r['ip']);
                  if ($ipTxt !== '—'): 
                ?>
                  <code title="<?= hx($ipTxt) ?>" style="cursor:pointer"
                        data-copy="<?= hx($ipTxt) ?>"
                        onclick="navigator.clipboard.writeText(this.dataset.copy)">
                    <?= hx($ipTxt) ?>
                  </code>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-break text-center text-truncate">
                <?php
                  $hasMeta = !empty($r['meta_json']);
                  $rawMeta = is_string($r['meta_json']) ? $r['meta_json'] : json_encode($r['meta_json'], JSON_UNESCAPED_UNICODE);
                  if ($hasMeta) {
                    $decoded = json_decode($rawMeta, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                      $pretty = json_encode(
                        $decoded,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                      );
                    } else {
                      $pretty = $rawMeta;
                    }
                    $jsonId = 'meta_json_' . (int)$r['id'];
                  }
                ?>
                <?php if ($r['error_msg']): ?>
                  <?php
                    $errorId = 'error_msg_' . (int)$r['id'];
                    $errorSafe = str_replace('</script>', '<\/script>', (string)$r['error_msg']);
                  ?>
                  <button type="button" class="btn btn-link btn-sm p-0 align-middle"
                          data-action="view-meta" data-target="#<?= hx($errorId) ?>"
                          title="Veure error en modal">
                    <i class="bi bi-eye text-danger"></i>
                  </button>
                  <script type="application/json" id="<?= hx($errorId) ?>"><?= $errorSafe ?></script>
                <?php else: ?>
                  —
                <?php endif; ?>

              </td>
              <td class="text-break text-center text-truncate">
                <?php if ($hasMeta): ?>
                  <button type="button" class="btn btn-link btn-sm p-0 align-middle ms-1"
                          data-action="view-meta" data-target="#<?= hx($jsonId) ?>"
                          title="Veure en modal">
                    <i class="bi bi-eye"></i>
                  </button>

                  <!-- JSON real (segur i fora d’atributs) -->
                  <?php $jsonSafe = str_replace('</script>', '<\/script>', $pretty); ?>
                  <script type="application/json" id="<?= hx($jsonId) ?>"><?= $jsonSafe ?></script>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="text-nowrap text-center">
                <div class="btn-group btn-group-sm" role="group" aria-label="Accions ràpides">
                    <!-- Veure usuari -->
                    <a class="btn btn-primary" href="<?= hx($userLink) ?>" title="Veure usuari">
                        <i class="bi bi-person"></i>
                    </a>
                    <!-- Veure rider públic: només si tenim UID -->
                    <?php if ($riderPublicLink !== ''): ?>
                    <a class="btn btn-primary" href="<?= hx($riderPublicLink) ?>" target="_blank" rel="noopener" title="Veure rider (públic)">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                    <?php else: ?>
                    <button class="btn btn-primary" disabled title="Sense UID">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </button>
                    <?php endif; ?>

                    <!-- Obrir admin_riders filtrat per trobar ràpid el rider -->
                    <?php if ($adminRidersFilterLink !== ''): ?>
                    <a class="btn btn-primary" href="<?= hx($adminRidersFilterLink) ?>" title="Obrir a admin_riders (filtrat)">
                        <i class="bi bi-search"></i>
                    </a>
                    <?php else: ?>
                    <button class="btn btn-primary" disabled title="No filtrable">
                        <i class="bi bi-search"></i>
                    </button>
                    <?php endif; ?>
                </div>
                </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <!-- MODAL DE META I ERROR -->
       <div class="modal fade" id="metaModal" tabindex="-1" aria-labelledby="metaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header bg-kinosonik">
              <h6 class="modal-title" id="metaModalLabel">Meta JSON</h6>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tancar"></button>
            </div>
            <div class="modal-body">
              <pre id="metaModalPre" class="small mb-0" style="white-space:pre-wrap;word-wrap:break-word;"></pre>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Tancar</button>
              <button type="button" class="btn btn-secondary btn-sm" id="metaCopyBtn">Copiar</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Paginació -->
      <?php if ($totalPages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center mb-0">
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= hx($pageUrl(max(1,$page-1))) ?>">«</a></li>
          <?php
            $start = max(1, $page-2);
            $end   = min($totalPages, $page+2);
            if ($start > 1) {
              echo '<li class="page-item"><a class="page-link" href="'.hx($pageUrl(1)).'">1</a></li>';
              if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            for ($p=$start; $p<=$end; $p++) {
              $active = $p===$page ? 'active' : '';
              echo '<li class="page-item '.$active.'"><a class="page-link" href="'.hx($pageUrl($p)).'">'.$p.'</a></li>';
            }
            if ($end < $totalPages) {
              if ($end < $totalPages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              echo '<li class="page-item"><a class="page-link" href="'.hx($pageUrl($totalPages)).'">'.$totalPages.'</a></li>';
            }
          ?>
          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= hx($pageUrl(min($totalPages,$page+1))) ?>">»</a></li>
        </ul>
      </nav>
      <?php endif; ?>
      <script>
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-json-toggle]');
        if (!btn) return;
        const pre = btn.parentElement.querySelector('pre[data-full]');
        if (!pre) return;
        const long = pre.getAttribute('data-long') === '1';
        if (!long) return;

        const showingFull = pre.getAttribute('data-showing') === 'full';
        if (showingFull) {
          pre.textContent = pre.getAttribute('data-short');
          pre.setAttribute('data-showing', 'short');
          btn.textContent = 'Desplega';
        } else {
          pre.textContent = pre.getAttribute('data-full');
          pre.setAttribute('data-showing', 'full');
          btn.textContent = 'Plega';
        }
      });
      </script>
      <script>
      (function(){
        const sel = document.querySelector('select[name="per"]');
        if (!sel) return;

        // Carrega valor guardat si l'URL no el porta
        const url = new URL(location.href);
        if (!url.searchParams.has('per')) {
          const saved = localStorage.getItem('admin_audit_per');
          if (saved && [...sel.options].some(o => o.value === saved)) {
            sel.value = saved;
          }
        }

        // Desa quan canvia
        sel.addEventListener('change', () => {
          localStorage.setItem('admin_audit_per', sel.value);
        });
      })();
      </script>
    </div>
  </div>
</div>
<script>
document.addEventListener('click', e => {
  const el = e.target.closest('code[data-copy]');
  if (!el) return;

  const msg = document.createElement('div');
  msg.textContent = 'Copiat!';
  msg.className = 'position-absolute small text-success fw-bold';
  msg.style.top = (el.getBoundingClientRect().top + window.scrollY - 20) + 'px';
  msg.style.left = (el.getBoundingClientRect().left) + 'px';
  msg.style.pointerEvents = 'none';
  msg.style.transition = 'opacity 0.6s';
  msg.style.opacity = '1';
  document.body.appendChild(msg);

  setTimeout(() => msg.style.opacity = '0', 400);
  setTimeout(() => msg.remove(), 800);
});
</script>
<script>
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('[data-action]');
  if (!btn) return;

  const targetSel = btn.getAttribute('data-target');
  const holder = targetSel ? document.querySelector(targetSel) : null;
  if (!holder) return;

  const json = holder.textContent || '';
  const action = btn.getAttribute('data-action');

  if (action === 'view-meta') {
  const pre = document.getElementById('metaModalPre');
  pre.textContent = json;

  // 🆕 Títol dinàmic segons l’ID
  const title = document.getElementById('metaModalLabel');
  if (targetSel.includes('error_msg_')) {
    title.textContent = 'Missatge d’error';
  } else if (targetSel.includes('meta_json_')) {
    title.textContent = 'Meta JSON';
  } else {
    title.textContent = 'Detall';
  }

  const modal = new bootstrap.Modal(document.getElementById('metaModal'));
  modal.show();

  // botó copiar dins modal
  const copyBtn = document.getElementById('metaCopyBtn');
  copyBtn.onclick = async () => {
    try { await navigator.clipboard.writeText(pre.textContent); }
    catch(e){ alert('No s’ha pogut copiar el text.'); }
  };
}
});
</script>