<?php
// rider_subscripcions.php — Riders als quals estic subscrit
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

$isAdmin = strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0;
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$csrf = $_SESSION['csrf'] ?? '';

$targetUserId = $sessionUserId;
if ($isAdmin && isset($_GET['user']) && ctype_digit((string)$_GET['user'])) {
  $targetUserId = (int)$_GET['user'];
}

$allowedStatus = ['tots','validat','caducat'];
$status = isset($_GET['status']) ? strtolower((string)$_GET['status']) : 'tots';
if (!in_array($status, $allowedStatus, true)) $status = 'tots';

$perPage = 50;
$page = (isset($_GET['page']) && ctype_digit((string)$_GET['page']) && (int)$_GET['page'] >= 1) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

// ── SQL base ───────────────────────────────────────────────
$whereBase  = "rs.Usuari_ID = :uid AND r.ID_Usuari <> :uid_excl AND rs.active=1";
$paramsBase = [':uid'=>$targetUserId, ':uid_excl'=>$targetUserId];

$whereList=$whereBase; $paramsList=$paramsBase;
if ($status==='validat') $whereList.=" AND LOWER(r.Estat_Segell)='validat'";
elseif ($status==='caducat') $whereList.=" AND LOWER(r.Estat_Segell)='caducat'";

$cntSql="SELECT
  SUM(CASE WHEN LOWER(r.Estat_Segell)='validat' THEN 1 ELSE 0 END) AS validated,
  SUM(CASE WHEN LOWER(r.Estat_Segell)='caducat' THEN 1 ELSE 0 END) AS expired,
  COUNT(*) AS total
  FROM Rider_Subscriptions rs JOIN Riders r ON r.ID_Rider=rs.Rider_ID WHERE $whereBase";
$stCnt=$pdo->prepare($cntSql); $stCnt->execute($paramsBase);
$counters=$stCnt->fetch(PDO::FETCH_ASSOC) ?: ['validated'=>0,'expired'=>0,'total'=>0];

$totSql="SELECT COUNT(*) FROM Rider_Subscriptions rs JOIN Riders r ON r.ID_Rider=rs.Rider_ID WHERE $whereList";
$stTot=$pdo->prepare($totSql); $stTot->execute($paramsList);
$totalRows=(int)$stTot->fetchColumn(); $totalPages=max(1,(int)ceil($totalRows/$perPage));

$listSql="SELECT
  r.ID_Rider,r.Rider_UID,r.Nom_Arxiu,r.Descripcio,r.Estat_Segell,r.Data_Publicacio,
  u.Nom_Usuari AS Owner_Nom,u.Cognoms_Usuari AS Owner_Cognoms,
  rs.ts_created,rs.ts_last_notified
  FROM Rider_Subscriptions rs
  JOIN Riders r ON r.ID_Rider=rs.Rider_ID
  JOIN Usuaris u ON u.ID_Usuari=r.ID_Usuari
  WHERE $whereList
  ORDER BY rs.ts_created DESC
  LIMIT :lim OFFSET :off";
$st=$pdo->prepare($listSql);
foreach($paramsList as $k=>$v){$st->bindValue($k,$v,PDO::PARAM_INT);}
$st->bindValue(':lim',$perPage,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows=$st->fetchAll(PDO::FETCH_ASSOC);

// Marca la secció activa pel navbar
$seccio = 'rider_subscripcions';

require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>
<div class="container my-3">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
      <i class="bi bi-bell"></i>&nbsp;&nbsp;<?= h(__('riders.subscrit.titol')) ?>
      <?php if ($isAdmin && $targetUserId!==$sessionUserId): ?>
        <small class="text-body-terciary">· user #<?= (int)$targetUserId ?></small>
      <?php endif; ?>
    </h4>
    <?php if ($isAdmin): ?>
      <form class="d-flex gap-2" method="get" action="<?= h(BASE_PATH) ?>rider_subscripcions.php">
        <input type="hidden" name="status" value="<?= h($status) ?>">
        <input type="number" min="1" class="form-control form-control-sm" name="user"
               value="<?= (int)$targetUserId ?>" style="width:120px" placeholder="User ID">
        <button class="btn btn-outline-secondary btn-sm">Canvia</button>
      </form>
    <?php endif; ?>
  </div>

  <?php
  $mkHref = function(string $st) use ($targetUserId) {
  $qs = ['status' => $st];
    if ($targetUserId) {
      $qs['user'] = (string)$targetUserId;
    }
    return BASE_PATH . 'rider_subscripcions.php?' .
      http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
  };

  ?>
  <div class="btn-group mb-4" role="group">
    <button class="btn btn-primary btn-sm <?= $status==='tots'?'active':'' ?>"
            onclick="location='<?= h($mkHref('tots')) ?>';"><?= h(__('riders.subscrit.tots')) ?>
      <span class="badge text-bg-secondary"><?= (int)($counters['total']??0) ?></span></button>
    <button class="btn btn-primary btn-sm <?= $status==='validat'?'active':'' ?>"
            onclick="location='<?= h($mkHref('validat')) ?>';"><i class="bi bi-shield-check"></i> <?= h(__('riders.subscrit.validats')) ?>
      <span class="badge text-bg-secondary"><?= (int)($counters['validated']??0) ?></span></button>
    <button class="btn btn-primary btn-sm <?= $status==='caducat'?'active':'' ?>"
            onclick="location='<?= h($mkHref('caducat')) ?>';"><i class="bi bi-shield-x"></i> <?= h(__('riders.subscrit.caducats')) ?>
      <span class="badge text-bg-primary"><?= (int)($counters['expired']??0) ?></span></button>
  </div>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th class="text-center" style="width:56px;">ID</th>
          <th>Rider</th>
          <th class="text-center"><i class="bi bi-shield-shaded"></i></th>
          <th><?= h(__('riders.subscrit.propietari')) ?></th>
          <th class="text-center"><?= h(__('riders.subscrit.subscritdesde')) ?></th>
          <th class="text-center"><?= h(__('riders.subscrit.darreranotificacio')) ?></th>
          <th class="text-end"></th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="7" class="text-center text-body-secondary py-2">— <?= h(__('common.no_results')) ?> —</td></tr>
      <?php else: foreach($rows as $r): 
        $state=strtolower((string)($r['Estat_Segell']??'')); 
        $icon=$state==='validat'?'bi-shield-fill-check text-success':
              ($state==='caducat'?'bi-shield-fill-x text-danger':'bi-shield text-secondary');
        $owner=trim(($r['Owner_Nom']??'').' '.($r['Owner_Cognoms']??'')); 
        $desc=$r['Descripcio']?:('rider-'.$r['Rider_UID'].'.pdf');
        $pdfUrl=BASE_PATH.'php/rider_file.php?ref='.rawurlencode((string)$r['Rider_UID']);
        $viewUrl=BASE_PATH.'visualitza.php?ref='.rawurlencode((string)$r['Rider_UID']);
        $subDate=$r['ts_created']?(new DateTime($r['ts_created']))->format('d/m/Y H:i'):'—';
        $lastNotif=$r['ts_last_notified']?(new DateTime($r['ts_last_notified']))->format('d/m/Y H:i'):'—';
      ?>
        <tr id="row-<?= (int)$r['ID_Rider'] ?>">
          <td class="text-center"><?= (int)$r['ID_Rider'] ?></td>
          <td class="text-truncate" style="max-width:380px;">
            <a class="link-light text-decoration-none" href="<?= h($pdfUrl) ?>" target="_blank" rel="noopener">
              <?= h($desc) ?></a></td>
          <td class="text-center"><i class="bi <?= h($icon) ?>"></i></td>
          <td class="text-truncate" style="max-width:240px;"><?= h($owner ?: '—') ?></td>
          <td class="text-center"><?= h($subDate) ?></td>
          <td class="text-center"><?= h($lastNotif) ?></td>
          <!-- Accions -->
           <td class="text-end">
            <div class="btn-group btn-group-sm" role="group">
              <a href="<?= h($viewUrl) ?>" target="_blank" rel="noopener"
                class="btn btn-primary btn-sm"
                title="Obrir fitxa pública">
                <i class="bi bi-box-arrow-up-right"></i>
              </a>
              <button class="btn btn-danger btn-sm unsub-btn" data-id="<?= (int)$r['ID_Rider'] ?>">
                <i class="bi bi-bell-slash"></i>
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      <tr><td colspan="7" class="border-0 text-center small"><?= h(__('riders.subscrit.eliminats')) ?></td></tr>
      </tbody>
    </table>
  </div>

  <?php if($totalPages>1): 
  $mkPageHref = function(int $p) use ($status, $targetUserId) {
  $qs = ['status' => $status, 'page' => $p];
  if ($targetUserId) {
    $qs['user'] = (string)$targetUserId;
  }
  return BASE_PATH . 'rider_subscripcions.php?' .
  http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
};

 ?>
    <nav aria-label="Paginació">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= h($mkPageHref(max(1,$page-1))) ?>">«</a></li>
        <li class="page-item disabled"><span class="page-link"><?= (int)$page ?> / <?= (int)$totalPages ?></span></li>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
          <a class="page-link" href="<?= h($mkPageHref(min($totalPages,$page+1))) ?>">»</a></li>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.unsub-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id = btn.dataset.id;
    const form = new URLSearchParams();
    form.append('rider_id', id);
    form.append('csrf', '<?= $csrf ?>');

    try {
      const res = await fetch('php/rider_sub_toggle.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString()
      });

      if (!res.ok) throw new Error('HTTP ' + res.status);

      const j = await res.json();
      if (j.ok && !j.subscribed) {
        // recarrega per treure la fila i mantenir comptadors/paginació coherents
        window.location.reload();
      }
    } catch (e) {
      console.error(e);
      alert('Error en cancel·lar la subscripció.');
    }
  });
});
</script>
<?php require_once __DIR__ . '/parts/footer.php'; ?>