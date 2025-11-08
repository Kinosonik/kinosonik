<?php
// stage_new.php — Alta d’un escenari per a un event
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

ks_require_role('productor','admin');
if (!function_exists('h')) { function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
function dt_from_local(?string $s): ?string { $s=trim((string)$s); if($s==='')return null; $s=str_replace('T',' ',$s); if(strlen($s)===16)$s.=':00'; return $s; }
function fmt_d(?string $d): string { if(!$d) return ''; $t=strtotime($d); return $t?date('d/m/Y',$t):''; }

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');
$eid = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
if ($eid<=0) { http_response_code(400); exit('bad_request'); }

$se = $pdo->prepare('SELECT * FROM Events WHERE id=:id'); $se->execute([':id'=>$eid]);
$ev = $se->fetch(PDO::FETCH_ASSOC);
if (!$ev){ http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$ev['owner_user_id'] !== $uid){ http_response_code(403); exit('forbidden'); }

// Dates de l’event per preomplir/limitar
$evDi  = $ev['data_inici'] ? substr((string)$ev['data_inici'],0,10) : '';
$evDf  = $ev['data_fi']     ? substr((string)$ev['data_fi'],0,10)     : '';
$isOpen = ((int)$ev['is_open_ended'] === 1);

$err='';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('csrf_invalid'); }

  $nom   = trim((string)($_POST['nom'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));
  $di = dt_from_local($_POST['data_inici'] ?? '');
  $df = dt_from_local($_POST['data_fi'] ?? '');

  if ($nom==='') $err='Introdueix un nom.';
  if (!$ev['is_open_ended']) {
    if (!$di || !$df) $err = $err ?: 'Cal finestra escenari dins la finestra de l’event.';
    if ($di && $ev['data_inici'] && $di < $ev['data_inici']) $err = $err ?: 'Inici escenari fora de l’event.';
    if ($df && $ev['data_fi'] && $df > $ev['data_fi']) $err = $err ?: 'Fi escenari fora de l’event.';
    if ($di && $df && $df < $di) $err = $err ?: 'Fi < Inici.';
  }

  if ($err==='') {
    try {
      $pdo->beginTransaction();

      // 1) Inserim l'escenari
      $ins = $pdo->prepare('INSERT INTO Event_Stages (event_id, nom, data_inici, data_fi, notes, ts_created, ts_updated)
                          VALUES (:eid, :nom, :di, :df, :notes, NOW(), NOW())');
      $ins->execute([
      ':eid'=>$eid, ':nom'=>$nom,
      ':di'=>$di ?: $ev['data_inici'],
      ':df'=>$ev['is_open_ended'] ? null : ($df ?: $ev['data_fi']),
      ':notes'=>$notes,
      ]);
      $sid = (int)$pdo->lastInsertId();

      // 2) Si l'usuari ha indicat INICI i FI vàlids, generem Stage_Days (un per dia, inclusiu)
      if ($di && $df && !$ev['is_open_ended']) {
        try {
          $start = new DateTime(substr($di, 0, 10));
          $end   = new DateTime(substr($df, 0, 10));
        } catch (Throwable $e) {
          $start = $end = null;
        }

        if ($start && $end && $end >= $start) {
          $insDay = $pdo->prepare(
            'INSERT INTO Stage_Days (stage_id, dia)
               SELECT :sid, :dia FROM DUAL
               WHERE NOT EXISTS (
                 SELECT 1 FROM Stage_Days WHERE stage_id = :sid2 AND dia = :dia2
               )'
          );
          for ($d = $start; $d <= $end; $d->modify('+1 day')) {
            $dia = $d->format('Y-m-d');
            $insDay->execute([
              ':sid'  => $sid,
              ':dia'  => $dia,
              ':sid2' => $sid,
              ':dia2' => $dia,
            ]);
          }
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      throw $e;
    }
    header('Location: ' . BASE_PATH . 'event.php?id=' . $eid); exit;
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
        <div class="card-header bg-kinosonik d-flex align-items-center">
          <div class="flex-grow-1 position-relative">
            <h6 class="mb-0 text-center"><i class="bi bi-plus-circle me-1"></i> Nou escenari</h6>
          </div>
          <div class="btn-group ms-2">
              <a class="btn-close btn-close-white" href="<?= h(BASE_PATH) ?>event.php?id=<?= (int)$eid ?>" title="Tanca"></a>
          </div>
        </div>

        <div class="card-body">
            <div class="small">
              <div class="w-100 mb-4 mt-2 small text-light"
                style="background: var(--ks-veil);
                border-left:3px solid var(--ks-accent);
                padding:12px 18px;">
                <strong class="text-secondary">Event</strong> <?= h($ev['nom']) ?> ·
                <strong class="text-secondary">Dates</strong>
                <?php if ($isOpen): ?>
                  <?= fmt_d($ev['data_inici']) ?> → ∞
                <?php else: ?>
                  <?= fmt_d($ev['data_inici']) ?> → <?= fmt_d($ev['data_fi']) ?>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= h(BASE_PATH) ?>stage_new.php" class="row g-3">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
              <input type="hidden" name="event_id" value="<?= (int)$eid ?>">
              <div class="col-md-12">
                <label class="form-label">Nom escenari</label>
                <input type="text" name="nom" maxlength="180" required class="form-control" autofocus>
              </div>
              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" rows="3" class="form-control" maxlength="1000"></textarea>
              </div>
              <div class="col-md-3">
                <label class="form-label">Data inici</label>
                <input
                  type="date" name="data_inici" id="data_inici" class="form-control"
                  value="<?= h($evDi) ?>"
                  <?php if ($evDi): ?>min="<?= h($evDi) ?>"<?php endif; ?>
                  <?php if (!$isOpen && $evDf): ?>max="<?= h($evDf) ?>"<?php endif; ?>
                >
              </div>
              <div class="col-md-3">
                <label class="form-label">Data fi</label>
                <input
                  type="date" name="data_fi" id="data_fi" class="form-control"
                  value="<?= h($evDf) ?>"
                  <?php if ($evDi): ?>min="<?= h($evDi) ?>"<?php endif; ?>
                  <?php if (!$isOpen && $evDf): ?>max="<?= h($evDf) ?>"<?php endif; ?>
                >
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
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const di = document.getElementById('data_inici');
  const df = document.getElementById('data_fi');
  function sync() { if (di && df && di.value) { df.min = di.value; } }
  di?.addEventListener('change', sync);
  sync();
});
</script>
<?php require_once __DIR__ . '/parts/footer.php'; ?>
