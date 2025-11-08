<?php
// stage_day_detail.php — Detall d'un dia d'escenari: actuacions i estat
// Accés: productor o admin. Requereix: Stage_Days, Event_Stages, Events, Stage_Day_Acts

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
function fmt_d(?string $d): string  { if(!$d) return ''; $t=strtotime($d);  return $t?date('d/m/Y',$t):''; }
function badge_state(string $s): string {
  return [
    'rider_rebut'        => 'secondary',
    'contra_enviat'      => 'info',
    'esperant_resposta'  => 'warning',
    'comentat'           => 'primary',
    'acord_tancat'       => 'success',
    'final_publicat'     => 'success',
    'final_reobert'      => 'danger',
  ][$s] ?? 'secondary';
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');
$dayId = (int)($_GET['id'] ?? 0);
if ($dayId <= 0) { http_response_code(400); exit('bad_request'); }

// ── Context: dia + escenari + event i autorització ────────────────────
$sqlCtx = <<<SQL
SELECT d.id AS day_id, d.dia,
       s.id AS stage_id, s.nom AS stage_nom, s.data_inici AS stage_inici, s.data_fi AS stage_fi,
       e.id AS event_id, e.nom AS event_nom, e.owner_user_id,
       e.is_open_ended, e.data_inici AS event_inici, e.data_fi AS event_fi
FROM Stage_Days d
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e       ON e.id = s.event_id
WHERE d.id = :did
SQL;
$st = $pdo->prepare($sqlCtx); $st->execute([':did'=>$dayId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if(!$ctx){ http_response_code(404); exit('not_found'); }
if(!$isAdmin && (int)$ctx['owner_user_id'] !== $uid){ http_response_code(403); exit('forbidden'); }

// ── Actuacions del dia ────────────────────────────────────────────────
$sqlActs = <<<SQL
SELECT a.id, a.ordre, a.artista_nom,
       a.rider_orig_doc_id, a.rider_orig_id, a.ks_seal_detected, a.ks_seal_valid_at,
       a.ia_precheck_status, a.ia_precheck_score, a.ia_precheck_summary,
       a.negotiation_state, a.needs_contrarider_refresh,
       a.final_doc_id
FROM Stage_Day_Acts a
WHERE a.stage_day_id = :did
ORDER BY a.ordre, a.id
SQL;
$pa = $pdo->prepare($sqlActs); $pa->execute([':did'=>$dayId]);
$acts = $pa->fetchAll(PDO::FETCH_ASSOC);

// Mètriques ràpides
$total = count($acts);
$okCnt = 0; $minIa = null; $refreshCnt = 0;
foreach ($acts as $a) {
  if (!empty($a['final_doc_id'])) $okCnt++;
  if ($a['ia_precheck_score'] !== null) {
    $sc = (int)$a['ia_precheck_score'];
    $minIa = ($minIa === null) ? $sc : min($minIa, $sc);
  }
  if (!empty($a['needs_contrarider_refresh'])) $refreshCnt++;
}

/* ── Head + Nav ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>

<div class="container w-75">
  <!-- Títol -->
  <div class="d-flex justify-content-between align-items-center mb-2 border-bottom border-1 border-secondary ">
    <h4 class="text-start">
      <i class="bi bi-gear-wide-connected me-2"></i>&nbsp;&nbsp;
      <?= h($ctx['event_nom']) ?> <i class="bi bi-arrow-right"></i> <?= h($ctx['stage_nom']) ?> <i class="bi bi-arrow-right"></i> <?= fmt_d($ctx['dia']) ?>
    </h4>
    <div class="btn-group d-flex" role="group">
      <button type="button" class="btn btn-primary btn-sm"
        onclick="window.location.href='<?= h(BASE_PATH) ?>act_new.php?stage_day_id=<?= (int)$ctx['day_id'] ?>';"
        data-bs-toggle="tooltip" data-bs-title="Afegir actuació" aria-label="Afegir actuació">
        <i class="bi bi-person-plus"></i>
      </button>
    </div>
  </div>

  <!-- Breadcumb Producció  -->
  <div class="w-100 mb-2 mt-2 small bc-kinosonik">
      <nav aria-label="breadcrumb">
          <ol class="breadcrumb">
              <li><a href="<?= h(BASE_PATH) ?>espai.php?seccio=produccio">Els teus esdeveniments</a></li>
              <li><a href="<?= h(BASE_PATH) ?>event.php?id=<?= (int)$ctx['event_id'] ?>"><?= h($ctx['event_nom']) ?></a></li>
              <li><a href="<?= h(BASE_PATH) ?>produccio_escenari.php?id=<?= (int)$ctx['stage_id'] ?>"><?= h($ctx['stage_nom']) ?></a></li>
              <li class="active">Programació dia <?= fmt_d($ctx['dia']) ?></li>
          </ol>
      </nav>
  </div>

  <!-- Subinfo i estat agregat -->
  <div class="d-flex mb-1 small text-secondary">
    <div class="w-100">
      <div class="row row-cols-auto justify-content-start text-start">
        <div class="col">ID: <span class="text-light"><?= h($ctx['event_id']) ?></span></div>
        <div class="col">
          Finestra escenari:
          <span class="text-light"><?= fmt_d($ctx['stage_inici']) ?> → <?= $ctx['stage_fi']?fmt_d($ctx['stage_fi']):'∞' ?></span>
        </div>
        <div class="col">
          Finestra event:
          <span class="text-light"><?php if ((int)$ctx['is_open_ended']===1): ?><?= fmt_d($ctx['event_inici']) ?> → ∞<?php else: ?><?= fmt_d($ctx['event_inici']) ?> → <?= fmt_d($ctx['event_fi']) ?><?php endif; ?></span>
        </div>
        <div class="col">
          Estat del dia:
          <span class="text-light"><?= $total>0 && $okCnt===$total ? 'OK complet' : ("$okCnt/$total OK") ?></span>
        </div>
        <div class="col">
          Flags: <span class="text-light"><?= $refreshCnt ?> refresh</span>
        </div>
        <?php if ($minIa!==null): ?>
          <div class="col">
            IA min: <span class="text-light"><?= (int)$minIa ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Taula d'actuacions -->
  <div class="d-flex align-items-center mb-2 mt-4">
    <h2 class="h5 mb-0">Actuacions</h2>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th class="text-center text-light">Ordre</th>
          <th class="text-start  text-light">Artista</th>
          <th class="text-center text-light">Rider original</th>
          <th class="text-center text-light">IA precheck</th>
          <th class="text-center text-light">Estat</th>
          <th class="text-center text-light">Flags</th>
          <th class="text-end  text-light">Accions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($acts)): ?>
          <tr><td colspan="7" class="text-center text-body-secondary py-2">— <?= h(__('no_results') ?: 'Sense resultats') ?> —</td></tr>
        <?php else: foreach ($acts as $a): ?>
          <?php
            $iaCls = 'secondary';
            if ($a['ia_precheck_status'] === 'running') $iaCls = 'info';
            elseif ($a['ia_precheck_status'] === 'error') $iaCls = 'danger';
            elseif ($a['ia_precheck_status'] === 'ok') {
              if ($a['ia_precheck_score'] !== null) {
                $sc = (int)$a['ia_precheck_score'];
                $iaCls = ($sc < 60) ? 'danger' : (($sc <= 80) ? 'warning' : 'success');
              } else {
                $iaCls = 'success';
              }
            }
          ?>
          <tr>
            <!-- Ordre modificable -->
            <td class="text-center">
              <select class="form-select form-select-sm w-auto d-inline reorder-select"
                      data-id="<?= (int)$a['id'] ?>">
                <?php for($i=1; $i<=$total; $i++): ?>
                  <option value="<?= $i ?>" <?= $i===(int)$a['ordre']?'selected':'' ?>><?= $i ?></option>
                <?php endfor; ?>
              </select>
            </td>
            <!-- Seguim -->
            <td class="text-start ">
              <a href="<?= h(BASE_PATH) ?>actuacio.php?id=<?= (int)$a['id'] ?>"><?= h($a['artista_nom']) ?></a>
              <?php if (!empty($a['final_doc_id'])): ?>
                <span class="badge text-bg-success ms-2">final</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if (!empty($a['rider_orig_doc_id'])): ?>
                <span class="badge text-bg-light text-dark">pujat</span>
                <?php if (!empty($a['ks_seal_detected'])): ?>
                  <span class="badge text-bg-<?= !empty($a['ks_seal_valid_at']) ? 'success' : 'warning' ?> ms-1">segell</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-body-secondary">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($a['ia_precheck_status']): ?>
                <span class="badge text-bg-<?= $iaCls ?>">
                  <?= h($a['ia_precheck_status']) ?><?= $a['ia_precheck_score']!==null?(' · '.(int)$a['ia_precheck_score']):'' ?>
                </span>
              <?php else: ?>
                <span class="text-body-secondary">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge text-bg-<?= badge_state($a['negotiation_state'] ?? 'rider_rebut') ?>"><?= h($a['negotiation_state'] ?? 'rider_rebut') ?></span>
            </td>
            <td class="text-center">
              <?php if (!empty($a['needs_contrarider_refresh'])): ?>
                <span class="badge text-bg-warning">refresh</span>
              <?php else: ?>
                <span class="text-body-secondary">—</span>
              <?php endif; ?>
            </td>
            <!-- Accions -->
            <td class="text-end">
              <div class="btn-group btn-group-sm flex-nowrap" role="group">
                <button type="button" class="btn btn-primary btn-sm"
                  onclick="window.location.href='<?= h(BASE_PATH) ?>actuacio.php?id=<?= (int)$a['id'] ?>';"
                  data-bs-toggle="tooltip" data-bs-title="Obrir actuació" aria-label="Obrir actuació">
                  <i class="bi bi-box-arrow-in-right"></i>
                </button>
                <button type="button" class="btn btn-primary btn-sm"
                  onclick="window.location.href='<?= h(BASE_PATH) ?>act_edit.php?id=<?= (int)$a['id'] ?>';"
                  data-bs-toggle="tooltip" data-bs-title="Editar actuació" aria-label="Editar actuació">
                  <i class="bi bi-pencil-square"></i>
                </button>
                <button
                  type="button"
                  class="btn btn-danger btn-sm text-nowrap"
                  data-bs-toggle="modal"
                  data-bs-target="#modalDeleteAct"
                  data-act-id="<?= (int)$a['id'] ?>"
                  data-act-name="<?= htmlspecialchars((string)$a['artista_nom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  title="Elimina actuació">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        <tr>
            <td colspan="7" class="text-end text-body-secondary py-2 border-0">
                <button type="button" class="btn btn-primary btn-sm"
        onclick="window.location.href='<?= h(BASE_PATH) ?>act_new.php?stage_day_id=<?= (int)$ctx['day_id'] ?>';"
        data-bs-toggle="tooltip" data-bs-title="Afegir actuació" aria-label="Afegir actuació">
        <i class="bi bi-plus-circle"></i> Afegir actuació
      </button>
              </td>
            </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ────────────────────────────── Modal: Eliminar actuació ────────────────────────────── -->
<div class="modal fade" id="modalDeleteAct" tabindex="-1" aria-labelledby="modalDeleteActLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-light border border-danger-subtle">
      <div class="modal-header border-0">
        <h5 class="modal-title" id="modalDeleteActLabel">
          <i class="bi bi-exclamation-circle me-2 text-danger"></i>Eliminar actuació
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2 small">Segur que vols eliminar aquesta actuació?</p>
        <div class="border rounded mb-4 p-2 bg-kinosonik small">
          <div class="row"><div class="col-12 text-muted">Artista: <strong id="delActName">—</strong></div></div>
        </div>
        <p class="mb-2 small">Aquesta acció és <strong>definitiva</strong> i esborrarà:</p>
        <ul class="small mb-3">
          <li>Tots els documents associats (rider, contra-rider, final...)</li>
          <li>Entrades i resultats d’IA</li>
          <li>Registres de negociació i seguiment</li>
        </ul>
        <hr>
        <label for="confirmActWord" class="form-label small text-secondary">Escriu <code>ELIMINA</code> per confirmar:</label>
        <input type="text" class="form-control" id="confirmActWord" placeholder="ELIMINA" autocomplete="off">
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel·la</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="submitDeleteAct()">
          <i class="bi bi-trash me-1"></i> Elimina definitivament
        </button>
      </div>
    </div>
  </div>
</div>

<form id="frmDeleteAct" method="post" action="<?= h(BASE_PATH) ?>act_delete.php" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
  <input type="hidden" name="id" id="delActId" value="">
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('modalDeleteAct');
  if (!modalEl) return;
  modalEl.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    const id = btn?.getAttribute('data-act-id') || '';
    const nom = btn?.getAttribute('data-act-name') || '—';
    document.getElementById('delActId').value = id;
    document.getElementById('delActName').textContent = nom;
    document.getElementById('confirmActWord').value = '';
  });
});
async function submitDeleteAct() {
  const ok = document.getElementById('confirmActWord').value.trim().toUpperCase() === 'ELIMINA';
  if (!ok) { alert('Escriu ELIMINA per confirmar.'); return; }

  const form = document.getElementById('frmDeleteAct');
  const res  = await fetch(form.action, { method: 'POST', body: new FormData(form) });

  let json;
  try {
    json = await res.json(); // només aquesta línia, no res.text()
  } catch (err) {
    console.error('Resposta no JSON:', err);
    alert('Error intern del servidor.');
    return;
  }

  if (json.ok) {
    sessionStorage.setItem('ks_flash', JSON.stringify({
      cls: 'success',
      text: 'Actuació eliminada correctament.'
    }));
    location.reload();
  } else {
    alert('Error: ' + (json.error || json.message || 'delete_failed'));
  }
}

</script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const msg = sessionStorage.getItem("ks_flash");
  if (msg) {
    sessionStorage.removeItem("ks_flash");
    const toast = document.createElement("div");
    toast.className = "position-fixed bottom-0 end-0 p-3";
    toast.innerHTML = `
      <div class="toast align-items-center text-bg-success border-0 show" role="alert">
        <div class="d-flex">
          <div class="toast-body">${msg}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
      </div>`;
    document.body.appendChild(toast);
    new bootstrap.Toast(toast.querySelector(".toast")).show();
  }
});
</script>
<!-- JS Canvi ordre -->
 <script>
document.addEventListener('change', e => {
  if (!e.target.classList.contains('reorder-select')) return;
  const id = e.target.dataset.id;
  const ordre = e.target.value;
  fetch('act_reorder.php', {
    method: 'POST',
    body: new URLSearchParams({ id, ordre, csrf: '<?= h($_SESSION['csrf']) ?>' })
  })
  .then(r => r.json())
  .then(j => {
    if (j.ok) location.reload();
    else alert(j.error || 'Error canviant ordre');
  });
});
</script>

<?php require_once __DIR__ . '/parts/footer.php'; ?>