<?php
// admin_riders.php — Llista global de riders (només ADMIN) …
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';

$pdo = db();

// Helper d'escapat
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Format mida
function humanBytes(int $b): string {
  $u = ['B','KB','MB','GB','TB'];
  $i = 0; $v = (float)$b;
  while ($v >= 1024 && $i < count($u)-1) { $v /= 1024; $i++; }
  return number_format($v, ($i===0?0:1)) . ' ' . $u[$i];
}

// Formatador de dates robust (fallback local)
if (!function_exists('dt_eu')) {
  /**
   * Converteix una data MySQL/ISO a 'dd/mm/YYYY' o 'dd/mm/YYYY HH:ii'.
   * Retorna '—' si és buida o invàlida.
   */
  function dt_eu(?string $s, string $fmt = 'd/m/Y H:i'): string {
    $s = trim((string)$s);
    if ($s === '' || $s === '0000-00-00' || $s === '0000-00-00 00:00:00') return '—';
    try {
      // Normalitza "YYYY-MM-DD hh:mm:ss" -> ISO
      $norm = str_replace(' ', 'T', $s);
      $dt   = new DateTime($norm);
      // Si només ve dia (sense hora), fem format curt.
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $dt->format('d/m/Y');
      }
      return $dt->format($fmt);
    } catch (Throwable $e) {
      return '—';
    }
  }
}

// ── Seguretat: només admins
$currentUserId = $_SESSION['user_id'] ?? null;
if (!$currentUserId) {
  header('Location: ' . BASE_PATH . 'index.php?error=login_required'); exit;
}
$st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$st->execute([$currentUserId]);
$isAdmin = ($row = $st->fetch(PDO::FETCH_ASSOC)) && strcasecmp((string)$row['Tipus_Usuari'], 'admin') === 0;
if (!$isAdmin) {
  header('Location: ' . BASE_PATH . 'espai.php?seccio=dades&error=forbidden'); exit;
}

// CSRF
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf'];

// ── Filtres / ordenació / paginació
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(5, min(100, (int)($_GET['per'] ?? 20)));
$offset  = ($page - 1) * $perPage;

$filterQ      = trim((string)($_GET['q'] ?? ''));
$filterUserId = trim((string)($_GET['uid'] ?? ''));
$filterEmail  = trim((string)($_GET['email'] ?? ''));
$filterSeal   = (string)($_GET['seal'] ?? 'tots');
$filterPendingTech = ((string)($_GET['pendents'] ?? '') === '1');

$sort = (string)($_GET['sort'] ?? 'id');
$dir  = strtolower((string)($_GET['dir'] ?? 'desc'));
$dir  = in_array($dir, ['asc','desc'], true) ? $dir : 'desc';

$sortMap = [
  'id'    => 'r.ID_Rider',
  'uid'   => 'r.Rider_UID',
  'email' => 'u.Email_Usuari',
  'desc'  => 'r.Descripcio',
  'ref'   => 'r.Referencia',
  'score' => 'r.Valoracio',
  'seal'  => 'r.Estat_Segell',
  'redir' => 'r.rider_actualitzat',
  'mida'  => 'r.Mida_Bytes',
];
// Construcció d'ORDER BY
if ($sort === 'ia') {
  // Mapa de prioritat per a ASC (de més “crític” a menys)
  // error -> running -> queued -> success -> (NULL/altres)
  $caseExpr = "
    CASE
      WHEN ia.status = 'error'   THEN 0
      WHEN ia.status = 'running' THEN 1
      WHEN ia.status = 'queued'  THEN 2
      WHEN ia.status = 'success' THEN 3
      ELSE 4
    END
  ";

  // Si l’usuari demana DESC, invertim el sentit del CASE
  // (això fa que 'success' quedi primer en DESC)
  $orderSql = $caseExpr . ' ' . strtoupper($dir)
           . ", ia.started_at " . ($dir === 'asc' ? 'ASC' : 'DESC')
           . ", r.ID_Rider " . ($dir === 'asc' ? 'ASC' : 'DESC');

} else {
  $orderBy  = $sortMap[$sort] ?? $sortMap['id'];
  $orderSql = $orderBy . ' ' . strtoupper($dir) . ', r.ID_Rider ' . strtoupper($dir);
}

// WHERE
$where  = [];
$params = [];
if ($filterQ !== '') {
  $like = '%' . $filterQ . '%';
  $parts = [
    'r.Descripcio LIKE :q1',
    'r.Referencia LIKE :q2',
    'r.Nom_Arxiu LIKE :q3',
    'r.Rider_UID LIKE :q4',
  ];
  if (ctype_digit($filterQ)) {
    $parts[] = 'r.ID_Rider = :qid';
    $params[':qid'] = (int)$filterQ;
  }
  $where[] = '(' . implode(' OR ', $parts) . ')';
  $params[':q1'] = $like;
  $params[':q2'] = $like;
  $params[':q3'] = $like;
  $params[':q4'] = $like;
}
if ($filterUserId !== '' && ctype_digit($filterUserId)) {
  $where[] = "u.ID_Usuari = :uid";
  $params[':uid'] = (int)$filterUserId;
}
if ($filterEmail !== '') {
  $where[] = "u.Email_Usuari LIKE :email";
  $params[':email'] = '%' . $filterEmail . '%';
}
$validSeals = ['cap','pendent','validat','caducat'];
if (in_array($filterSeal, $validSeals, true)) {
  $where[]         = "LOWER(COALESCE(r.Estat_Segell_lc, r.Estat_Segell)) = :seal";
  $params[':seal'] = strtolower($filterSeal);
}
if ($filterPendingTech) {
  $where[] = "(r.Validacio_Manual_Solicitada = 1 AND COALESCE(r.Estat_Segell_lc,'') NOT IN ('validat','caducat'))";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Resum global
$totalsStmt = $pdo->query("
  SELECT
  COUNT(*) AS total_riders,
  SUM(Mida_Bytes) AS total_bytes,
  SUM(CASE WHEN COALESCE(Estat_Segell_lc,'') = 'validat' THEN 1 ELSE 0 END)  AS riders_validats,
  SUM(CASE WHEN COALESCE(Estat_Segell_lc,'') = 'pendent' THEN 1 ELSE 0 END)  AS riders_pendents,
  SUM(CASE WHEN Validacio_Manual_Solicitada = 1
           AND COALESCE(Estat_Segell_lc,'') NOT IN ('validat','caducat') THEN 1 ELSE 0 END) AS riders_pendents_tecnics
  FROM Riders
");
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
// ── Càlcul global d'espai real a R2 (dedupe per SHA, Riders + Documents)
$totalBytesR2 = 0;
try {
  $sqlBytes = "
    SELECT SUM(sz) AS total_bytes FROM (
      SELECT sha, MAX(sz) AS sz FROM (
        SELECT TRIM(COALESCE(r.Hash_SHA256,'')) AS sha,
               COALESCE(r.Mida_Bytes,0)         AS sz
        FROM Riders r
        WHERE COALESCE(r.Hash_SHA256,'') <> ''

        UNION ALL
        SELECT TRIM(COALESCE(d.SHA256,'')) AS sha,
               COALESCE(d.Mida_Bytes,0)     AS sz
        FROM Documents d
        WHERE COALESCE(d.SHA256,'') <> ''

        UNION ALL
        SELECT TRIM(COALESCE(d.Hash_SHA256,'')) AS sha,
               COALESCE(d.Mida_Bytes,0)         AS sz
        FROM Documents d
        WHERE COALESCE(d.Hash_SHA256,'') <> ''
      ) t
      WHERE t.sha <> ''
      GROUP BY t.sha
    ) u
  ";
  $stB = $pdo->query($sqlBytes);
  $totalBytesR2 = (int)($stB->fetchColumn() ?? 0);
} catch (Throwable $e) {
  // Fallback segur: només Riders
  $totalBytesR2 = (int)($totals['total_bytes'] ?? 0);
}

$totalRiders          = (int)($totals['total_riders'] ?? 0);
$totalBytes           = (int)($totals['total_bytes'] ?? 0);
$totalValidats        = (int)($totals['riders_validats'] ?? 0);
$totalPendents        = (int)($totals['riders_pendents'] ?? 0);
$totalPendentsTecnics = (int)($totals['riders_pendents_tecnics'] ?? 0);

// ── Count
$stc = $pdo->prepare("
  SELECT COUNT(*)
    FROM Riders r
    JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
  $whereSql
");
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// ── Data
$sql = "
  SELECT
    r.ID_Rider, r.Rider_UID, r.Nom_Arxiu, r.Descripcio, r.Referencia,
    r.Valoracio, r.Estat_Segell, r.Mida_Bytes, r.rider_actualitzat,
    r.Validacio_Manual_Solicitada, r.Validacio_Manual_Data,
    u.ID_Usuari AS UID, u.Email_Usuari AS Email_Usuari,

    -- ✨ IA (darrera execució)
    ia.job_uid     AS IA_Job,
    ia.status      AS IA_Status,      -- p.ex. 'queued','running','success','error'
    ia.score       AS IA_Score,       -- si ho guardes a ia_runs
    ia.started_at  AS IA_Started_At,
    ia.finished_at AS IA_Finished_At,
    ia.error_msg   AS IA_Error

  FROM Riders r
  JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari

  -- ✨ Darrera execució IA per rider (LEFT JOIN perquè pot no existir)
  LEFT JOIN (
    SELECT x.*
    FROM (
      SELECT ir.*,
             ROW_NUMBER() OVER (
               PARTITION BY ir.rider_id
               ORDER BY ir.started_at DESC, ir.id DESC
             ) AS rn
      FROM ia_runs ir
    ) x
    WHERE x.rn = 1
  ) ia ON ia.rider_id = r.ID_Rider

  $whereSql
  ORDER BY $orderSql
  LIMIT :limit OFFSET :ofs
";
$sth = $pdo->prepare($sql);
foreach ($params as $k => $v) { $sth->bindValue($k, $v); }
$sth->bindValue(':limit', $perPage, PDO::PARAM_INT);
$sth->bindValue(':ofs',   $offset,  PDO::PARAM_INT);
$sth->execute();
$rows = $sth->fetchAll(PDO::FETCH_ASSOC);

// URLs
$baseQS = [
  'seccio' => 'admin_riders',
  'q'      => $filterQ,
  'uid'    => $filterUserId,
  'email'  => $filterEmail,
  'seal'   => $filterSeal,
  'pendents' => $filterPendingTech ? '1' : '',
  'per'    => $perPage,
];
$sortUrl = function (string $key) use ($baseQS, $sort, $dir) {
  $qs = $baseQS; $qs['sort'] = $key; $qs['dir'] = ($sort === $key && $dir==='asc') ? 'desc' : 'asc';
  return BASE_PATH . 'espai.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
};
$sortIcon = function (string $key) use ($sort, $dir) {
  if ($sort !== $key) return '<i class="bi bi-arrow-down-up ms-1 text-secondary"></i>';
  return $dir === 'asc' ? '<i class="bi bi-arrow-up-short ms-1"></i>' : '<i class="bi bi-arrow-down-short ms-1"></i>';
};

// ✅ Preparem la consulta del darrer job un cop
// $lastJobSt = $pdo->prepare("SELECT job_uid FROM ia_runs WHERE rider_id=? ORDER BY started_at DESC LIMIT 1");
?>
<div class="container-fluid my-0">
  <div class="card border-0">
    <div class="card-header bg-dark">
      <h5 class="card-title mb-0">Tots els riders</h5>
    </div>
    <div class="card-body bd-0">
    

<?php /* ───────────── Caixa: Riders pendents de validació tècnica ───────────── */ 
$stPending = $pdo->query("
  SELECT r.ID_Rider, r.Rider_UID, r.Descripcio, r.Nom_Arxiu, r.Referencia, r.Validacio_Manual_Data,
         u.Email_Usuari
    FROM Riders r
    JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
   WHERE r.Validacio_Manual_Solicitada = 1
     AND LOWER(COALESCE(r.Estat_Segell,'')) NOT IN ('validat','caducat')
   ORDER BY COALESCE(r.Validacio_Manual_Data, r.Data_Pujada) DESC, r.ID_Rider DESC
");
$pending = $stPending->fetchAll(PDO::FETCH_ASSOC);
if ($pending):
?>
<div class="container mt-3 mb-3 w-75">
  <div class="card border border-secondary-subtle shadow-sm">
    <div class="card-header bg-kinosonik border-0 d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <h6 class="mb-0">Riders pendents de validació tècnica</h6>
        <span class="badge text-bg-light"><?= (int)count($pending) ?></span>
      </div>
    </div>
    <div class="card-body p-0">
      <ul class="list-group list-group-flush small">
        <?php foreach ($pending as $p):
          $pid    = (int)$p['ID_Rider'];
          $puid   = (string)$p['Rider_UID'];
          $pdesc  = trim((string)($p['Descripcio'] ?? ''));
          if ($pdesc === '') $pdesc = (string)($p['Nom_Arxiu'] ?? ('RD'.$pid));
          $pref   = trim((string)($p['Referencia'] ?? ''));
          $pemail = (string)$p['Email_Usuari'];
          $pdt    = (string)($p['Validacio_Manual_Data'] ?? '');
          $pdtStr = '—';
          if ($pdt !== '' && $pdt !== '0000-00-00 00:00:00') {
           $pdtStr = dt_eu($pdt);
          }
        ?>
        <li class="list-group-item d-flex align-items-center flex-wrap gap-2">
          <span class="text-secondary fw-lighter">#<?= h((string)$pid) ?></span>
          <span class="me-2 fw-lighter"><?= h($pdesc) ?></span>
          <?php if ($pref !== ''): ?>
            <span class="me-2 fw-lighter">(Ref: <?= h($pref) ?>)</span>
          <?php endif; ?>
          <span class="ms-auto d-flex align-items-center gap-3">
            <span class="text-secondary"><i class="bi bi-envelope-open"></i> <?= h($pemail) ?></span>
            <span class="text-secondary"><i class="bi bi-calendar-event"></i> <?= h($pdtStr) ?></span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Accions rider">
              <a href="<?= h(BASE_PATH) ?>php/rider_file.php?ref=<?= h($puid) ?>&dl=1"
                 class="btn btn-primary" title="Descarrega PDF">
                <i class="bi bi-download"></i>
              </a>
              <button type="button" class="btn btn-primary admin-reupload-btn"
                      data-uid="<?= h($puid) ?>" data-id="<?= (int)$pid ?>" data-csrf="<?= h($CSRF) ?>"
                      title="Repujar nou PDF">
                <i class="bi bi-arrow-repeat"></i>
              </button>
              <input type="file" accept="application/pdf" class="d-none admin-reupload-input"
                     data-uid="<?= h($puid) ?>">
              <button type="button" class="btn btn-success mark-tech-ok"
                      data-uid="<?= h($puid) ?>" data-csrf="<?= h($CSRF) ?>"
                      title="Marcar validat tècnicament">
                <i class="bi bi-check2-circle"></i>
              </button>
            </div>
          </span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php endif; ?>

<?php /* ───────────── Filtres ───────────── */ ?>
<div class="container mt-4 mb-3 w-75">
  <form class="row row-cols-auto g-2 align-items-end mb-3" method="get" action="<?= h(BASE_PATH) ?>espai.php">
    <input type="hidden" name="seccio" value="admin_riders">

    <div class="col">
      <label class="form-label small mb-0">Cerca</label>
      <input type="text" class="form-control form-control-sm" name="q"
             value="<?= h($filterQ) ?>" placeholder="Descripció, referència, fitxer o UID">
    </div>

    <div class="col">
      <label class="form-label small mb-0">ID</label>
      <input type="text" class="form-control form-control-sm w-auto" name="uid"
             value="<?= h($filterUserId) ?>" pattern="\d*" inputmode="numeric" style="max-width: 90px;">
    </div>

    <div class="col">
      <label class="form-label small mb-0">Email</label>
      <input type="text" class="form-control form-control-sm" name="email" value="<?= h($filterEmail) ?>">
    </div>

    <div class="col">
      <label class="form-label small mb-0">Segell</label>
      <select class="form-select form-select-sm" name="seal">
        <?php $opts = ['tots'=>'Tots','cap'=>'Cap','pendent'=>'Pendent','validat'=>'Validat','caducat'=>'Caducat'];
        foreach ($opts as $k=>$v): ?>
          <option value="<?= h($k) ?>" <?= $filterSeal===$k?'selected':''; ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col">
      <label class="form-label small mb-0 d-block">Pendents</label>
      <div class="form-check form-switch mt-1">
        <input class="form-check-input" type="checkbox" role="switch" id="fPendents"
               name="pendents" value="1" <?= $filterPendingTech ? 'checked' : '' ?>>
        <label class="form-check-label small" for="fPendents">Validació tècnica</label>
      </div>
    </div>

    <div class="col">
      <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_riders">Neteja</a>
    </div>
  </form>
</div>

<?php /* ───────────── Resum resultats + per pàgina ───────────── */ ?>
<div class="d-flex justify-content-between align-items-center small text-secondary mb-2">
  <div>
    Resultats: <span class="text-body"><?= h((string)$total) ?></span>
    · Pàgina <span class="text-body"><?= h((string)$page) ?></span>
    de <span class="text-body"><?= h((string)$totalPages) ?></span>
  </div>
  <form method="get" class="d-inline">
    <?php foreach ($baseQS as $k => $v): ?>
      <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="sort" value="<?= h($sort) ?>">
    <input type="hidden" name="dir"  value="<?= h($dir) ?>">
    <select name="per" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
      <?php foreach ([10,20,50,100] as $n): ?>
        <option value="<?= $n ?>" <?= $perPage===$n?'selected':''; ?>><?= $n ?>/pàg</option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

      <!-- Taula -->
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle fw-lighter small mb-0">
          <thead class="table-dark">
            <tr>
              <th class="text-center fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('id')) ?>">ID <?= $sortIcon('id') ?></a>
              </th>
              <th class="fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('desc')) ?>">Descripció <?= $sortIcon('desc') ?></a>
              </th>
              <th class="fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('ref')) ?>">Ref. <?= $sortIcon('ref') ?></a>
              </th>
              <th class="fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('email')) ?>">E-mail <?= $sortIcon('email') ?></a>
              </th>
              <th class="text-center fw-lighter" title="Segell">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('seal')) ?>"><i class="bi bi-shield-shaded"></i> <?= $sortIcon('seal') ?></a>
              </th>
              <th class="text-center fw-lighter" title="Redirecció">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('redir')) ?>"><i class="bi bi-link-45deg"></i> <?= $sortIcon('redir') ?></a>
              </th>
              <th class="text-center fw-lighter" title="Mida">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('mida')) ?>"><i class="bi bi-hdd"></i> <?= $sortIcon('mida') ?></a>
              </th>
              <th class="text-center fw-lighter" title="Estat IA">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('ia')) ?>">
                  <i class="bi bi-cpu"></i> <?= $sortIcon('ia') ?>
                </a>
              </th>
              <th class="text-center fw-lighter" title="Accions">Accions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-secondary py-4">Cap resultat.</td></tr>
          <?php else: ?>
            <?php
              $iconMap = [
                'validat' => ['bi-shield-fill-check',  'text-success', 'Validat'],
                'caducat' => ['bi-shield-fill-x',      'text-danger',  'Caducat'],
                'pendent' => ['bi-shield-exclamation', 'text-warning', 'Pendent'],
                'cap'     => ['bi-shield',             'text-secondary','Sense segell']
              ];
            ?>
            <?php foreach ($rows as $r):
              $id   = (int)$r['ID_Rider'];
              $uid  = (string)$r['Rider_UID'];
              $desc = trim((string)($r['Descripcio'] ?? ''));
              $ref  = trim((string)($r['Referencia'] ?? ''));
              $estat = strtolower(trim((string)($r['Estat_Segell'] ?? 'cap')));
              $mida  = (int)($r['Mida_Bytes'] ?? 0);
              $uidOwner = (int)$r['UID'];
              $email    = (string)$r['Email_Usuari'];
              $hasRedir = !empty($r['rider_actualitzat']);
              $manualReq = (int)($r['Validacio_Manual_Solicitada'] ?? 0);
              $pendingTech = ($manualReq === 1) && !in_array($estat, ['validat','caducat'], true);
              $rowStyle  = $pendingTech ? ' style="border-left:4px solid #dc3545;"' : '';
              $display = $desc !== '' ? $desc : ( ($r['Nom_Arxiu'] ?? '') ?: $uid );
              [$ic,$col,$title] = $iconMap[$estat] ?? ['bi-shield','text-secondary','Segell'];
              $canCopyView = ($estat === 'validat' || $estat === 'caducat');
              $userLink = BASE_PATH . 'espai.php?seccio=dades&user=' . $uidOwner;

              $iaStatus = strtolower(trim((string)($r['IA_Status'] ?? '')));
              $iaScore  = $r['IA_Score'] ?? null;
              $iaJob    = (string)($r['IA_Job'] ?? '');
              $iaStarted = $r['IA_Started_At'] ?? null;
              $iaFinished = $r['IA_Finished_At'] ?? null;
              $iaErr    = trim((string)($r['IA_Error'] ?? ''));

              // Mapeig d'icones
              $IA_ICON = [
                'queued'  => ['bi-hourglass-split','text-secondary','A la cua'],
                'running' => ['bi-lightning-charge','text-warning','Executant'],
                'success' => ['bi-check-circle-fill','text-success','Complet'],
                'ok'      => ['bi-check-circle-fill','text-success','Complet'],
                'error'   => ['bi-exclamation-triangle-fill','text-danger','Error'],
              ];
              [$iaIc, $iaCol, $iaTitle] = $IA_ICON[$iaStatus] ?? ['bi-dash-circle','text-secondary','Sense execució'];

              $iaTip = '';
              
              // Tooltip
              if ($iaScore !== null)   $iaTip .= " · score: {$iaScore}";
              if ($iaStarted)          $iaTip .= " · start: {$iaStarted}";
              if ($iaFinished)         $iaTip .= " · end: {$iaFinished}";
              if ($iaStatus === 'error' && $iaErr !== '') $iaTip .= " · err: " . mb_strimwidth($iaErr, 0, 120, '…');

              ?>
              <tr <?= $rowStyle ?> data-row-uid="<?= h($uid) ?>">
                <td class="text-center text-secondary"><?= h((string)$id) ?></td>
                <td><?= h($display) ?></td>
                <td><?= h($ref) ?></td>
                <td>
                  <a href="<?= h($userLink) ?>" class="link-body-emphasis text-decoration-none" title="Obre fitxa de l’usuari">
                    <?= h($email) ?>
                  </a>
                </td>
                

                <!-- Segell -->
                <td class="text-center" data-bs-toggle="tooltip" data-bs-title="<?= h($title) ?>">
                  <div class="d-inline-flex align-items-center gap-2 flex-nowrap">
                    <i class="seal-icon bi <?= h($ic) ?> <?= h($col) ?>" data-estat="<?= h($estat) ?>" data-uid="<?= h($uid) ?>"></i>
                    <select class="form-select form-select-sm seal-select w-auto"
                            data-uid="<?= h($uid) ?>"
                            data-csrf="<?= h($CSRF) ?>">
                        <?php foreach (['cap'=>'Cap','pendent'=>'Pendent','validat'=>'Validat','caducat'=>'Caducat'] as $val=>$lbl): ?>
                        <option value="<?= h($val) ?>" <?= $estat===$val?'selected':''; ?>><?= h($lbl) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </td>

                <!-- Redirecció -->
                <td class="text-center">
                  <?php if ($hasRedir): ?>
                    <i class="bi bi-link-45deg text-success" title="Té redirecció"></i>
                  <?php else: ?>
                    <i class="bi bi-x-circle text-danger" title="Sense redirecció"></i>
                  <?php endif; ?>
                </td>
                <!-- Tamany arxiu -->
                <td class="text-center"><?= h(humanBytes($mida)) ?></td>

                <!-- Admin iA -->
                <td class="text-center"
                  <?= $iaTip !== '' ? 'data-bs-toggle="tooltip" data-bs-title="'.h(ltrim($iaTip, " ·")).'"' : '' ?>>
                  <div class="d-inline-flex align-items-center gap-2">
                    <i class="bi <?= h($iaIc) ?> <?= h($iaCol) ?>"></i>
                    <?php if ($iaScore !== null): ?>
                      <span class="text-secondary small"><?= h((string)$iaScore) ?></span>
                    <?php endif; ?>
                    <?php if ($iaJob !== ''): ?>
                    <a class="btn btn-outline-secondary btn-sm py-0 px-1"
                      href="<?= h(BASE_PATH) ?>php/admin/log_view.php?job=<?= h($iaJob) ?>&mode=html"
                      target="_blank" title="Veure log IA" rel="noopener">
                      <i class="bi bi-journal-text"></i>
                    </a>
                    <?php else: ?>
                    <button class="btn btn-outline-secondary btn-sm py-0 px-1" disabled title="Sense log">
                      <i class="bi bi-journal-text"></i>
                    </button>
                    <?php endif; ?>
                  </div>
                </td>
                <!-- Accions -->
                <td class="text-end actions-cell">
                  <div class="d-inline-flex align-items-center gap-1 flex-nowrap">
                    <div class="btn-group btn-group-sm flex-nowrap" role="group">
                      <?php if ($canCopyView): ?>
                      <button type="button" class="btn btn-primary btn-sm copy-link-btn"
                        data-uid="<?= h($uid) ?>" data-bs-toggle="tooltip" data-bs-title="<?= h(__('common.copy') ?: 'Copiar enllaç') ?>">
                        <i class="bi bi-qr-code"></i>
                      </button>
                      <a href="<?= h(BASE_PATH.'visualitza.php?ref='.rawurlencode($uid)) ?>"
                        class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-title="<?= h(__('riders.actions.view_card') ?: 'Pàgina pública') ?>">
                        <i class="bi bi-box-arrow-up-right"></i>
                      </a>
                      <?php else: ?>
                      <button type="button" class="btn btn-secondary btn-sm" disabled data-bs-toggle="tooltip" data-bs-title="<?= h(__('common.copy') ?: 'Copiar enllaç') ?>">
                        <i class="bi bi-qr-code"></i>
                      </button>
                      <button type="button" class="btn btn-secondary btn-sm" disabled data-bs-toggle="tooltip" data-bs-title="<?= h(__('riders.actions.view_card') ?: 'Pàgina pública') ?>">
                        <i class="bi bi-box-arrow-up-right"></i>
                      </button>
                      <?php endif; ?>
                      <!-- Veure PDF -->
                      <a href="<?= h(BASE_PATH) ?>php/rider_file.php?ref=<?= h($uid) ?>"
                        class="btn btn-primary btn-sm" target="_blank" rel="noopener" data-bs-toggle="tooltip" data-bs-title="<?= h(__('riders.table.col_published') ?: 'Veure PDF') ?>">
                        <i class="bi bi-eye"></i>
                      </a>
                      <!-- Caducar (soft-delete) → només té sentit si és 'validat' -->
                      <?php if ($estat === 'validat'): ?>
                      <button type="button"
                        class="btn btn-primary btn-sm expire-btn text-danger"
                        data-id="<?= (int)$id ?>"
                        data-uid="<?= h($uid) ?>"
                        data-csrf="<?= h($CSRF) ?>"
                        data-bs-toggle="tooltip"
                        data-bs-title="<?= h(__('riders.actions.expire') ?: 'Caducar') ?>">
                        <i class="bi bi-shield-fill-x"></i>
                      </button>
                      <?php else: ?>
                      <button type="button" class="btn btn-secondary btn-sm" disabled
                        data-bs-toggle="tooltip" data-bs-title="<?= h(__('riders.actions.expire') ?: 'Caducar') ?>">
                        <i class="bi bi-shield-x"></i>
                      </button>
                      <?php endif; ?>
                      <!-- Auditoria del rider --> 
                      <?php
                      $auditUrl = BASE_PATH . 'espai.php?' . http_build_query(
                        ['seccio' => 'admin_audit', 'rider_uid' => $uid, 'per' => 50],
                        '', '&', PHP_QUERY_RFC3986
                      );
                      ?>
                      <a href="<?= h($auditUrl) ?>"
                        class="btn btn-primary btn-sm"
                        data-bs-toggle="tooltip"
                        data-bs-title="Auditoria del rider">
                        <i class="bi bi-clipboard-data"></i>
                      </a>
                      <?php
                        // Actiu si hi ha com a mínim una execució IA (ja tens $iaJob de la LEFT JOIN)
                        $hasRuns = ($iaJob !== '');
                        // URL a admin_logs filtrat per rider
                        $logsUrl = BASE_PATH . 'espai.php?' . http_build_query(
                          ['seccio' => 'admin_logs', 'rider' => $id, 'per' => 50],
                          '', '&', PHP_QUERY_RFC3986
                        );
                      ?>
                      <?php if ($hasRuns): ?>
                        <a class="btn btn-sm btn-primary" href="<?= h($logsUrl) ?>" target="_blank" rel="noopener"
                          data-bs-toggle="tooltip" data-bs-title="Veure execucions IA d’aquest rider">
                          <i class="bi bi-cpu"></i>
                        </a>
                      <?php else: ?>
                        <button class="btn btn-sm btn-secondary" disabled data-bs-toggle="tooltip" data-bs-title="Sense execucions IA">
                          <i class="bi bi-cpu"></i>
                        </button>
                      <?php endif; ?>
                      <!-- Hard delete (obrir modal) -->
                      <button type="button"
                        class="btn btn-danger btn-sm admin-delete-btn"
                        data-id="<?= (int)$id ?>"
                        data-uid="<?= h($uid) ?>"
                        data-email="<?= h($email) ?>"
                        data-desc="<?= h($display) ?>"
                        data-csrf="<?= h($CSRF) ?>"
                        data-bs-toggle="tooltip"
                        data-bs-title="<?= h(__('common.delete') ?: 'Eliminar') ?>">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </div>
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
        $pageUrl = function(int $p) use ($baseQS, $sort, $dir) {
          $qs = $baseQS; $qs['page'] = $p; $qs['sort'] = $sort; $qs['dir'] = $dir;
          return BASE_PATH . 'espai.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
        };
      ?>
      <div>
      <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center mb-0">
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= h($pageUrl(max(1,$page-1))) ?>">«</a></li>
          <?php
            $start = max(1, $page-2);
            $end   = min($totalPages, $page+2);
            if ($start > 1) {
              echo '<li class="page-item"><a class="page-link" href="'.h($pageUrl(1)).'">1</a></li>';
              if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
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
          </div>
      <?php endif; ?>




    <?php /* ───────────── Cards resum ───────────── */ ?>
      <div class="card border-0 mb-3">
        <div class="card-body py-3">
          <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 text-center">
            <div class="col">
              <div class="p-3 border rounded-3 h-100">
                <div class="small text-secondary">Espai total (R2)</div>
                <div class="fs-5 fw-semibold mt-1"><?= h(humanBytes($totalBytesR2)) ?></div>
              </div>
            </div>
            <div class="col">
              <div class="p-3 border rounded-3 h-100">
                <div class="small text-secondary">Riders validats</div>
                <div class="fs-5 fw-semibold mt-1"><?= h(number_format($totalValidats)) ?></div>
              </div>
            </div>
            <div class="col">
              <div class="p-3 border rounded-3 h-100">
                <div class="small text-secondary">Riders pujats</div>
                <div class="fs-5 fw-semibold mt-1"><?= h(number_format($totalRiders)) ?></div>
              </div>
            </div>
            <div class="col">
              <div class="p-3 border rounded-3 h-100">
                <div class="small text-secondary">Riders pendents</div>
                <div class="fs-5 fw-semibold mt-1"><?= h(number_format($totalPendents)) ?></div>
              </div>
            </div>
            <div class="col">
              <div class="p-3 border rounded-3 h-100">
                <div class="small text-secondary">Pendents validació tècnica</div>
                <div class="fs-5 fw-semibold mt-1"><?= h(number_format($totalPendentsTecnics)) ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>





      <!-- Widget IA -->
      <?php
      require_once __DIR__ . '/php/ia_admin_tools.php';
      if (!empty($isAdmin)) {
      $iaWidgetPath = __DIR__ . '/php/ia_widget.php';
      if (is_readable($iaWidgetPath)) require $iaWidgetPath;
      }
      $ia_msg = null; $ia_log = []; $ia_diag = null;

      if (!empty($isAdmin) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ia_action'])) {
        if (!csrf_check($_POST['csrf'] ?? '')) {
          $ia_msg = ['type'=>'warning', 'text'=>'CSRF invàlid'];
        } else {
          $act = $_POST['ia_action'];
          if ($act === 'diag') {
            $diag = ia_diag($pdo);
            $ia_msg = ['type'=>'success', 'text'=>'Diagnòstic executat'];
            $ia_diag = $diag; // per pintar més avall
          } elseif ($act === 'fix') {
            $res = ia_fix($pdo);
            $ia_msg = ['type'=> $res['ok'] ? 'success':'warning', 'text'=> $res['ok'] ? 'Auto-neteja feta' : 'Auto-neteja amb errors'];
            $ia_log = $res['log'];
            // opcional: tornar a fer diagnòstic després del fix
            $ia_diag = ia_diag($pdo);
          }
        }
      }
?>

<div class="d-flex align-items-center gap-2 my-2">
  <form method="post" class="mb-0">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <button class="btn btn-secondary btn-sm" name="ia_action" value="diag">
      <i class="bi bi-search"></i> Diagnosticar inconsistències
    </button>
  </form>

  <form method="post" class="mb-0" onsubmit="return confirm('Vols executar l\\\'auto-neteja segura?');">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <button class="btn btn-secondary btn-sm" name="ia_action" value="fix">
      <i class="bi bi-tools"></i> Auto-neteja segura
    </button>
  </form>
</div>

<?php if ($ia_msg): ?>
  <div class="alert alert-<?= htmlspecialchars($ia_msg['type']) ?> fw-lighter py-2">
    <?= htmlspecialchars($ia_msg['text']) ?>
  </div>
<?php endif; ?>

<?php if (!empty($ia_log)): ?>
  <div class="card text-bg-dark my-2">
    <div class="card-body py-2">
      <div class="fw-lighter mb-1">Log d’operacions</div>
      <pre class="small mb-0"><?= htmlspecialchars(implode("\n", $ia_log)) ?></pre>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($ia_diag)): ?>
  <div class="row g-3 my-2 fw-lighter">
    <div class="col-md-4">
      <div class="card text-bg-dark h-100">
        <div class="card-body">
          <div class="mb-2">Duplicats actius per rider</div>
          <?php if ($ia_diag['dup_actius']): ?>
            <table class="table table-sm table-dark table-striped mb-0">
              <thead><tr><th>Rider</th><th>Actius</th></tr></thead>
              <tbody>
                <?php foreach ($ia_diag['dup_actius'] as $r): ?>
                  <tr><td><?= (int)$r['rider_id'] ?></td><td><?= (int)$r['actius'] ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="text-success small">Cap duplicat actiu 🎉</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card text-bg-dark h-100">
        <div class="card-body">
          <div class="mb-2">Jobs finished sense run</div>
          <?php if ($ia_diag['finished_without_run']): ?>
            <div class="small text-muted mb-2">Mostrant fins a 200</div>
            <table class="table table-sm table-dark table-striped mb-0">
              <thead><tr><th>ID</th><th>Rider</th><th>Status</th><th>Fi</th></tr></thead>
              <tbody>
              <?php foreach ($ia_diag['finished_without_run'] as $j): ?>
                <tr>
                  <td><?= (int)$j['id'] ?></td>
                  <td><?= (int)$j['rider_id'] ?></td>
                  <td><?= htmlspecialchars($j['status']) ?></td>
                  <td><?= htmlspecialchars(dt_eu($j['finished_at'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="text-success small">Cap cas 🎉</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card text-bg-dark h-100">
        <div class="card-body">
          <div class="mb-2">Runs orfes</div>
          <?php if ($ia_diag['runs_orfes']): ?>
            <div class="small text-muted mb-2">Mostrant fins a 200</div>
            <table class="table table-sm table-dark table-striped mb-0">
              <thead><tr><th>ID</th><th>Rider</th><th>Inici</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($ia_diag['runs_orfes'] as $r): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= (int)$r['rider_id'] ?></td>
                  <td><?= htmlspecialchars(dt_eu($r['started_at'] ?? '')) ?></td>
                  <td><?= htmlspecialchars($r['status'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="text-success small">Cap run orfe 🎉</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
      
     
    </div>
  </div>
</div>

<!-- Modal: Eliminar rider (HARD DELETE) -->
<div class="modal fade" id="adminDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content liquid-glass-kinosonik">
      <div class="modal-header bg-danger border-0 text-white">
        <h6 class="modal-title mb-0"><?= h(__('riders.delete.title') ?: 'Eliminar rider') ?></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"></button>
      </div>

      <!-- 📌 El formulari real que fa POST a delete_rider.php -->
      <form method="POST" action="<?= h(BASE_PATH) ?>php/delete_rider.php" id="adm-del-form">
        <input type="hidden" id="adm-del-csrf"  name="csrf" value="">
        <input type="hidden" id="adm-del-uid"   name="rider_uid" value="">
        <input type="hidden" id="adm-del-idin"  name="rider_id"  value="">
        <input type="hidden" name="context" value="admin">
        
        <div class="modal-body small">
          <p class="text-danger fw-semibold mb-2"><?= h(__('riders.delete.note') ?: 'Aquesta acció és definitiva i també purgarà els logs i execucions d’IA associats.') ?></p>

          <div class="border rounded p-2 bg-kinosonik">
            <div class="row">
              <div class="col-4 text-muted"><?= h(__('riders.expire.modal.id') ?: 'ID') ?></div>
              <div class="col-8"><span id="adm-del-id">—</span></div>
            </div>
            <div class="row">
              <div class="col-4 text-muted"><?= h(__('riders.expire.modal.desc') ?: 'Descripció') ?></div>
              <div class="col-8"><span id="adm-del-desc">—</span></div>
            </div>
            <div class="row">
              <div class="col-4 text-muted">E-mail</div>
              <div class="col-8"><span id="adm-del-email">—</span></div>
            </div>
          </div>

          <!-- Checklist per habilitar el botó -->
          <div class="mt-3">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input adm-del-req" type="checkbox" id="adm-del-ck-irreversible">
              <label class="form-check-label" for="adm-del-ck-irreversible">
                <?= h(__('profile.delete_irreversible') ?: 'Entenc que aquesta acció és irreversible i no es pot desfer.') ?>
              </label>
            </div>
            <div class="form-check form-switch mb-2">
              <input class="form-check-input adm-del-req" type="checkbox" id="adm-del-ck-purge">
              <label class="form-check-label" for="adm-del-ck-purge">
                <?= h(__('riders.delete.note') ?: 'S’esborrarà el fitxer a R2 i es purgaran execucions i logs d’IA.') ?>
              </label>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
            <?= h(__('common.cancel') ?: 'Cancel·la') ?>
          </button>
          <button type="submit" class="btn btn-danger btn-sm" id="adm-del-confirm" disabled>
            <?= h(__('common.delete') ?: 'Sí, elimina’l') ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Caducar rider (admin) -->
<div class="modal fade" id="adminExpireModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content liquid-glass-kinosonik">
      <div class="modal-header bg-danger border-0 justify-content-center position-relative">
        <h6 class="modal-title text-center text-uppercase fw-bold">
          <?= h(__('riders.expire.modal.title') ?: 'Caducar rider') ?>
        </h6>
        <button type="button" class="btn-close position-absolute end-0 me-2"
          data-bs-dismiss="modal" aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2 text-danger fw-semibold"><?= h(__('riders.expire.modal.lead') ?: 'Aquesta acció és irreversible.') ?></p>
        <p class="mb-2 fw-lighter small">
          <?= h(__('riders.expire.modal.body_intro') ?: 'Si caduques aquest rider:') ?><br>
          <?= h(__('riders.expire.modal.point_irrev') ?: '• No es podrà tornar a validar.') ?><br>
          <?= h(__('riders.expire.modal.point_redir') ?: '• Podràs redireccionar-lo a un rider validat més nou des del selector de “Redirecció”.') ?>
        </p>
        <div class="border rounded p-2 small bg-kinosonik">
          <div class="row">
            <div class="col-4 text-muted"><?= h(__('riders.expire.modal.id') ?: 'ID') ?></div>
            <div class="col-8"><span id="adm-exp-rider-id">—</span></div>
          </div>
          <div class="row">
            <div class="col-4 text-muted"><?= h(__('riders.expire.modal.desc') ?: 'Descripció') ?></div>
            <div class="col-8"><span id="adm-exp-rider-desc">—</span></div>
          </div>
        </div>
        <div class="mt-3 small" id="adm-exp-checklist">
          <div class="form-check form-switch mb-2 small">
            <input class="form-check-input adm-exp-req" type="checkbox" id="adm-exp-ck-irreversible">
            <label class="form-check-label" for="adm-exp-ck-irreversible">
              <?= h(__('riders.expire.ck.irreversible') ?: 'He entès que aquesta acció és irreversible i el rider no es podrà tornar a validar.') ?>
            </label>
          </div>
          <div class="form-check form-switch mb-2 small">
            <input class="form-check-input adm-exp-req" type="checkbox" id="adm-exp-ck-unpublish">
            <label class="form-check-label" for="adm-exp-ck-unpublish">
              <?= h(__('riders.expire.ck.unpublish') ?: 'He entès que aquest rider deixarà d’estar publicat/validat immediatament.') ?>
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
          <?= h(__('common.cancel') ?: 'Cancel·la') ?>
        </button>
        <button type="button" class="btn btn-danger btn-sm" id="adm-exp-confirm" disabled>
          <?= h(__('riders.expire.modal.confirm') ?: 'Sí, caduca’l') ?>
        </button>
      </div>
    </div>
  </div>
</div>
<!-- JS Caducar -->
 <script>
(function(){
  let modal, confirmBtn, idSpan, descSpan;
  const state = { uid:'', id:'', csrf:'', row:null, desc:'' };

  function updateEnabled(){
    if (!confirmBtn) return;
    const reqOK = Array.from(document.querySelectorAll('#adminExpireModal .adm-exp-req')).every(el => el.checked);
    confirmBtn.disabled = !(reqOK && state.uid && state.csrf);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('adminExpireModal');
    if (!modalEl) return;
    modal = new bootstrap.Modal(modalEl, { backdrop: 'static' });
    confirmBtn = document.getElementById('adm-exp-confirm');
    idSpan = document.getElementById('adm-exp-rider-id');
    descSpan = document.getElementById('adm-exp-rider-desc');

    modalEl.addEventListener('show.bs.modal', () => {
      modalEl.querySelectorAll('.adm-exp-req').forEach(el => el.checked = false);
      updateEnabled();
    });
    modalEl.addEventListener('change', (ev) => {
      if (ev.target.classList.contains('adm-exp-req')) updateEnabled();
    });
  });

  // Obrir modal
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.expire-btn');
    if (!btn) return;

    // amagar tooltip
    const tip = bootstrap.Tooltip.getInstance(btn);
    if (tip) tip.hide();

    const row = btn.closest('tr');
    const uid = btn.getAttribute('data-uid') || '';
    const id  = btn.getAttribute('data-id') || (row?.querySelector('td:first-child')?.textContent?.trim() || '');
    const csrf = btn.getAttribute('data-csrf') || '';

    const desc = row?.children?.[1]?.textContent?.trim() || '—';

    state.uid = uid; state.id = id; state.csrf = csrf; state.row = row; state.desc = desc;

    if (idSpan) idSpan.textContent = id || '—';
    if (descSpan) descSpan.textContent = desc || '—';

    updateEnabled();
    modal.show();
  });

  // Confirmar: canvia segell → 'caducat'
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('#adm-exp-confirm');
    if (!btn) return;
    if (!state.uid || !state.csrf) return;

    btn.disabled = true;
    const oldHTML = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' +
                    (<?= json_encode(__('loading.processing') ?: 'Processant…') ?>);

    try {
      const resp = await fetch('<?= h(BASE_PATH) ?>php/update_seal.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({ csrf: state.csrf, rider_uid: state.uid, estat: 'caducat' })
      });
      const json = await resp.json().catch(() => ({}));
      if (!resp.ok || !json.ok) {
        alert((json?.error) || <?= json_encode(__('common.error') ?: 'Error') ?>);
        btn.disabled = false;
        btn.innerHTML = oldHTML;
        return;
      }

      modal.hide();
      // refresca la pàgina per reflectir estat i opcions
      window.location = '<?= h(BASE_PATH) ?>espai.php?seccio=admin_riders&success=seal_expired';

    } catch(e) {
      console.error(e);
      alert(<?= json_encode(__('common.network_error') ?: 'Error de xarxa') ?>);
      btn.disabled = false;
      btn.innerHTML = oldHTML;
    }
  });
})();
</script>

<!-- JS: canviar segell + copiar enllaç -->
<script>
(function () {
  const ICON_MAP = {
    cap:     ['bi-shield',              'text-secondary'],
    pendent: ['bi-shield-exclamation',  'text-warning'],
    validat: ['bi-shield-fill-check',   'text-success'],
    caducat: ['bi-shield-fill-x',       'text-danger'],
  };

  function applySealIcon(iconEl, estat) {
    const [ic, col] = ICON_MAP[estat] || ICON_MAP.cap;
    iconEl.className = 'seal-icon bi ' + ic + ' ' + col;
  }

  // 🔹 Toast portal (no queda sota el navbar)
  function getToastContainer() {
    let tc = document.getElementById('toast-portal');
    if (!tc) {
      tc = document.createElement('div');
      tc.id = 'toast-portal';
      tc.className = 'position-fixed';
      tc.style.zIndex = '1085';
      const nav = document.querySelector('.navbar.fixed-top, .navbar.sticky-top');
      const offset = (nav?.offsetHeight || 56) + 12;
      tc.style.top = offset + 'px';
      tc.style.right = '12px';
      document.body.appendChild(tc);
    }
    return tc;
  }
  function showToast(message, variant = 'success', delay = 1600) {
    const tc = getToastContainer();
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${variant} border-0 shadow`;
    el.setAttribute('role','status');
    el.setAttribute('aria-live','assertive');
    el.setAttribute('aria-atomic','true');
    el.innerHTML =
      '<div class="d-flex">' +
        '<div class="toast-body">' + message + '</div>' +
        '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Tancar"></button>' +
      '</div>';
    tc.appendChild(el);
    new bootstrap.Toast(el, { delay }).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  // 🔧 Helpers per refrescar botons d'accions
  function ensureCopyLink(btnGroup, uid, enabled) {
    const existingEnabled = btnGroup.querySelector('.copy-link-btn');
    const existingDisabled = Array.from(btnGroup.querySelectorAll('button.btn'))
      .find(b => !b.classList.contains('copy-link-btn') && b.querySelector('.bi-qr-code'));

    if (enabled) {
      if (!existingEnabled) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-primary btn-sm copy-link-btn';
        btn.setAttribute('data-uid', uid);
        btn.setAttribute('data-bs-toggle', 'tooltip');
        btn.setAttribute('data-bs-title', 'Copiar enllaç');
        btn.innerHTML = '<i class="bi bi-qr-code"></i>';
        // Si hi havia la versió deshabilitada, la substituïm; si no, l’afegim al principi
        if (existingDisabled) {
          existingDisabled.replaceWith(btn);
        } else {
          btnGroup.insertBefore(btn, btnGroup.firstChild);
        }
      } else {
        existingEnabled.disabled = false;
        existingEnabled.classList.remove('btn-secondary');
        existingEnabled.classList.add('btn-primary');
      }
    } else {
      if (existingEnabled) {
        const disabledBtn = document.createElement('button');
        disabledBtn.type = 'button';
        disabledBtn.className = 'btn btn-secondary btn-sm';
        disabledBtn.disabled = true;
        disabledBtn.setAttribute('data-bs-toggle', 'tooltip');
        disabledBtn.setAttribute('data-bs-title', 'Copiar enllaç');
        disabledBtn.innerHTML = '<i class="bi bi-qr-code"></i>';
        existingEnabled.replaceWith(disabledBtn);
      }
      // si ja era deshabilitat, no fem res
    }
  }

  function ensurePublicLink(btnGroup, uid, enabled, basePath, absBase) {
    // Busca icona box-arrow (pot ser <a> actiu o <button> desactivat)
    const iconSel = '.bi-box-arrow-up-right';
    const current = btnGroup.querySelector(iconSel)?.closest('a,button');

    const originLike = absBase || window.location.origin;
    const base = (basePath || '').replace(/\/+$/,'') + '/';
    const href = originLike + base + 'visualitza.php?ref=' + encodeURIComponent(uid);

    if (enabled) {
      // volem <a> actiu
      if (!current || current.tagName !== 'A') {
        const a = document.createElement('a');
        a.href = href;
        a.className = 'btn btn-primary btn-sm';
        a.setAttribute('data-bs-toggle','tooltip');
        a.setAttribute('data-bs-title','Pàgina pública');
        a.innerHTML = '<i class="bi bi-box-arrow-up-right"></i>';
        if (current) current.replaceWith(a);
        else btnGroup.insertBefore(a, btnGroup.children[1] || null);
      } else {
        current.classList.remove('btn-secondary'); current.classList.add('btn-primary');
        current.removeAttribute('disabled');
        current.setAttribute('href', href);
      }
    } else {
      // volem <button> desactivat
      if (!current || current.tagName === 'A') {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-secondary btn-sm';
        b.disabled = true;
        b.setAttribute('data-bs-toggle','tooltip');
        b.setAttribute('data-bs-title','Pàgina pública');
        b.innerHTML = '<i class="bi bi-box-arrow-up-right"></i>';
        if (current) current.replaceWith(b);
        else btnGroup.insertBefore(b, btnGroup.children[1] || null);
      }
    }
  }

  function ensureExpireButton(row, btnGroup, id, uid, csrf, enabled) {
    const enabledBtn = btnGroup.querySelector('.expire-btn');
    const shieldIconBtn = Array.from(btnGroup.querySelectorAll('button, a'))
      .map(el => el.closest('button'))
      .filter(Boolean)
      .find(b => b.querySelector('.bi-shield-x') && !b.classList.contains('expire-btn'));

    if (enabled) {
      if (!enabledBtn) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-primary btn-sm expire-btn';
        btn.setAttribute('data-id', id);
        btn.setAttribute('data-uid', uid);
        btn.setAttribute('data-csrf', csrf);
        btn.setAttribute('data-bs-toggle','tooltip');
        btn.setAttribute('data-bs-title','Caducar');
        btn.innerHTML = '<i class="bi bi-shield-x"></i>';
        if (shieldIconBtn) shieldIconBtn.replaceWith(btn);
        else btnGroup.appendChild(btn);
      } else {
        enabledBtn.disabled = false;
        enabledBtn.classList.remove('btn-secondary');
        enabledBtn.classList.add('btn-primary');
      }
    } else {
      if (enabledBtn) {
        const disabled = document.createElement('button');
        disabled.type = 'button';
        disabled.className = 'btn btn-secondary btn-sm';
        disabled.disabled = true;
        disabled.setAttribute('data-bs-toggle','tooltip');
        disabled.setAttribute('data-bs-title','Caducar');
        disabled.innerHTML = '<i class="bi bi-shield-x"></i>';
        enabledBtn.replaceWith(disabled);
      }
      // si ja era deshabilitat, no fem res
    }
  }

  function refreshActionsForRow(row, estatResp) {
    const uid = row?.getAttribute('data-row-uid') || '';
    if (!uid) return;
    const canCopyView = (estatResp === 'validat' || estatResp === 'caducat');

    const btnGroup = row.querySelector('.actions-cell .btn-group');
    if (!btnGroup) return;

    const csrf = row.querySelector('.seal-select')?.getAttribute('data-csrf') || '';
    const id = (row.querySelector('td:first-child')?.textContent || '').trim();

    // BASE_URL/BAS_PATH ja venen del PHP dins l'altre bloc ↓
    const absBase  = "<?= defined('BASE_URL') ? rtrim(BASE_URL, '/') : '' ?>";
    const basePath = "<?= rtrim(BASE_PATH, '/') ?>/";

    ensureCopyLink(btnGroup, uid, canCopyView);
    ensurePublicLink(btnGroup, uid, canCopyView, basePath, absBase);
    ensureExpireButton(row, btnGroup, id, uid, csrf, estatResp === 'validat');
  }

  // Canvi d’estat del segell (AJAX) + lock anti-doble
  document.addEventListener('change', async (ev) => {
    const sel = ev.target;
    if (!sel.matches('.seal-select')) return;
    if (sel.dataset.busy === '1') return; // ja ocupat

    const uid   = sel.getAttribute('data-uid');
    const csrf  = sel.getAttribute('data-csrf');
    const estat = sel.value;

    const row = sel.closest('tr');
    const iconEl = row ? row.querySelector('.seal-icon[data-uid="'+uid+'"]') : null;

    let guard = { restore: null };
    if (window.uiBusy && typeof window.uiBusy.lock === 'function') {
      guard = window.uiBusy.lock(sel);
    } else {
      sel.dataset.busy = '1'; sel.disabled = true;
      guard.restore = () => { sel.disabled = false; delete sel.dataset.busy; };
    }

    try {
      const resp = await fetch('<?= h(BASE_PATH) ?>php/update_seal.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({ csrf, rider_uid: uid, estat })
      });

      const json = await resp.json().catch(() => ({}));
      if (!resp.ok || !json.ok) {
        showToast('Error actualitzant segell' + (json?.error ? (': ' + json.error) : ''), 'danger', 2600);
        return;
      }

      const estatResp = (json.data?.estat || 'cap').toLowerCase();

      // icona del segell
      if (iconEl) {
        applySealIcon(iconEl, estatResp);
        iconEl.setAttribute('data-estat', estatResp);
      }

      // 🔁 refresca accions d'aquesta fila segons l'estat nou
      if (row) refreshActionsForRow(row, estatResp);

      showToast('Segell actualitzat', 'success', 1600);

    } catch (e) {
      console.error(e);
      showToast('Error de xarxa', 'danger', 2600);
    } finally {
      guard.restore && guard.restore();
    }
  });

  // Copiar enllaç públic
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.copy-link-btn');
    if (!btn) return;

    const uid = btn.getAttribute('data-uid');
    if (!uid) return;

    const absBase  = "<?= defined('BASE_URL') ? rtrim(BASE_URL, '/') : '' ?>";
    const basePath = "<?= rtrim(BASE_PATH, '/') ?>/";
    const originLike = absBase || window.location.origin;
    const absolute = originLike + basePath + "visualitza.php?ref=" + encodeURIComponent(uid);

    try {
      await navigator.clipboard.writeText(absolute);

      let tip = bootstrap.Tooltip.getInstance(btn);
      if (!tip) { tip = new bootstrap.Tooltip(btn, { trigger: 'manual' }); }
      const originalTitle = btn.getAttribute('data-bs-original-title') || btn.getAttribute('title') || 'Copiar enllaç';
      btn.setAttribute('data-bs-original-title', 'Copiat!');
      tip.show();
      setTimeout(() => {
        btn.setAttribute('data-bs-original-title', originalTitle);
        tip.hide();
      }, 1200);
    } catch (e) {
      console.error(e);
      alert('No s’ha pogut copiar:\n' + absolute);
    }
  });
})();
</script>
<!-- JS Validació de rider completa -->
<script>
(function () {
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.mark-tech-ok');
    if (!btn) return;
    if (btn.dataset.busy === '1') return;

    const uid  = btn.getAttribute('data-uid');
    const csrf = btn.getAttribute('data-csrf');
    if (!uid || !csrf) return;

    const guard = uiBusy.lock(btn, ''); // spinner curt al botó
    try {
      const resp = await fetch('<?= h(BASE_PATH) ?>php/admin_mark_tech_validated.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({ csrf, rider_uid: uid })
      });
      const json = await resp.json().catch(()=>({}));
      if (!resp.ok || !json.ok) {
        alert('No s’ha pogut marcar com validat tècnicament' + (json?.error ? (': ' + json.error) : ''));
        return;
      }
      // OK → refresquem per treure el rider del box i netejar estats visuals
      window.location.reload();
    } catch (e) {
      console.error(e);
      alert('Error de xarxa');
    } finally {
      guard.restore && guard.restore();
    }
  });
})();
</script>
<!-- JS/AJAX RePujar rider usuari -->
<script>
(function () {
  // Obrir el selector quan cliquem el botó
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.admin-reupload-btn');
    if (!btn) return;
    if (btn.dataset.busy === '1') return; // evita doble clic mentre està ocupat
    const uid = btn.getAttribute('data-uid');
    const li  = btn.closest('li');
    const input = li?.querySelector('.admin-reupload-input[data-uid="'+uid+'"]');
    if (input) input.click();
  });

  // Enviar el PDF nou
  document.addEventListener('change', async (ev) => {
    const inp = ev.target;
    if (!inp.matches('.admin-reupload-input')) return;

    const file = inp.files?.[0];
    if (!file) return;
    const isPdf = (file.type === 'application/pdf') || (/\.pdf$/i.test(file.name));
    if (!isPdf) { alert('Cal un PDF.'); inp.value = ''; return; }

    const li   = inp.closest('li');
    const uid  = inp.getAttribute('data-uid');
    const btn  = li?.querySelector('.admin-reupload-btn[data-uid="'+uid+'"]');
    if (!btn || btn.dataset.busy === '1') return;

    const csrf = btn?.getAttribute('data-csrf') || '';
    const id   = btn?.getAttribute('data-id') || '';

    // lock universal (desactiva i posa spinner)
    const guard = uiBusy.lock(btn, <?= json_encode(__('loading.processing') ?: 'Processant…') ?>);

    try {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('rider_uid', uid);
      fd.append('rider_id', id);
      fd.append('rider_pdf', file);

      const resp = await fetch('<?= h(BASE_PATH) ?>php/reupload_rider.php', { method: 'POST', body: fd });
      const json = await resp.json().catch(()=>({}));
      if (!resp.ok || !json.ok) {
        alert((json?.error) || <?= json_encode(__('common.network_error') ?: 'Error de xarxa') ?>);
        return;
      }

      inp.value = '';
      alert(<?= json_encode(__('riders.reupload.done_body')  ?: 'S\'ha actualitzat el PDF correctament.') ?>);
      window.location.reload();

    } catch (e) {
      console.error(e);
      alert(<?= json_encode(__('common.network_error') ?: 'Error de xarxa') ?>);
    } finally {
      guard.restore && guard.restore();
    }
  });
})();
</script>
<!-- JS Eliminar rider -->
<script>
(function(){
  let delModal, confirmBtn, formEl;
  let spanId, spanDesc, spanEmail, inUid, inCsrf;

  function updateDeleteEnabled(){
    if (!confirmBtn) return;
    const allOk = Array.from(document.querySelectorAll('#adminDeleteModal .adm-del-req'))
      .every(el => el.checked);
    const hasVals = !!(inUid?.value && inCsrf?.value);
    confirmBtn.disabled = !(allOk && hasVals);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('adminDeleteModal');
    if (!modalEl) return;

    delModal   = new bootstrap.Modal(modalEl, { backdrop: 'static' });
    confirmBtn = document.getElementById('adm-del-confirm');
    formEl     = document.getElementById('adm-del-form');

    spanId     = document.getElementById('adm-del-id');
    spanDesc   = document.getElementById('adm-del-desc');
    spanEmail  = document.getElementById('adm-del-email');
    inUid      = document.getElementById('adm-del-uid');
    inCsrf     = document.getElementById('adm-del-csrf');

    modalEl.addEventListener('show.bs.modal', () => {
      modalEl.querySelectorAll('.adm-del-req').forEach(el => el.checked = false);
      updateDeleteEnabled();
    });
    modalEl.addEventListener('change', (ev) => {
      if (ev.target.classList.contains('adm-del-req')) updateDeleteEnabled();
    });
  });

  // Obrir modal (click icona paperera)
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.admin-delete-btn');
    if (!btn) return;

    // amaga tooltip si n’hi ha
    const tip = bootstrap.Tooltip.getInstance(btn);
    if (tip) { tip.hide(); }

    const id    = btn.getAttribute('data-id')    || '';
    const uid   = btn.getAttribute('data-uid')   || '';
    const email = btn.getAttribute('data-email') || '—';
    const desc  = btn.getAttribute('data-desc')  || '—';
    const csrf  = btn.getAttribute('data-csrf')  || '';

    if (spanId)    spanId.textContent = id;
    if (spanDesc)  spanDesc.textContent = desc;
    if (spanEmail) spanEmail.textContent = email;
    if (inUid)     inUid.value  = uid;
    if (inCsrf)    inCsrf.value = csrf;
    const inId = document.getElementById('adm-del-idin');
    if (inId) inId.value = id;

    updateDeleteEnabled();
    delModal.show();
  });

  // Submit: deixem que el <form> faci POST normal a delete_rider.php
  // (no cal JS addicional; mantenim la UX consistent amb el modal)
})();
</script>
<script>
// Helper: bloqueja/desbloqueja un element mentre hi ha una petició en marxa
window.uiBusy = {
  lock(el, label = '…') {
    if (!el) return { restore: null };
    if (el.dataset.busy === '1') return { restore: null }; // ja ocupat
    const isInput = (el.tagName === 'SELECT' || el.tagName === 'INPUT' || el.tagName === 'TEXTAREA');
    const prev = {
      html: isInput ? null : el.innerHTML,
      disabled: el.disabled
    };
    el.dataset.busy = '1';
    el.disabled = true;

    // posa spinner curt (només si no és <select>)
    if (!isInput) {
      el.innerHTML =
        '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' +
        label;
    }
    return {
      restore() {
        delete el.dataset.busy;
        el.disabled = prev.disabled;
        if (!isInput && prev.html !== null) el.innerHTML = prev.html;
      }
    };
  }
};
</script>
<script>
// Inicialització global de tooltips Bootstrap
document.addEventListener('DOMContentLoaded', () => {
  const selector = '[data-bs-toggle="tooltip"]';
  new bootstrap.Tooltip(document.body, { selector });
});
</script>