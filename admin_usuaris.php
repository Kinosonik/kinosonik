<?php
// admin_usuaris.php — Llista d'usuaris (només ADMIN) amb edició AJAX del tipus, modal d'eliminació,
// i columnes Riders / Espai / Segells validats + ordenació.
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';
require_once __DIR__ . '/php/i18n.php';

$pdo = db();

/* ── Helper d’ús d’espai UNIFICAT (Riders + Documents, amb dedupe per SHA) ── */
if (!function_exists('ks_user_storage_totals')) {
  function ks_user_storage_totals(PDO $pdo, int $uid): array {
    $shaToBytes = [];

    // Riders de l’usuari (tecnic)
    try {
      $st = $pdo->prepare("
        SELECT TRIM(COALESCE(Hash_SHA256,'')) AS sha, MAX(COALESCE(Mida_Bytes,0)) AS sz
        FROM Riders
        WHERE ID_Usuari = :uid AND COALESCE(Hash_SHA256,'') <> ''
        GROUP BY Hash_SHA256
      ");
      $st->execute([':uid' => $uid]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sha = (string)$r['sha']; $sz = (int)$r['sz'];
        if ($sha !== '') $shaToBytes[$sha] = max($shaToBytes[$sha] ?? 0, $sz);
      }
    } catch (Throwable $e) {
      // Fallback sense SHA a Riders
      $st = $pdo->prepare("SELECT COALESCE(SUM(Mida_Bytes),0) FROM Riders WHERE ID_Usuari = :uid");
      $st->execute([':uid' => $uid]);
      $shaToBytes['__RIDERS_FALLBACK__'] = (int)$st->fetchColumn();
    }

    // Documents del productor (qualsevol etiqueta), provem dues columnes de hash
    $docsHandled = false;
    foreach (['owner_user_id','created_by'] as $whoCol) {
      foreach (['SHA256','Hash_SHA256'] as $col) {
        try {
          $q = "
            SELECT TRIM(COALESCE($col,'')) AS sha, MAX(COALESCE(Mida_Bytes,0)) AS sz
            FROM Documents
            WHERE $whoCol = :uid AND COALESCE($col,'') <> ''
            GROUP BY $col
          ";
          $st = $pdo->prepare($q);
          $st->execute([':uid' => $uid]);
          foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $sha = (string)$d['sha']; $sz = (int)$d['sz'];
            if ($sha !== '') $shaToBytes[$sha] = max($shaToBytes[$sha] ?? 0, $sz);
          }
          $docsHandled = true;
          break 2;
        } catch (Throwable $e) { /* prova següent */ }
      }
    }
    if (!$docsHandled) {
      try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(Mida_Bytes),0) FROM Documents WHERE created_by = :uid");
        $st->execute([':uid' => $uid]);
        $shaToBytes['__DOCS_FALLBACK__'] = ($shaToBytes['__DOCS_FALLBACK__'] ?? 0) + (int)$st->fetchColumn();
      } catch (Throwable $e) { /* cap Documents → 0 */ }
    }


    $used = 0;
    foreach ($shaToBytes as $sz) $used += (int)$sz;

    return [
      'used_bytes' => (int)$used,
      'distinct_blobs' => count($shaToBytes),
    ];
  }
}

// Helper d'escapat
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// human bytes
function humanBytes(int $b): string {
  $u = ['B','KB','MB','GB','TB'];
  $i = 0; $v = (float)$b;
  while ($v >= 1024 && $i < count($u)-1) { $v /= 1024; $i++; }
  return number_format($v, ($i===0?0:1)) . ' ' . $u[$i];
}

/* --- Seguretat: només admins --- */
$currentUserId = $_SESSION['user_id'] ?? null;
$ret = $_SERVER['REQUEST_URI'] ?? (BASE_PATH . 'espai.php?seccio=usuaris');
if (!$currentUserId) {
  ks_redirect(BASE_PATH . 'index.php?modal=login&return=' . rawurlencode($ret), 302);
  exit;
}
$st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
$st->execute([$currentUserId]);
$isAdmin = ($row = $st->fetch(PDO::FETCH_ASSOC)) && strcasecmp((string)$row['Tipus_Usuari'], 'admin') === 0;
if (!$isAdmin) {
  ks_redirect(BASE_PATH . 'index.php?modal=login&return=' . rawurlencode($ret), 302);
  exit;
}

/* --- CSRF per formularis/ AJAX --- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf'];

/* --- Paràmetres de filtre / cerca / paginació --- */
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = max(5, min(100, (int)($_GET['per'] ?? 20)));
$offset    = ($page - 1) * $perPage;

$filterId     = trim((string)($_GET['id'] ?? ''));
$filterEmail  = trim((string)($_GET['email'] ?? ''));
$filterVerif  = (string)($_GET['verified'] ?? 'all'); // 'all' | '1' | '0'
$filterTipus  = (string)($_GET['tipus'] ?? 'tots');   // 'tots' | 'tecnic' | 'productor' | 'sala' | 'admin'

/* --- Ordenació --- */
$sort = (string)($_GET['sort'] ?? 'id');   // camp lògic
$dir  = strtolower((string)($_GET['dir'] ?? 'desc')); // 'asc' | 'desc'
$dir  = in_array($dir, ['asc','desc'], true) ? $dir : 'desc';

$sortMap = [
  'id'       => 'u.ID_Usuari',
  'nom'      => 'u.Nom_Usuari',
  'cognoms'  => 'u.Cognoms_Usuari',
  'email'    => 'u.Email_Usuari',
  'tipus'    => 'u.Tipus_Usuari',
  'verified' => 'u.Email_Verificat',
  'alta'     => 'u.Data_Alta_Usuari',
  'riders'   => 'Num_Riders',
  'originals'=> 'Num_Originals',
  'space'    => 'Used_Bytes',
  'seals'    => 'Valid_Seals',
];
$orderBy = $sortMap[$sort] ?? $sortMap['id'];
$orderSql = $orderBy . ' ' . strtoupper($dir);
// Tiebreaker estable: en cas d'empat, ordena també per ID_Usuari
// (evita salts visibles entre pàgines quan hi ha mateix valor al camp principal)
if (stripos($orderBy, 'u.ID_Usuari') === false) {
  $orderSql .= ', u.ID_Usuari ' . strtoupper($dir);
}

/* --- Filtres WHERE --- */
$where  = [];
$params = [];

if ($filterId !== '' && ctype_digit($filterId)) {
  $where[] = "u.ID_Usuari = :id";
  $params[':id'] = (int)$filterId;
}
if ($filterEmail !== '') {
  $where[] = "u.Email_Usuari LIKE :email";
  $params[':email'] = '%' . $filterEmail . '%';
}
if ($filterVerif === '1') {
  $where[] = "u.Email_Verificat = 1";
} elseif ($filterVerif === '0') {
  $where[] = "u.Email_Verificat = 0";
}
$validTipus = ['tecnic','productor','sala','admin'];
if (in_array($filterTipus, $validTipus, true)) {
  $where[] = "u.Tipus_Usuari = :tipus";
  $params[':tipus'] = $filterTipus;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* --- Query amb estadístiques de Riders --- */
$countSql = "
  SELECT COUNT(*) FROM Usuaris u
  $whereSql
";
$stc = $pdo->prepare($countSql);
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$dataSql = "
  SELECT
  u.ID_Usuari,
  u.Nom_Usuari,
  u.Cognoms_Usuari,
  u.Email_Usuari,
  u.Tipus_Usuari,
  u.Email_Verificat,
  u.Data_Alta_Usuari,

  COALESCE(rstats.num_riders, 0)      AS Num_Riders,
  COALESCE(dstats.num_originals, 0)   AS Num_Originals,
  COALESCE(rstats.valid_seals, 0)     AS Valid_Seals,
  (COALESCE(rstats.bytes, 0) + COALESCE(dstats.doc_bytes, 0)) AS Used_Bytes

  FROM Usuaris u
  LEFT JOIN (
    SELECT
      ID_Usuari,
      COUNT(*) AS num_riders,
      SUM(Mida_Bytes) AS bytes,
      SUM(CASE WHEN Estat_Segell = 'validat' THEN 1 ELSE 0 END) AS valid_seals
    FROM Riders
    GROUP BY ID_Usuari
  ) AS rstats
    ON rstats.ID_Usuari = u.ID_Usuari
  LEFT JOIN (
    SELECT
      owner_user_id,
      SUM(CASE WHEN kind = 'band_original' THEN 1 ELSE 0 END) AS num_originals,
      SUM(bytes) AS doc_bytes
    FROM Documents
    GROUP BY owner_user_id
  ) AS dstats
    ON dstats.owner_user_id = u.ID_Usuari
  $whereSql
  ORDER BY $orderSql
  LIMIT :limit OFFSET :ofs
  ";

$sth = $pdo->prepare($dataSql);
foreach ($params as $k => $v) { $sth->bindValue($k, $v); }
$sth->bindValue(':limit', $perPage, PDO::PARAM_INT);
$sth->bindValue(':ofs',   $offset,  PDO::PARAM_INT);
$sth->execute();
$usuaris = $sth->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $perPage));

/* ── Post-process: espai deduplicat + originals vigents per usuari (pàgina actual) ── */
$stOrig = null;
try {
  $stOrig = $pdo->prepare("
    SELECT COUNT(DISTINCT a.rider_orig_doc_id)
    FROM Stage_Day_Acts a
    JOIN Documents d ON d.id = a.rider_orig_doc_id
    WHERE d.owner_user_id = :uid
  ");
} catch (Throwable $e) {
  $stOrig = $pdo->prepare("
    SELECT COUNT(DISTINCT a.rider_orig_doc_id)
    FROM Stage_Day_Acts a
    JOIN Documents d ON d.id = a.rider_orig_doc_id
    WHERE d.created_by = :uid
  ");
}

foreach ($usuaris as &$u) {
  $uid = (int)$u['ID_Usuari'];

  // Espai deduplicat (Riders + Documents per SHA)
  $tot = ks_user_storage_totals($pdo, $uid);
  $u['Used_Bytes'] = (int)$tot['used_bytes'];

  // “Originals” vigents (només els enllaçats ara mateix)
  $stOrig->execute([':uid' => $uid]);
  $u['Num_Originals'] = (int)$stOrig->fetchColumn();
}
unset($u);


/* helpers d'URL ordenació */
$baseQS = [
  'seccio'  => 'usuaris',
  'id'      => $filterId,
  'email'   => $filterEmail,
  'verified'=> $filterVerif,
  'tipus'   => $filterTipus,
  'per'     => $perPage,
];

$sortUrl = function (string $key) use ($baseQS, $sort, $dir) {
  $qs = $baseQS;
  $qs['sort'] = $key;
  $qs['dir']  = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
  return BASE_PATH . 'espai.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
};

$sortIcon = function (string $key) use ($sort, $dir) {
  if ($sort !== $key) return '<i class="bi bi-arrow-down-up ms-1 text-secondary"></i>';
  return $dir === 'asc'
    ? '<i class="bi bi-arrow-up-short ms-1"></i>'
    : '<i class="bi bi-arrow-down-short ms-1"></i>';
};
?>
<div class="container-fluid my-0">
  <div class="card shadow-sm border-0">
    <div class="card-header bg-dark">
      <h5 class="card-title mb-0">Llistat d’usuaris</h5>
    </div>
    <!-- Banner missatges -->
    <?php
    if (!empty($_SESSION['flash'])):
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type = (string)($f['type'] ?? 'info'); // success | error | info | warning
    $text = trim((string)($f['text'] ?? ''));
    if ($text === '' && isset($f['key'])) { $text = (string)$f['key']; } // fallback si algun script encara envia 'key'
    $alertClass = match ($type) {
      'success' => 'alert-success',
      'error'   => 'alert-danger',
      'warning' => 'alert-warning',
      default   => 'alert-info',
    };
    ?>
    <div class="container my-2">
      <div class="alert <?= h($alertClass) ?> alert-dismissible fade show shadow-sm auto-dismiss-alert" role="alert">
        <?= h($text) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tanca"></button>
      </div>
    </div>
    <script>
    // Tanca sol el banner al cap de 3s
      setTimeout(() => {
        const el = document.querySelector('.auto-dismiss-alert');
        if (el) bootstrap.Alert.getOrCreateInstance(el).close();
      }, 3000);
    </script>
    <?php endif; ?>
    
    <div class="card-body bd-0">
      <!-- Filtres / Cerca -->
<form class="row row-cols-auto g-2 align-items-end mb-3" method="get" action="<?= h(BASE_PATH) ?>espai.php">
  <input type="hidden" name="seccio" value="usuaris">

  <div class="col">
    <label for="f_id" class="form-label small mb-0">ID</label>
    <input type="text" pattern="\d*" inputmode="numeric"
           class="form-control form-control-sm w-auto"
           style="max-width:80px"
           id="f_id" name="id" value="<?= h($filterId) ?>">
  </div>

  <div class="col">
    <label for="f_email" class="form-label small mb-0">Email</label>
    <input type="text" class="form-control form-control-sm"
           id="f_email" name="email" value="<?= h($filterEmail) ?>"
           placeholder="exemple@correu.com">
  </div>

  <div class="col">
    <label for="f_verified" class="form-label small mb-0">Verificació</label>
    <select class="form-select form-select-sm" id="f_verified" name="verified">
      <option value="all" <?= $filterVerif==='all'?'selected':''; ?>>Tots</option>
      <option value="1"   <?= $filterVerif==='1'  ?'selected':''; ?>>Verificats</option>
      <option value="0"   <?= $filterVerif==='0'  ?'selected':''; ?>>No verificats</option>
    </select>
  </div>

  <div class="col">
    <label for="f_tipus" class="form-label small mb-0">Tipus</label>
    <select class="form-select form-select-sm" id="f_tipus" name="tipus">
      <option value="tots"   <?= $filterTipus==='tots'  ?'selected':''; ?>>Tots</option>
      <option value="tecnic" <?= $filterTipus==='tecnic'?'selected':''; ?>>Tècnic</option>
      <option value="productor"  <?= $filterTipus==='productor' ?'selected':''; ?>>Productor</option>
      <option value="sala"   <?= $filterTipus==='sala'  ?'selected':''; ?>>Sala</option>
      <option value="admin"  <?= $filterTipus==='admin' ?'selected':''; ?>>Admin</option>
    </select>
  </div>

  <div class="col">
    <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(BASE_PATH) ?>espai.php?seccio=usuaris">Neteja</a>
  </div>
</form>

      <!-- Resum + per pàgina -->
      <div class="d-flex justify-content-between align-items-center small text-secondary mb-2">
        <div>
          Resultats: <span class="text-body"><?= h((string)$total) ?></span>
          · Pàgina <span class="text-body"><?= h((string)$page) ?></span> de <span class="text-body"><?= h((string)$totalPages) ?></span>
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
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('nom')) ?>">Nom <?= $sortIcon('nom') ?></a>
              </th>
              <th class="fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('cognoms')) ?>">Cognoms <?= $sortIcon('cognoms') ?></a>
              </th>
              <th class="fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('email')) ?>">Correu electrònic <?= $sortIcon('email') ?></a>
              </th>
              <th class="text-center fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('tipus')) ?>">Tipus <?= $sortIcon('tipus') ?></a>
              </th>
              <th class="text-center fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('verified')) ?>">Verificat <?= $sortIcon('verified') ?></a>
              </th>
              <th class="text-center fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('alta')) ?>">Alta <?= $sortIcon('alta') ?></a>
              </th>
              <th class="text-center fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('riders')) ?>">Riders <?= $sortIcon('riders') ?></a>
              </th>
              <th class="text-center fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('originals')) ?>">
                  Originals <?= $sortIcon('originals') ?>
                </a>
              </th>

              <th class="text-center fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('seals')) ?>">Segells validats <?= $sortIcon('seals') ?></a>
              </th>
              <th class="text-center fw-lighter">
                <a class="link-light text-decoration-none" href="<?= h($sortUrl('space')) ?>">Espai (R2) <?= $sortIcon('space') ?></a>
              </th>
              <th class="text-center fw-lighter"></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$usuaris): ?>
            <tr><td colspan="11" class="text-center text-secondary py-4">Cap resultat.</td></tr>
          <?php else: foreach ($usuaris as $u):
            $uid   = (int)$u['ID_Usuari'];
            $tipus = (string)($u['Tipus_Usuari'] ?? '');
            $verified = (int)($u['Email_Verificat'] ?? 0) === 1;
            $riders = (int)($u['Num_Riders'] ?? 0);
            $originals  = (int)($u['Num_Originals'] ?? 0);
            $bytesTotal = (int)($u['Used_Bytes'] ?? 0);
            $seals  = (int)($u['Valid_Seals'] ?? 0);
          ?>
            <tr data-row-user="<?= h((string)$uid) ?>">
              <td class="text-center text-secondary"><?= h((string)$uid) ?></td>
              <td><?= h($u['Nom_Usuari'] ?? '') ?></td>
              <td><?= h($u['Cognoms_Usuari'] ?? '') ?></td>
              <td><?= h($u['Email_Usuari'] ?? '') ?></td>

              <!-- Tipus: editable per admins via AJAX -->
              <td class="text-center">
                <select class="form-select form-select-sm d-inline-block w-auto tipus-select"
                        data-uid="<?= h((string)$uid) ?>"
                        data-csrf="<?= h($CSRF) ?>">
                  <?php foreach (['tecnic'=>'Tècnic','productor'=>'Productor','sala'=>'Sala','admin'=>'Admin'] as $val=>$lbl): ?>
                    <option value="<?= h($val) ?>" <?= $tipus===$val?'selected':''; ?>><?= h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="ms-1 d-none text-muted small spinner"
                      aria-label="saving"><span class="spinner-border spinner-border-sm"></span></span>
              </td>

              <td class="text-center">
                <?php if ($verified): ?>
                  <i class="bi bi-hand-thumbs-up" style="color: green;" aria-label="Verificat"></i>
                <?php else: ?>
                  <i class="bi bi-hand-thumbs-down" style="color: red;" aria-label="No verificat"></i>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?= $u['Data_Alta_Usuari'] ? dt_eu($u['Data_Alta_Usuari']) : '—' ?>
              </td>

              <td class="text-center"><?= h((string)$riders) ?></td>
              <td class="text-center"><?= h((string)$originals) ?></td>
              <td class="text-center"><?= h((string)$seals) ?></td>
              <td class="text-center"><?= h(humanBytes($bytesTotal)) ?></td>

              <td class="text-end actions-cell">
  <div class="d-inline-flex align-items-center gap-1 flex-nowrap">
    <!-- Veure/Editar (obre dades.php com admin) + Riders de l'usuari -->
    <div class="btn-group btn-group-sm flex-nowrap">
      <a href="<?= h(BASE_PATH) ?>espai.php?seccio=dades&user=<?= $uid ?>"
         class="btn btn-primary btn-sm"
         data-bs-toggle="tooltip" data-bs-title="Veure / Editar">
        <i class="bi bi-person-gear"></i>
      </a>
      <a href="<?= h(BASE_PATH) ?>espai.php?seccio=riders&uid=<?= (int)$uid ?>"
         class="btn btn-primary btn-sm"
         data-bs-toggle="tooltip" data-bs-title="Riders de l'usuari">
        <i class="bi bi-music-note-list"></i>
      </a>
    </div>

    <!-- Eliminar (obrir modal) -->
    <button type="button"
            class="btn btn-danger btn-sm btn-open-delete"
            data-uid="<?= h((string)$uid) ?>"
            data-email="<?= h($u['Email_Usuari'] ?? '') ?>"
            data-name="<?= h(trim(($u['Nom_Usuari'] ?? '').' '.($u['Cognoms_Usuari'] ?? ''))) ?>"
            data-bs-toggle="modal" data-bs-target="#deleteUserModal"
            data-bs-title="Eliminar">
      <i class="bi bi-trash3"></i>
    </button>
  </div>
</td>
            </tr>
          <?php endforeach; endif; ?>
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
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Modal eliminar usuari -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" action="<?= h(BASE_PATH) ?>php/admin_delete_user.php" class="modal-content liquid-glass-kinosonik">
      <?= csrf_field() ?>
      <div class="modal-header bg-danger text-white py-2">
        <h6 class="modal-title" id="deleteUserModalLabel">Eliminar usuari</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="user_id" id="del_user_id" value="">
        <p class="mb-1">Segur que vols eliminar aquest usuari?</p>
        <p class="small text-secondary mb-0">Aquesta acció és <strong>irreversible</strong>. També s’eliminaran els seus riders i fitxers del núvol.</p>
        <hr class="my-2">
        <div class="small">
          <div><span class="text-secondary">ID:</span> <span id="del_user_id_view" class="text-body fw-semibold">—</span></div>
          <div><span class="text-secondary">Nom:</span> <span id="del_user_name" class="text-body">—</span></div>
          <div><span class="text-secondary">Email:</span> <span id="del_user_email" class="text-body">—</span></div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel·la</button>
        <button type="submit" class="btn btn-danger btn-sm">
          <i class="bi bi-trash3 me-1"></i> Elimina
        </button>
      </div>
    </form>
  </div>
</div>

<!-- JS: AJAX canvi de tipus + inicialització modal -->
<script>
(function(){
  // Obrir modal d'eliminació amb dades
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.btn-open-delete');
    if (!btn) return;
    const uid   = btn.getAttribute('data-uid') || '';
    const email = btn.getAttribute('data-email') || '';
    const name  = btn.getAttribute('data-name') || '';

    document.getElementById('del_user_id').value = uid;
    document.getElementById('del_user_id_view').textContent = uid;
    document.getElementById('del_user_email').textContent = email;
    document.getElementById('del_user_name').textContent  = name;
  });

  // AJAX per canviar Tipus_Usuari
  document.addEventListener('change', async (ev) => {
    const sel = ev.target;
    if (!sel.matches('.tipus-select')) return;

    const uid  = sel.getAttribute('data-uid');
    const csrf = sel.getAttribute('data-csrf');
    const val  = sel.value;

    const row = sel.closest('tr');
    const spin = row ? row.querySelector('.spinner') : null;
    if (spin) spin.classList.remove('d-none');
    sel.disabled = true;

    try {
      const resp = await fetch('<?= h(BASE_PATH) ?>php/admin_update_user_type.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({ csrf, user_id: uid, tipus: val })
      });
      const json = await resp.json().catch(() => ({}));
      if (!resp.ok || !json || json.ok !== true) {
        alert('No s’ha pogut actualitzar el tipus d’usuari' + (json?.error ? (': ' + json.error) : ''));
      }
    } catch (e) {
      console.error(e);
      alert('Error de xarxa en actualitzar el tipus d’usuari');
    } finally {
      if (spin) spin.classList.add('d-none');
      sel.disabled = false;
    }
  });
})();
</script>