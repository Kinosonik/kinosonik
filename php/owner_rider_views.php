<?php
// php/owner_rider_views.php — informe de visites d’un rider per al propietari/admin
declare(strict_types=1);

require_once __DIR__ . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/middleware.php';

$pdo = db();

// --- Inputs ---
$rid = isset($_GET['rid']) && ctype_digit((string)$_GET['rid']) ? (int)$_GET['rid'] : 0;
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$per  = 25;
$off  = ($page - 1) * $per;

$tab  = (string)($_GET['tab'] ?? 'users'); // 'users' | 'anon'

// --- Guard ---
if ($rid <= 0 || empty($_SESSION['loggedin'])) {
  header('Location: ' . BASE_PATH . 'espai.php?seccio=riders&error=login_required', true, 302);
  exit;
}

$me     = (int)($_SESSION['user_id'] ?? 0);
$isAdmin= (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0);

// Rider + propietari
$st = $pdo->prepare("SELECT ID_Rider, ID_Usuari, Nom_Arxiu, Estat_Segell FROM Riders WHERE ID_Rider = ? LIMIT 1");
$st->execute([$rid]);
$rider = $st->fetch(PDO::FETCH_ASSOC);
if (!$rider) {
  header('Location: ' . BASE_PATH . 'espai.php?seccio=riders&error=not_found', true, 302);
  exit;
}
$ownerId = (int)$rider['ID_Usuari'];
if (!$isAdmin && $ownerId !== $me) {
  http_response_code(403);
  echo '<div class="container my-4"><div class="alert alert-danger">No tens permís per veure aquest informe.</div></div>';
  exit;
}

// --- Resum counters ---
$cnt = [
  'total_views' => 0,
  'unique_logged_users' => 0,
  'anon_views' => 0,
  'last_view_at' => null,
];
$stc = $pdo->prepare("SELECT total_views, unique_logged_users, anon_views, last_view_at FROM Rider_View_Counters WHERE ID_Rider=?");
$stc->execute([$rid]);
$row = $stc->fetch(PDO::FETCH_ASSOC);
if ($row) { $cnt = $row; }

// --- Total files (per paginar) per pestanya ---
if ($tab === 'users') {
  $totSql = "SELECT COUNT(*)
               FROM (
                 SELECT rv.Viewer_User_ID
                   FROM Rider_Views rv
                   JOIN Usuaris u ON u.ID_Usuari = rv.Viewer_User_ID
                  WHERE rv.ID_Rider = :rid
                    AND rv.Viewer_User_ID IS NOT NULL
                    AND rv.Viewer_User_ID <> :owner
                    AND LOWER(u.Tipus_Usuari) <> 'admin'
                  GROUP BY rv.Viewer_User_ID
               ) t";
  $stTot = $pdo->prepare($totSql);
  $stTot->execute([':rid' => $rid, ':owner' => $ownerId]);
  $total = (int)$stTot->fetchColumn();
} else {
  // grupem per (session_hash || ua_hash) normalitzant a base64 per tractar BINs
  $totSql = "SELECT COUNT(*)
               FROM (
                 SELECT COALESCE(TO_BASE64(rv.session_hash), CONCAT('ua:',TO_BASE64(rv.ua_hash))) AS anon_key
                   FROM Rider_Views rv
                  WHERE rv.ID_Rider = :rid AND rv.Viewer_User_ID IS NULL
                  GROUP BY anon_key
               ) t";
  $stTot = $pdo->prepare($totSql);
  $stTot->execute([':rid' => $rid]);
  $total = (int)$stTot->fetchColumn();
}

$pages = max(1, (int)ceil($total / $per));

// --- Llistats ---
if ($tab === 'users') {
  $sql = "
    SELECT 
      u.ID_Usuari,
      u.Nom_Usuari,
      u.Cognoms_Usuari,
      u.Email_Usuari,
      COUNT(*) AS views,
      MAX(rv.viewed_at) AS last_view
    FROM Rider_Views rv
    JOIN Usuaris u ON u.ID_Usuari = rv.Viewer_User_ID
   WHERE rv.ID_Rider = :rid
     AND rv.Viewer_User_ID IS NOT NULL
     AND rv.Viewer_User_ID <> :owner
     AND LOWER(u.Tipus_Usuari) <> 'admin'
   GROUP BY u.ID_Usuari, u.Nom_Usuari, u.Cognoms_Usuari, u.Email_Usuari
   ORDER BY last_view DESC
   LIMIT :lim OFFSET :off
  ";
  $stList = $pdo->prepare($sql);
  $stList->bindValue(':rid', $rid, PDO::PARAM_INT);
  $stList->bindValue(':owner', $ownerId, PDO::PARAM_INT);
  $stList->bindValue(':lim', $per, PDO::PARAM_INT);
  $stList->bindValue(':off', $off, PDO::PARAM_INT);
  $stList->execute();
  $rows = $stList->fetchAll(PDO::FETCH_ASSOC);
} else {
  $sql = "
    SELECT 
      COALESCE(TO_BASE64(rv.session_hash), CONCAT('ua:',TO_BASE64(rv.ua_hash))) AS anon_key,
      COUNT(*) AS views,
      MAX(rv.viewed_at) AS last_view
    FROM Rider_Views rv
   WHERE rv.ID_Rider = :rid
     AND rv.Viewer_User_ID IS NULL
   GROUP BY anon_key
   ORDER BY last_view DESC
   LIMIT :lim OFFSET :off
  ";
  $stList = $pdo->prepare($sql);
  $stList->bindValue(':rid', $rid, PDO::PARAM_INT);
  $stList->bindValue(':lim', $per, PDO::PARAM_INT);
  $stList->bindValue(':off', $off, PDO::PARAM_INT);
  $stList->execute();
  $rows = $stList->fetchAll(PDO::FETCH_ASSOC);
}

// --- Render senzill (Bootstrap) ---
require_once __DIR__ . '/../parts/head.php';
require_once __DIR__ . '/../parts/navmenu.php';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mask_email(?string $email): string {
  if (!$email) return '';
  [$u,$d] = array_pad(explode('@', $email, 2), 2, '');
  $u2 = mb_substr($u, 0, 2, 'UTF-8');
  return $u2 . str_repeat('*', max(0, mb_strlen($u, 'UTF-8') - 2)) . ($d ? "@$d" : '');
}
?>
<div class="container w-75">
  <!-- Títol -->
  <div class="d-flex justify-content-between align-items-center mb-1">
    <h3 class="border-bottom border-1 border-secondary pb-2 w-100">
      <?= h(t('own.riders.titol') ?? 'Visites al rider') ?>
      <!-- IF IS ADMIN -->
      <?php if ($isAdmin && $targetUserId !== $sessionUserId): ?>
        <small class="text-body-terciary">· user #<?= (int)$targetUserId ?></small>
      <?php endif; ?>
    </h3>    
  </div>
  
  <!-- Info del rider -->
    <?php
    $res_segell = 'validat';
    $state_segell = strtolower((string)($rider['Estat_Segell'] ?? ''));
    if ($state_segell === 'validat') $res_segell = 'bi-shield-fill-check text-success';
    elseif ($state_segell === 'caducat') $res_segell = 'bi-shield-fill-x text-danger';
    $fmtEU = function($s) {
      if (!$s) return '—';
      try {
        $dt = new DateTime((string)$s, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Madrid'));
        return $dt->format('d/m/Y H:i');
      } catch (Throwable $e) { return (string)$s; }
    };
  ?>
  <!-- Informació de visites i última -->
  <div class="mb-3">
    <p class="resultat-rider-info"><i class="bi <?= h($res_segell ?: '—') ?>"></i>&nbsp;<?= h((string)($rider['Nom_Arxiu'] ?? 'rider.pdf')) ?> (ID: <?= (int)$rider['ID_Rider'] ?>)</p>
    <p class="resultat-rider-info"><?= h(t('own.riders.visites-totals') ?? 'Visites totals') ?>: <span><?= (int)$cnt['total_views'] ?></span></p>
    <p class="resultat-rider-info"><?= h(t('own.riders.visites-ultima') ?? 'Última') ?>: <span><?= h($fmtEU($cnt['last_view_at'] ?? null)) ?></span></p>
  </div>

  <!-- Selector de taula -->

  <div class="btn-group mb-4" role="group" aria-label="<?= h(t('nav.recent_riders') ?? 'Riders que he vist') ?>">
    <button 
      type="button" 
      class="botons_riders btn btn-primary btn-sm <?= $tab==='users'?'active':'' ?>"    
      onclick="window.location.href='?rid=<?= (int)$rid ?>&tab=users';">
      <?= h(t('own.riders.visites-unics') ?? 'Visites amb loguin') ?>
      <span class="badge text-bg-secondary riders_vistos"><?= (int)$cnt['unique_logged_users'] ?></span>
    </button>
    <button 
      type="button" 
      class="botons_riders btn btn-primary btn-sm <?= $tab==='anon'?'active':'' ?>"    
      onclick="window.location.href='?rid=<?= (int)$rid ?>&tab=anon';">
      <?= h(t('own.riders.visites-anonimes') ?? 'Visites anònims') ?>
      <span class="badge text-bg-secondary riders_vistos"><?= (int)$cnt['anon_views'] ?></span>
    </button>
  </div>

  <!-- Taules de resultats -->
  <div class="table-responsive">
  <?php if ($tab === 'users'): ?>
    <table class="table table-sm align-middle">
      <thead><tr>
        <th><?= h(t('own.riders.nom') ?? 'Nom') ?></th>
        <th><?= h(t('own.riders.correu') ?? 'Correu electrònic') ?></th>
        <th style="width:120px" class="text-center"><?= h(t('views') ?? 'Visites') ?></th>
        <th style="width:190px" class="text-center"><?= h(t('last_view') ?? 'últ.Visita') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h(trim((string)($r['Nom_Usuari'].' '.$r['Cognoms_Usuari']))) ?></td>
          <td><?= h(mask_email((string)$r['Email_Usuari'])) ?></td>
          <td class="text-center"><?= (int)$r['views'] ?></td>
          <td class="text-center"><?= h($fmtEU($r['last_view'] ?? null)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-secondary"><?= h($fmtEU($r['no_results'] ?? null)) ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  <?php else: ?>
    <table class="table table-sm align-middle">
      <thead><tr>
        <th><?= h(t('own.riders.clau') ?? 'Clau anònima') ?></th>
        <th style="width:120px" class="text-center"><?= h(t('views') ?? 'Visites') ?></th>
        <th style="width:190px" class="text-center"><?= h(t('last_view') ?? 'últ.Visita') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-truncate" style="max-width:480px;"><?= h((string)$r['anon_key']) ?></td>
          <td class="text-center"><?= (int)$r['views'] ?></td>
          <td class="text-center"><?= h($fmtEU($r['last_view'] ?? null)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="3" class="text-secondary"><?= h($fmtEU($r['no_results'] ?? null)) ?><</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
  <nav aria-label="Paginació">
    <ul class="pagination pagination-sm">
      <?php for ($p=1; $p<=$pages; $p++): ?>
        <li class="page-item <?= $p===$page?'active':'' ?>">
          <a class="page-link" href="?rid=<?= (int)$rid ?>&tab=<?= h($tab) ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>

  <div class="mt-3">
    <a class="btn btn-primary btn-sm" href="<?= h(BASE_PATH) ?>espai.php?seccio=riders"><?= h(t('own.riders.tornar') ?? 'Tornar') ?></a>
  </div>
</div>

<?php require_once __DIR__ . '/../parts/footer.php'; ?>