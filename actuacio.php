<?php
// actuacio.php — Fitxa d'una actuació (Stage_Day_Acts.id)
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';

ks_require_role('productor','admin');

if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
function fmt_d(?string $d): string { if(!$d) return ''; $t=strtotime($d); return $t?date('d/m/Y',$t):''; }

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

$actId = (int)($_GET['id'] ?? 0);
if ($actId <= 0) { http_response_code(400); exit('bad_request'); }

// ── Carrega context + autorització ────────────────────────────────────
$sql = <<<SQL
SELECT 
  a.id AS act_id, a.stage_day_id, a.ordre, a.artista_nom,
  a.negotiation_state, a.needs_contrarider_refresh,
  a.rider_orig_doc_id, a.rider_orig_id,
  a.final_doc_id,
  a.ia_precheck_status, a.ia_precheck_score, a.ia_precheck_summary,
  d.dia,
  s.id AS stage_id, s.nom AS stage_nom,
  e.id AS event_id, e.nom AS event_nom, e.owner_user_id
FROM Stage_Day_Acts a
JOIN Stage_Days d   ON d.id = a.stage_day_id
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e       ON e.id = s.event_id
WHERE a.id = :id
SQL;
$st = $pdo->prepare($sql);
$st->execute([':id'=>$actId]);
$act = $st->fetch(PDO::FETCH_ASSOC);
if (!$act) { http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$act['owner_user_id'] !== $uid) { http_response_code(403); exit('forbidden'); }

/* ── Head + Nav ─────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>
<div class="container w-75">

  <!-- ────────────────────────────── CAPÇALERA ────────────────────────────── -->
  <div class="d-flex justify-content-between align-items-center mb-2 border-bottom border-1 border-secondary">
    <h4 class="text-start">
      <i class="bi bi-gear-wide-connected me-2"></i>&nbsp;&nbsp;
      <?= h($act['event_nom']) ?> <i class="bi bi-arrow-right"></i>
      <?= h($act['stage_nom']) ?> <i class="bi bi-arrow-right"></i>
      <?= fmt_d($act['dia']) ?> <i class="bi bi-arrow-right"></i>
      <?= h($act['artista_nom']) ?>
    </h4>
  </div>

  <!-- Breadcrumb -->
  <div class="w-100 mb-3 small bc-kinosonik">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li><a href="<?= h(BASE_PATH) ?>espai.php?seccio=produccio">Els teus esdeveniments</a></li>
        <li><a href="<?= h(BASE_PATH) ?>event.php?id=<?= (int)$act['event_id'] ?>"><?= h($act['event_nom']) ?></a></li>
        <li><a href="<?= h(BASE_PATH) ?>produccio_escenari.php?id=<?= (int)$act['stage_id'] ?>"><?= h($act['stage_nom']) ?></a></li>
        <li><a href="<?= h(BASE_PATH) ?>stage_day_detail.php?id=<?= (int)$act['stage_day_id'] ?>">Programació <?= fmt_d($act['dia']) ?></a></li>
        <li class="active"><?= h($act['artista_nom']) ?></li>
      </ol>
    </nav>
  </div>

  <!-- ────────────────────────────── BLOC 0 — Resum ràpid ────────────────────────────── -->
  <div class="row row-cols-auto small text-secondary mb-3">
    <div class="col">Ordre: <span class="text-light"><?= (int)$act['ordre'] ?></span></div>
    <div class="col">Estat:
      <span class="badge text-bg-secondary"><?= h($act['negotiation_state'] ?? 'rider_rebut') ?></span>
    </div>
    <?php if ($act['ia_precheck_score'] !== null): ?>
      <?php $cls = ((int)$act['ia_precheck_score']<60?'danger':(((int)$act['ia_precheck_score']<=80)?'warning':'success')); ?>
      <div class="col">IA: <span class="badge text-bg-<?= $cls ?>"><?= (int)$act['ia_precheck_score'] ?></span></div>
    <?php endif; ?>
    <?php if ((int)$act['needs_contrarider_refresh'] === 1): ?>
      <div class="col"><span class="badge text-bg-warning">refresh</span></div>
    <?php endif; ?>
  </div>

    <!-- ────────────────────────────── BLOC 1 — Rider original ────────────────────────────── -->
  <div class="card k-card mb-3">
    <div class="card-header d-flex align-items-center">
      <i class="bi bi-music-note-list me-1"></i>
      <span>Rider original</span>
      <div class="ms-auto small text-secondary">
        <?= $act['rider_orig_id'] ? 'Validat Kinosonik' : ($act['rider_orig_doc_id'] ? 'Pujat manualment' : '— cap —') ?>
      </div>
    </div>

    <div class="card-body small">
      <?php if ($act['rider_orig_id']): ?>
        <!-- Cas A: Rider amb segell Kinosonik Riders -->
        <div class="alert alert-success py-2 small mb-3">
          <i class="bi bi-patch-check-fill me-1"></i>
          Rider validat per <strong>Kinosonik Riders</strong>.
        </div>
        <p class="mb-2">Aquest rider ja ha estat verificat per IA i certificat.</p>
        <a href="<?= h(BASE_PATH) ?>rider_detail.php?id=<?= (int)$act['rider_orig_id'] ?>"
           target="_blank" class="btn btn-outline-light btn-sm">
          <i class="bi bi-box-arrow-up-right"></i> Obrir rider validat
        </a>

      <?php elseif ($act['rider_orig_doc_id']): ?>
        <!-- Cas B: Rider pujat manualment -->
        <div class="mb-2">
          <a href="<?= h(BASE_PATH) ?>document.php?id=<?= (int)$act['rider_orig_doc_id'] ?>&view=1"
             target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-pdf"></i> Veure rider pujat
          </a>
          <a href="<?= h(BASE_PATH) ?>act_upload_orig.php?id=<?= (int)$act['act_id'] ?>"
             class="btn btn-outline-light btn-sm ms-2">
            <i class="bi bi-upload"></i> Reemplaça rider
          </a>
        </div>

        <?php if (!empty($act['ia_precheck_status'])): ?>
          <div class="mt-2">
            <span class="badge text-bg-<?=
              $act['ia_precheck_status']==='ok' ? 'success' :
              ($act['ia_precheck_status']==='running' ? 'info' :
              ($act['ia_precheck_status']==='queued' ? 'secondary' : 'danger'))
            ?>">
              <?= h($act['ia_precheck_status']) ?>
            </span>
            <?php if ($act['ia_precheck_score'] !== null): ?>
              <span class="ms-1 text-secondary">Score:
                <strong><?= (int)$act['ia_precheck_score'] ?></strong>
              </span>
            <?php endif; ?>
            <?php if ($act['ia_precheck_status']==='ok'): ?>
              <a href="<?= h(BASE_PATH) ?>ia_detail.php?job=<?= urlencode($act['act_id']) ?>"
                 class="btn btn-outline-light btn-sm ms-3">
                <i class="bi bi-robot"></i> Informe IA
              </a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="text-body-secondary small mt-2">Encara no s’ha processat cap anàlisi IA.</div>
        <?php endif; ?>

      <?php else: ?>
        <!-- Cas C: Encara no s'ha pujat res -->
        <p class="text-body-secondary mb-3">Encara no hi ha cap rider original pujat per aquesta actuació.</p>
        <a href="<?= h(BASE_PATH) ?>act_upload_orig.php?id=<?= (int)$act['act_id'] ?>"
           class="btn btn-outline-light btn-sm">
          <i class="bi bi-upload"></i> Pujar rider original
        </a>
      <?php endif; ?>
    </div>
  </div>


  <!-- ────────────────────────────── BLOC 2 — Contra-rider ────────────────────────────── -->
    <!-- Pujada de CONTRA-RIDER -->
  <div class="card k-card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-arrow-repeat"></i> Contra-rider</span>
      <?php if (!empty($act['final_doc_id'])): ?>
        <span class="badge text-bg-success">existent</span>
      <?php else: ?>
        <span class="badge text-bg-secondary">pendent</span>
      <?php endif; ?>
    </div>
    <div class="card-body small">
      <?php if (!empty($act['final_doc_id'])): ?>
        <p class="mb-2 text-light">
          Actual: 
          <a href="<?= h(BASE_PATH) ?>document.php?id=<?= (int)$act['final_doc_id'] ?>&view=1" target="_blank">
            <i class="bi bi-file-earmark-pdf"></i>
            <?= h($docFinalName ?: 'contra_rider.pdf') ?>
          </a>
        </p>
        <hr class="border-secondary">
        <p class="text-secondary mb-2">Pots substituir-lo per un nou fitxer:</p>
      <?php else: ?>
        <p class="text-secondary mb-2">Encara no hi ha cap contra-rider pujat.</p>
      <?php endif; ?>

      <form id="frmContra" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
        <input type="hidden" name="act_id" value="<?= (int)$act['act_id'] ?>">
        <div class="mb-2">
          <input type="file" name="contra_pdf" accept="application/pdf" required class="form-control form-control-sm">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-upload"></i> <?= empty($act['final_doc_id']) ? 'Pujar contra-rider' : 'Substitueix contra-rider' ?>
        </button>
        <div id="contraStatus" class="mt-2 text-secondary"></div>
      </form>
    </div>
  </div>


  <!-- ────────────────────────────── BLOC 3 — Respostes de l’artista ────────────────────────────── -->
  <div class="card k-card mb-3">
    <div class="card-header"><i class="bi bi-inbox me-1"></i> Respostes de l’artista</div>
    <div class="card-body small">
      <p class="text-body-secondary mb-0">Aquí es llistaran els documents i missatges rebuts (Negotiation_Events).</p>
    </div>
  </div>

  <!-- ────────────────────────────── BLOC 4 — Estat i accions ────────────────────────────── -->
  <div class="card k-card mb-3">
    <div class="card-header"><i class="bi bi-flag me-1"></i> Estat de negociació</div>
    <div class="card-body small">
      <div class="btn-group">
        <button type="button" class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
          Canvia estat
        </button>
        <ul class="dropdown-menu dropdown-menu-dark">
          <?php
          $states = ['rider_rebut','contra_enviat','esperant_resposta','comentat','acord_tancat','final_publicat','final_reobert'];
          foreach ($states as $stt): ?>
            <li><button class="dropdown-item"><?= h($stt) ?></button></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <button class="btn btn-outline-warning btn-sm ms-2">Neteja refresc</button>
    </div>
  </div>

  <!-- ────────────────────────────── BLOC 5 — Resum IA ────────────────────────────── -->
  <div class="card k-card mb-3">
    <div class="card-header"><i class="bi bi-robot me-1"></i> Resum IA</div>
    <div class="card-body small">
      <?php if (!empty($act['ia_precheck_summary'])): ?>
        <pre class="mb-0 text-body-emphasis" style="white-space:pre-wrap"><?= h($act['ia_precheck_summary']) ?></pre>
      <?php else: ?>
        <p class="text-body-secondary mb-0">Sense resum disponible.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ────────────────────────────── BLOC 6 — Històric de documents ────────────────────────────── -->
  <div class="card k-card mb-3">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i> Històric de documents</div>
    <div class="card-body small">
      <p class="text-body-secondary mb-0">Llistat cronològic de tots els riders i versions.</p>
    </div>
  </div>

  <!-- ────────────────────────────── BLOC 7 — Accions finals ────────────────────────────── -->
  <div class="card k-card mb-5">
    <div class="card-header"><i class="bi bi-check2-circle me-1"></i> Accions finals</div>
    <div class="card-body small">
      <button class="btn btn-success btn-sm me-2">Marca com OK</button>
      <button class="btn btn-outline-light btn-sm">Genera pack final</button>
    </div>
  </div>

</div>
<script>
// ── Pujada AJAX de contra-rider ─────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const frm = document.getElementById('frmContra');
  if (!frm) return;
  const status = document.getElementById('contraStatus');
  const btn = frm.querySelector('button[type="submit"]');

  frm.addEventListener('submit', async e => {
    e.preventDefault();
    status.textContent = 'Pujant...';
    btn.disabled = true;
    const fd = new FormData(frm);
    try {
      const res = await fetch('<?= h(BASE_PATH) ?>php/act_upload_contra.php', { method: 'POST', body: fd });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const j = await res.json();
      if (j.ok) {
        status.innerHTML = '<span class="text-success">Pujat correctament.</span>';
        setTimeout(() => location.reload(), 1000);
      } else {
        status.innerHTML = '<span class="text-danger">Error: ' + (j.error || 'desconegut') + '</span>';
      }
    } catch (err) {
      console.error(err);
      status.innerHTML = '<span class="text-danger">Error de connexió.</span>';
      } finally {
      btn.disabled = false;
    }
  });
});
</script>


<?php require_once __DIR__ . '/parts/footer.php'; ?>
