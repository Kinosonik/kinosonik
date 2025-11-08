<?php
// event_new.php — Alta d’un esdeveniment
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';

ks_require_role('productor','admin');

if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}

function dt_from_local(?string $s): ?string {
  $s = trim((string)$s);
  if ($s==='') return null;
  $s = str_replace('T',' ',$s);     // HTML datetime-local → "YYYY-mm-dd HH:MM"
  if (strlen($s)===16) $s .= ':00'; // afegeix segons
  return $s;                        // string naive (server TZ)
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$err = '';

// Defaults per repintar el formulari si hi ha error
$old_nom   = (string)($_POST['nom'] ?? '');
$old_estat = (string)($_POST['estat'] ?? 'esborrany');
$old_open  = isset($_POST['is_open_ended']) && $_POST['is_open_ended']=='1';
$old_di    = (string)($_POST['data_inici'] ?? '');
$old_df    = (string)($_POST['data_fi'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('csrf_invalid'); }

  $nom   = trim((string)($_POST['nom'] ?? ''));
  $estat = (string)($_POST['estat'] ?? 'esborrany');
  $isOpen = (int)((isset($_POST['is_open_ended']) && $_POST['is_open_ended']=='1') ? 1 : 0);
  $di = d_from_input($_POST['data_inici'] ?? '');
  $df = d_from_input($_POST['data_fi'] ?? '');

  if ($nom === '' || mb_strlen($nom) > 180) $err = 'Introdueix un nom vàlid (1–180 caràcters).';
  if (!$isOpen && (!$di || !$df)) $err = $err ?: 'Cal data d’inici i fi si no és obert.';
  if (!$isOpen && $di && $df && $df < $di) $err = $err ?: 'La data fi no pot ser anterior a l’inici.';
  if (!in_array($estat, ['esborrany','actiu','tancat'], true)) $estat = 'esborrany';

  if ($err === '') {
    $ins = $pdo->prepare(
      'INSERT INTO Events (owner_user_id, nom, is_open_ended, data_inici, data_fi, estat, ts_created, ts_updated)
       VALUES (:uid, :nom, :open, :di, :df, :st, NOW(), NOW())'
    );
    $ins->execute([
      ':uid'  => $uid,
      ':nom'  => $nom,
      ':open' => $isOpen,
      ':di'   => $di ?: date('Y-m-d'),
      ':df'   => $isOpen ? null : $df,
      ':st'   => $estat,
    ]);
    $eid = (int)$pdo->lastInsertId();
    header('Location: ' . BASE_PATH . 'event.php?id=' . $eid);
    exit;
  }
}

require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>
<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-8">
      <?php if ($err): ?>
        <div class="alert alert-warning k-card"><?= h($err) ?></div>
      <?php endif; ?>

      <div class="card border-1 shadow">
        <!-- Títol box -->
        <div class="card-header bg-kinosonik centered">
          <h6><i class="bi bi-plus-circle me-1"></i> Nou esdeveniment</h6>
          <div class="btn-group ms-2">
            <a class="btn-close btn-close-white" href="<?= h(BASE_PATH) ?>espai.php?seccio=produccio" title="Tanca"></a>
          </div>
        </div>

        <div class="card-body">
          <div class="small">
            <form method="post" action="<?= h(BASE_PATH) ?>event_new.php" class="row g-3" autocomplete="off" novalidate>
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">

              <div class="col-md-8">
                <label class="form-label">Nom</label>
                <input type="text" name="nom" maxlength="180" required class="form-control" autofocus
                       value="<?= h($old_nom) ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label">Estat</label>
                <select name="estat" class="form-select">
                  <option value="esborrany" <?= $old_estat==='esborrany'?'selected':''; ?>>esborrany</option>
                  <option value="actiu"     <?= $old_estat==='actiu'?'selected':''; ?>>actiu</option>
                  <option value="tancat"    <?= $old_estat==='tancat'?'selected':''; ?>>tancat</option>
                </select>
              </div>

              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="1" id="chkOpen" name="is_open_ended" <?= $old_open?'checked':''; ?>>
                  <label class="form-check-label" for="chkOpen">Esdeveniment obert (sense data de fi)</label>
                </div>
              </div>

              <div class="col-md-3">
                <label class="form-label">Data inici</label>
                <input type="date" name="data_inici" id="data_inici" class="form-control"
                       value="<?= h($old_di) ?>">
              </div>

              <div class="col-md-3">
                <label class="form-label">Data fi</label>
                <input type="date" name="data_fi" id="data_fi" class="form-control"
                       value="<?= h($old_df) ?>">
              </div>

              <div class="col-12 text-end">
                <button class="btn btn-sm btn-primary" type="submit">
                  <i class="bi bi-plus-circle me-1"></i> <?= h(__('common.save') ?: 'Desa') ?>
                </button>
                <button class="btn btn-sm btn-secondary" type="reset">
                  <i class="bi bi-x-circle me-1"></i> <?= h(__('common.reset') ?: 'Neteja') ?>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div> <!-- /card -->
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const chkOpen = document.getElementById('chkOpen');
  const di = document.getElementById('data_inici');
  const df = document.getElementById('data_fi');

  function syncEndState() {
    const open = chkOpen.checked;
    if (open) {
      df.value = '';
      df.setAttribute('disabled', 'disabled');
      df.removeAttribute('required');
      df.removeAttribute('min');
    } else {
      df.removeAttribute('disabled');
      df.setAttribute('required', 'required');
      if (di.value) df.setAttribute('min', di.value);
    }
  }

  function syncMin() {
    if (!chkOpen.checked && di.value) df.setAttribute('min', di.value);
  }

  chkOpen.addEventListener('change', syncEndState);
  di.addEventListener('change', syncMin);

  // init
  syncEndState();
  syncMin();
});
</script>

<?php require_once __DIR__ . '/parts/footer.php'; ?>
