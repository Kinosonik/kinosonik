<?php
// rider_vistos.php — Llistat de riders que he vist
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/check_login.php';
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/messages.php';
require_once __DIR__ . '/php/middleware.php';

$pdo = db();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Paràmetres de consulta ───────────────────────────────────────────────
$isAdmin = strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0;
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);

// Admin pot consultar altres usuaris amb ?user=ID
$targetUserId = $sessionUserId;
if ($isAdmin && isset($_GET['user']) && ctype_digit((string)$_GET['user'])) {
  $targetUserId = (int)$_GET['user'];
}

// Filtre d’estat
$allowedStatus = ['tots','validat','caducat'];
$status = isset($_GET['status']) ? strtolower((string)$_GET['status']) : 'tots';
if (!in_array($status, $allowedStatus, true)) { $status = 'tots'; }

// Paginació
$perPage = 50;
$page = (isset($_GET['page']) && ctype_digit((string)$_GET['page']) && (int)$_GET['page'] >= 1) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

// ── SQL base (excloem riders propis del target) ──────────────────────────
// ── WHERE base (sense filtre d’estat) ────────────────────────────────────
$whereBase  = "urr.User_ID = :uid AND r.ID_Usuari <> :uid_excl";
$paramsBase = [
  ':uid'      => $targetUserId,
  ':uid_excl' => $targetUserId,
];

// ── WHERE llistat (amb filtre d’estat si cal) ───────────────────────────
$whereList  = $whereBase;
$paramsList = $paramsBase;

if ($status === 'validat') {
  $whereList .= " AND LOWER(r.Estat_Segell) = 'validat'";
} elseif ($status === 'caducat') {
  $whereList .= " AND LOWER(r.Estat_Segell) = 'caducat'";
}

// ── Comptadors globals (independents del filtre actual) ─────────────────
$cntSql = "
  SELECT
    SUM(CASE WHEN LOWER(r.Estat_Segell) = 'validat' THEN 1 ELSE 0 END) AS validated,
    SUM(CASE WHEN LOWER(r.Estat_Segell) = 'caducat'  THEN 1 ELSE 0 END) AS expired,
    COUNT(*) AS total
  FROM User_Recent_Riders urr
  JOIN Riders r ON r.ID_Rider = urr.ID_Rider
  WHERE $whereBase
";
$stCnt = $pdo->prepare($cntSql);
$stCnt->execute($paramsBase);
$counters = $stCnt->fetch(PDO::FETCH_ASSOC) ?: ['validated'=>0,'expired'=>0,'total'=>0];

// ── Total per paginar (sí respecta el filtre) ───────────────────────────
$totSql = "
  SELECT COUNT(*) FROM User_Recent_Riders urr
  JOIN Riders r ON r.ID_Rider = urr.ID_Rider
  WHERE $whereList
";
$stTot = $pdo->prepare($totSql);
$stTot->execute($paramsList);
$totalRows = (int)$stTot->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ── Llistat paginat (sí respecta el filtre) ─────────────────────────────
$listSql = "
  SELECT
    r.ID_Rider, r.Rider_UID, r.Nom_Arxiu, r.Descripcio, r.Estat_Segell, r.Data_Publicacio,
    u.Nom_Usuari AS Owner_Nom, u.Cognoms_Usuari AS Owner_Cognoms,
    urr.view_count, urr.last_view_at
  FROM User_Recent_Riders urr
  JOIN Riders r ON r.ID_Rider = urr.ID_Rider
  JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
  WHERE $whereList
  ORDER BY urr.last_view_at DESC
  LIMIT :lim OFFSET :off
";
$st = $pdo->prepare($listSql);
foreach ($paramsList as $k => $v) { $st->bindValue($k, $v, PDO::PARAM_INT); }
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Marca la secció activa pel navbar
$seccio = 'rider_vistos';

// ── Capçalera / Nav ──────────────────────────────────────────────────────
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>

<div class="container my-3">
  <!-- Títol -->
  <div class="d-flex justify-content-between align-items-center mb-1">
    <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
      <i class="bi bi-rocket-takeoff"></i>&nbsp;&nbsp;
      <?= h(t('nav.recent_riders') ?? 'Riders que he vist') ?>
      <?php if ($isAdmin && $targetUserId !== $sessionUserId): ?>
        <small class="text-body-terciary">· user #<?= (int)$targetUserId ?></small>
      <?php endif; ?>
    </h4>
    <div>
      <?php if ($isAdmin): ?>
        <form class="d-flex gap-2" method="get" action="<?= h(BASE_PATH) ?>rider_vistos.php">
          <input type="hidden" name="status" value="<?= h($status) ?>">
          <input type="number" min="1" class="form-control form-control-sm" name="user" value="<?= (int)$targetUserId ?>" style="width:120px" placeholder="User ID">
          <button class="btn btn-outline-secondary btn-sm">Canvia</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filtres d’estat amb contadors -->
  <?php
    $mkHref = function(string $st) use ($targetUserId, $status) {
      $qs = ['status' => $st];
      if ($targetUserId) $qs['user'] = (string)$targetUserId;
      return BASE_PATH . 'rider_vistos.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
    };
  ?>
  <div class="btn-group mb-4" role="group" aria-label="<?= h(t('nav.recent_riders') ?? 'Riders que he vist') ?>">
    <button 
      type="button" 
      class="botons_riders btn btn-primary btn-sm <?= $status==='tots'?'active':'' ?>"    
      onclick="window.location.href='<?= h($mkHref('tots')) ?>';">
      <?= h(t('filter.all') ?? 'Tots') ?> 
      <span class="badge text-bg-secondary riders_vistos"><?= (int)($counters['total'] ?? 0) ?></span>
    </button>
    <button 
      type="button" 
      class="botons_riders btn btn-primary btn-sm <?= $status==='validat'?'active':'' ?>" 
      onclick="window.location.href='<?= h($mkHref('validat')) ?>';">
      <i class="bi bi-shield-check"></i>
      <?= h(t('riders.validated') ?? 'Validats') ?> 
      <span class="badge text-bg-secondary riders_vistos"><?= (int)($counters['validated'] ?? 0) ?></span>
    </button>
    <button 
      type="button" 
      class="botons_riders btn btn-primary btn-sm <?= $status==='caducat'?'active':'' ?>" 
      onclick="window.location.href='<?= h($mkHref('caducat')) ?>';">
      <i class="bi bi-shield-x"></i>
      <?= h(t('riders.expired') ?? 'Caducats') ?> 
      <span class="badge text-bg-primary riders_vistos"><?= (int)($counters['expired'] ?? 0) ?></span>
    </button>
  </div>
  <!-- Taula -->
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th class="text-center" style="width: 56px;">ID</th>
          <th>Rider</th>
          <th class="text-center"><i class="bi bi-shield-shaded" title="<?= h(t('rider.state') ?? 'Estat') ?>"></i></th>
          <th><?= h(t('rider.owner') ?? 'Propietari') ?></th>
          <th class="text-center" class="text-end"><?= h(t('views') ?? 'Vistes') ?></th>
          <th class="text-center"><?= h(t('last_view') ?? 'Darrera vista') ?></th>
          <th class="text-end"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-body-secondary py-2">— <?= h(t('no_results') ?? 'Sense resultats') ?> —</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $badge = 'secondary';
            $state = strtolower((string)($r['Estat_Segell'] ?? ''));
            if ($state === 'validat') $badge = 'bi-shield-fill-check text-success';
            elseif ($state === 'caducat') $badge = 'bi-shield-fill-x text-danger';
            $owner = trim(($r['Owner_Nom'] ?? '').' '.($r['Owner_Cognoms'] ?? ''));
            $owner = $owner !== '' ? $owner : '—';
            $pubDate = '—';
            if (!empty($r['Data_Publicacio'])) {
              try { $pubDate = (new DateTime((string)$r['Data_Publicacio']))->format('d/m/Y H:i'); } catch (Throwable $e) {}
            }
            $lastView = '—';
            if (!empty($r['last_view_at'])) {
              try { $lastView = (new DateTime((string)$r['last_view_at']))->format('d/m/Y H:i'); } catch (Throwable $e) {}
            }
            $pdfUrl = BASE_PATH . 'php/rider_file.php?ref=' . rawurlencode((string)$r['Rider_UID']);
            $viewUrl = BASE_PATH . 'visualitza.php?ref=' . rawurlencode((string)$r['Rider_UID']);
          ?>
          <tr>
            <td class="text-center"><?= (int)$r['ID_Rider'] ?></td>
            <td class="text-truncate" style="max-width: 380px;">
              <a class="link-light text-decoration-none" 
                data-bs-toggle="tooltip"
                data-bs-placement="right"
                data-bs-title="<?= h((string)($r['Nom_Arxiu'])) ?>"
                href="<?= h($pdfUrl) ?>" 
                target="_blank" rel="noopener">
                <?= h((string)($r['Descripcio'] ?: ('rider-'.$r['Rider_UID'].'.pdf'))) ?>
              </a>
            </td>
            <td class="text-center"><i class="bi <?= h($badge ?: '—') ?>"></i></td>
            <td class="text-truncate" style="max-width: 240px;"><?= h($owner) ?></td>
            <td class="text-center"><?= (int)($r['view_count'] ?? 0) ?></td>
            <td class="text-center"><?= h($lastView) ?></td>
            <!-- Accions -->
            <td class="text-end">
              <a href="<?= h($viewUrl) ?>" target="_blank" rel="noopener"
                class="btn btn-primary btn-sm"
                title="">
                <i class="bi bi-box-arrow-up-right"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginació -->
  <?php if ($totalPages > 1): ?>
    <?php
      $mkPageHref = function(int $p) use ($status, $targetUserId) {
        $qs = ['status'=>$status, 'page'=>$p];
        if ($targetUserId) $qs['user'] = (string)$targetUserId;
        return BASE_PATH . 'rider_vistos.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
      };
    ?>
    <nav aria-label="Paginació">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= h($mkPageHref(max(1,$page-1))) ?>" tabindex="-1" aria-disabled="<?= $page<=1?'true':'false' ?>">«</a>
        </li>
        <li class="page-item disabled"><span class="page-link"><?= (int)$page ?> / <?= (int)$totalPages ?></span></li>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
          <a class="page-link" href="<?= h($mkPageHref(min($totalPages,$page+1))) ?>">»</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/parts/footer.php'; ?>