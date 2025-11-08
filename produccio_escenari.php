<?php
// produccio_escenari.php — Detall d'un escenari: dies i accions
// Accés: productor o admin. Requereix: Event_Stages, Events, Stage_Days, Stage_Day_Acts, Stage_Templates (opcional)

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
function fmt_d(?string $d): string { if(!$d) return ''; $t=strtotime($d);  return $t?date('d/m/Y',$t):''; }

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');
$stageId = (int)($_GET['id'] ?? 0);
if ($stageId <= 0) { http_response_code(400); exit('bad_request'); }

// ── Carrega escenari + event i valida propietari ─────────────────────
$sqlStage = <<<SQL
SELECT s.id, s.event_id, s.nom, s.data_inici, s.data_fi,
       e.nom AS event_nom, e.owner_user_id, e.is_open_ended,
       e.data_inici AS event_inici, e.data_fi AS event_fi
FROM Event_Stages s
JOIN Events e ON e.id = s.event_id
WHERE s.id = :sid
SQL;
$st = $pdo->prepare($sqlStage); $st->execute([':sid'=>$stageId]);
$stage = $st->fetch(PDO::FETCH_ASSOC);
if(!$stage){ http_response_code(404); exit('not_found'); }
if(!$isAdmin && (int)$stage['owner_user_id'] !== $uid){ http_response_code(403); exit('forbidden'); }

// ── Plantilla d'escenari (opcional) ───────────────────────────────────
$tpl = null; $hasTemplate = false;
try {
  $pt = $pdo->prepare('SELECT id, title FROM Stage_Templates WHERE stage_id=:sid ORDER BY ts DESC LIMIT 1');
  $pt->execute([':sid'=>$stageId]);
  $tpl = $pt->fetch(PDO::FETCH_ASSOC);
  $hasTemplate = (bool)$tpl;
} catch (Throwable $e) {
  // Taula opcional
}

// ── Dies de l'escenari amb mètriques ─────────────────────────────────
$sqlDays = <<<SQL
SELECT d.id, d.dia,
       COUNT(a.id)                                       AS num_bandes,
       SUM(a.final_doc_id IS NOT NULL)                   AS num_ok,
       SUM(a.needs_contrarider_refresh = 1)              AS num_refresh,
       MIN(a.ia_precheck_score)                          AS min_score
FROM Stage_Days d
LEFT JOIN Stage_Day_Acts a ON a.stage_day_id = d.id
WHERE d.stage_id = :sid
GROUP BY d.id
ORDER BY d.dia
SQL;

$showPast = ($_GET['past'] ?? '0') === '1';
$whereExtra = $showPast ? '' : 'AND d.dia >= CURDATE()';
$sqlDays = <<<SQL
SELECT d.id, d.dia,
       COUNT(a.id) AS num_bandes,
       SUM(a.final_doc_id IS NOT NULL) AS num_ok,
       SUM(a.needs_contrarider_refresh = 1) AS num_refresh,
       MIN(a.ia_precheck_score) AS min_score
FROM Stage_Days d
LEFT JOIN Stage_Day_Acts a ON a.stage_day_id = d.id
WHERE d.stage_id = :sid
  $whereExtra
GROUP BY d.id
ORDER BY d.dia
SQL;

$pd = $pdo->prepare($sqlDays); $pd->execute([':sid'=>$stageId]);
$days = $pd->fetchAll(PDO::FETCH_ASSOC);

/* --- Paginació per mesos simple ----*/
$months = [];
foreach ($days as $d) {
  $m = date('Y-m', strtotime($d['dia']));
  $months[$m][] = $d;
}

$currentMonth = $_GET['m'] ?? array_key_first($months);


/* ── Head + Nav ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>

<div class="container w-75">
  <!-- Títol -->
  <div class="d-flex justify-content-between align-items-center mb-2 border-bottom border-1 border-secondary ">
    <h4 class="text-start">
      <i class="bi bi-gear-wide-connected me-2"></i>&nbsp;&nbsp;
      <?= h($stage['event_nom']) ?> <i class="bi bi-arrow-right"></i> <?= h($stage['nom']) ?>
    </h4>
    <div class="btn-group d-flex" role="group" aria-label="">
      <button type="button" class="btn btn-primary btn-sm"
        onclick="window.location.href='<?= h(BASE_PATH) ?>stage_edit.php?id=<?= (int)$stage['id'] ?>&return_to=escenari';"
        data-bs-toggle="tooltip" data-bs-title="Editar escenari" aria-label="Editar escenari">
        <i class="bi bi-pencil-square"></i> Editar escenari
      </button>
      <!-- Eliminar escenari -->
      <button
        type="button"
        class="btn btn-danger btn-sm text-nowrap"
        data-bs-toggle="modal"
        data-bs-target="#modalDeleteStage"
        data-stage-id="<?= (int)$stage['id'] ?>"
        data-stage-name="<?= htmlspecialchars((string)$stage['nom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <i class="bi bi-trash"></i>
      </button>
    </div>
  </div>

  <!-- Breadcumb Producció  -->
  <div class="w-100 mb-2 mt-2 small bc-kinosonik">
      <nav aria-label="breadcrumb">
          <ol class="breadcrumb">
              <li><a href="<?= h(BASE_PATH) ?>espai.php?seccio=produccio">Els teus esdeveniments</a></li>
              <li><a href="<?= h(BASE_PATH) ?>event.php?id=<?= (int)$stage['event_id'] ?>"><?= h($stage['event_nom']) ?></a></li>
              <li class="active"><?= h($stage['nom']) ?></li>
          </ol>
      </nav>
  </div>

  <!-- Subinfo -->
  <div class="d-flex mb-1 small text-secondary">
    <div class="w-100">
      <div class="row row-cols-auto justify-content-start text-start">
        <div class="col">ID: <span class="text-light"><?= (int)$stage['id'] ?></span></div>
        <div class="col">
          Dates event:
          <span class="text-light">
            <?php if ((int)$stage['is_open_ended'] === 1): ?>
              <?= fmt_d($stage['event_inici']) ?> → ∞
            <?php elseif ($stage['event_inici'] === $stage['event_fi']): ?>
              <?= fmt_d($stage['event_inici']) ?>
            <?php else: ?>
              <?= fmt_d($stage['event_inici']) ?> → <?= fmt_d($stage['event_fi']) ?>
            <?php endif; ?>
          </span>
        </div>

        <div class="col">
          Dates escenari:
          <span class="text-light">
            <?php if ($stage['data_inici'] === $stage['data_fi']): ?>
              <?= fmt_d($stage['data_inici']) ?>
            <?php else: ?>
              <?= fmt_d($stage['data_inici']) ?> → <?= $stage['data_fi'] ? fmt_d($stage['data_fi']) : '∞' ?>
            <?php endif; ?>
          </span>
        </div>

        <div class="col">
          Plantilla:
          <?php if ($hasTemplate): ?>
            <a href="<?= h(BASE_PATH) ?>stage_template_edit.php?stage_id=<?= (int)$stage['id'] ?>"><?= h($tpl['title']) ?></a>
          <?php else: ?>
            <a href="<?= h(BASE_PATH) ?>stage_template_edit.php?stage_id=<?= (int)$stage['id'] ?>">Sense plantilla</a>
          <?php endif; ?>
        </div>

        <div class="col d-flex ">
          <?php $showPast = ($_GET['past'] ?? '0') === '1'; ?>
          
          <div class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" id="showPastSwitch"
            <?= $showPast ? 'checked' : '' ?>
            onchange="location.href='?id=<?= (int)$stageId ?>&past=' + (this.checked ? '1' : '0');">
            <label for="showPastSwitch" class="form-label me-2 mb-0">Incloure dies passats</label>
          </div>
        </div>


      </div>
    </div>
  </div>

  <!-- Taula de dies -->
  <div class="d-flex align-items-center mb-2 mt-4">
    <h2 class="h5 mb-0">Dies programats</h2>
  </div>
  <!-- Paginació per anys -->
  <div class="mb-2">
  <select id="yearSelector" class="form-select form-select-sm w-auto d-inline-block"
          onchange="location.href='?id=<?= (int)$stageId ?>&past=<?= $showPast?1:0 ?>&y=' + this.value;">
    <?php
      $years = array_unique(array_map(fn($d) => date('Y', strtotime($d['dia'])), $days));
      sort($years);
      $currentYear = $_GET['y'] ?? date('Y');
    ?>
    <?php foreach ($years as $y): ?>
      <option value="<?= h($y) ?>" <?= $y===$currentYear?'selected':'' ?>>
        <?= h($y) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Fi paginació -->
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th class="text-center text-light">Dia</th>
          <th class="text-center text-light">Bandes</th>
          <th class="text-center text-light">OK</th>
          <th class="text-center text-light">Flags</th>
          <th class="text-end text-light">Accions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($days)): ?>
          <tr>
            <td colspan="5" class="text-center text-body-secondary py-2">— <?= h(__('no_results') ?: 'Sense resultats') ?> —</td>
          </tr>
          <?php else: foreach ($months[$currentMonth] ?? [] as $d): 
          $today = date('Y-m-d');
          $border = '';
          if ($d['dia'] < $today) $border = 'border-left: 4px solid #aa0000ff';
          elseif ($d['dia'] === $today) $border = 'border-left: 4px solid #ffe100ff';
          ?>
          <tr>
            <td class="text-center"  style="<?= $border ?>"><a href="<?= h(BASE_PATH) ?>stage_day_detail.php?id=<?= (int)$d['id'] ?>"><?= fmt_d($d['dia']) ?></a></td>
            <td class="text-center"><?= (int)$d['num_bandes'] ?></td>
            <td class="text-center"><?= (int)$d['num_ok'] ?></td>
            <td class="text-center">
              <?php if ((int)$d['num_refresh'] > 0): ?>
                <span class="badge text-bg-warning" title="Contra a refrescar">refresh <?= (int)$d['num_refresh'] ?></span>
              <?php endif; ?>
              <?php if (!is_null($d['min_score'])): ?>
                <?php $cls = ((int)$d['min_score'] < 60) ? 'danger' : (((int)$d['min_score'] <= 80) ? 'warning' : 'success'); ?>
                <span class="badge text-bg-<?= $cls ?>" title="IA precheck mínima">IA <?= (int)$d['min_score'] ?></span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <div class="btn-group btn-group-sm flex-nowrap" role="group">
                <button type="button" class="btn btn-primary btn-sm"
                  onclick="window.location.href='<?= h(BASE_PATH) ?>stage_day_detail.php?id=<?= (int)$d['id'] ?>';"
                  data-bs-toggle="tooltip" data-bs-title="Obrir dia" aria-label="Obrir dia">
                  <i class="bi bi-box-arrow-in-right"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" <?= $hasTemplate ? '' : 'disabled' ?>
                  onclick="window.location.href='<?= h(BASE_PATH) ?>contra_generate.php?stage_day_id=<?= (int)$d['id'] ?>';"
                  data-bs-toggle="tooltip" data-bs-title="Genera contra des de plantilla" aria-label="Genera contra des de plantilla">
                  <i class="bi bi-magic"></i>
                </button>
                <!-- Eliminar DIA -->
                <button
                  type="button"
                  class="btn btn-danger btn-sm text-nowrap"
                  data-bs-toggle="modal"
                  data-bs-target="#modalDeleteDay"
                  data-day-id="<?= (int)$d['id'] ?>"
                  data-day-date="<?= fmt_d($d['dia']) ?>"
                  title="Elimina dia">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
          <tr>
            <td colspan="7" class="text-end text-body-secondary py-2 border-0">
              <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalNewDay">
                <i class="bi bi-plus-circle"></i> Afegeix dia
              </button>
            </td>
          </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ────────────────────────────── Modal: Eliminar dia ────────────────────────────── -->
<div class="modal fade" id="modalDeleteDay" tabindex="-1" aria-labelledby="modalDeleteDayLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-light border border-danger-subtle">
      <div class="modal-header border-0">
        <h5 class="modal-title" id="modalDeleteDayLabel">
          <i class="bi bi-exclamation-circle me-2 text-danger"></i>Eliminar dia
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
      </div>

      <div class="modal-body">
        <p class="mb-2 small">Segur que vols eliminar aquest dia de l’escenari?</p>
        <div class="border rounded mb-4 p-2 bg-kinosonik small">
          <div class="row">
            <div class="col-12 text-muted">Data: <strong id="delDayDate">—</strong></div>
          </div>
        </div>
        <p class="mb-2 small">Aquesta acció és <strong>definitiva</strong> i esborrarà:</p>
        <ul class="small mb-3">
          <li>Totes les actuacions d’aquest dia</li>
          <li>Documents associats (riders, contra-riders, finals...)</li>
          <li>Resultats i registres d’IA (prechecks...)</li>
        </ul>
        <hr>
        <label for="confirmDayWord" class="form-label small text-secondary">
          Escriu <code>ELIMINA</code> per confirmar:
        </label>
        <input type="text" class="form-control" id="confirmDayWord" placeholder="ELIMINA" autocomplete="off">
      </div>

      <div class="modal-footer border-0">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel·la</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="submitDeleteDay()">
          <i class="bi bi-trash me-1"></i> Elimina definitivament
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Formulari ocult -->
<form id="frmDeleteDay" method="post" action="<?= h(BASE_PATH) ?>stage_day_delete.php" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
  <input type="hidden" name="id" id="delDayId" value="">
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('modalDeleteDay');
  if (!modalEl) return;

  modalEl.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    const id  = btn?.getAttribute('data-day-id') || '';
    const date = btn?.getAttribute('data-day-date') || '—';
    document.getElementById('delDayId').value = id;
    document.getElementById('delDayDate').textContent = date;
    document.getElementById('confirmDayWord').value = '';
  });
});

async function submitDeleteDay() {
  const ok = document.getElementById('confirmDayWord').value.trim().toUpperCase() === 'ELIMINA';
  if (!ok) { alert('Escriu ELIMINA per confirmar.'); return; }

  const form = document.getElementById('frmDeleteDay');
  const res  = await fetch(form.action, { method: 'POST', body: new FormData(form) });
  try {
    const json = await res.json();
    if (json.ok) {
      sessionStorage.setItem('ks_flash', JSON.stringify({
        cls: 'success',
        text: 'Dia eliminat correctament.'
      }));
      location.reload();
    } else {
      alert('Error: ' + (json.message || json.error || 'delete_failed'));
    }
  } catch {
    alert('Error inesperat eliminant el dia.');
  }
}
</script>

<!-- ────────────────────────────── Modal: Afegir dia ────────────────────────────── -->
<div class="modal fade" id="modalNewDay" tabindex="-1" aria-labelledby="" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card border-1 shadow">
      <form id="formNewDay" method="post" action="stage_day_new_json.php">
      <div class="modal-header card-header bg-kinosonik centered">
        <h6><i class="bi bi-plus-circle me-2"></i>Nou dia</h6>
        <div class="btn-group ms-2">
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
        </div>
      </div>

      <div class="modal-body card-body">
        <div id="modalNewDayAlert" class="alert alert-warning d-none small"></div>
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
        <input type="hidden" name="stage_id" value="<?= (int)$stage['id'] ?>">
        <div class="mb-3 text-center">
   <label for="dia" class="form-label d-block">Data</label>
   <input type="date" class="form-control form-control-sm d-inline-block w-auto text-center"
     id="dia" name="dia" required
     <?php if (!empty($stage['data_inici'])): ?>min="<?= h(date('Y-m-d', strtotime($stage['data_inici']))) ?>"<?php endif; ?>
     <?php if (!empty($stage['data_fi'])): ?>max="<?= h(date('Y-m-d', strtotime($stage['data_fi']))) ?>"<?php endif; ?>>
   <div class="form-text text-secondary mt-2">
     Període permès:
     <strong>
       <?= $stage['data_inici'] ? h(date('d/m/Y', strtotime($stage['data_inici']))) : 'sense límit inicial' ?>
       →
       <?= $stage['data_fi'] ? h(date('d/m/Y', strtotime($stage['data_fi']))) : 'sense límit final' ?>
     </strong>
   </div>
 </div>
    </div>
    <div class="modal-footer border-0">    
      <button class="btn btn-sm btn-primary" type="submit">
        <i class="bi bi-plus-circle me-1"></i> <?= h(__('common.save') ?: 'Desa') ?>
      </button>
      <button class="btn btn-sm btn-secondary" type="reset" data-bs-dismiss="modal">
        <i class="bi bi-x-circle me-1"></i> <?= h(__('common.cancel') ?: 'Cancela') ?>
      </button>
    </form>
    </div>
  </div>
</div>

<script>
document.getElementById('formNewDay')?.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const form = ev.target;
  const alertBox = document.getElementById('modalNewDayAlert');
  alertBox.classList.add('d-none');
  alertBox.textContent = '';

  const data = new FormData(form);
  const resp = await fetch(form.action, {
    method: 'POST',
    headers: { 'Accept': 'application/json' },
    body: data
  });

  const text = await resp.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    alertBox.classList.remove('d-none');
    alertBox.textContent = 'Error intern del servidor.';
    return;
  }

  if (json.status === 'ok') {
    bootstrap.Modal.getInstance(document.getElementById('modalNewDay')).hide();
    location.reload();
  } else {
    alertBox.classList.remove('d-none');
    alertBox.textContent = json.msg || 'Error desconegut.';
  }
});
</script>

<!-- ────────────────────────────── Modal: Eliminar escenari ────────────────────────────── -->
<div class="modal fade" id="modalDeleteStage" tabindex="-1" aria-labelledby="modalDeleteStageLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-light border border-danger-subtle">
      <div class="modal-header border-0">
        <h5 class="modal-title" id="modalDeleteStageLabel">
          <i class="bi bi-exclamation-circle me-2 text-danger"></i>Eliminar escenari
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
      </div>

      <div class="modal-body">
        <p class="mb-2 small">Segur que vols eliminar aquest escenari?</p>
        <div class="border rounded mb-4 p-2 bg-kinosonik small">
          <div class="row">
            <div class="col-12 text-muted">Escenari: <strong id="delStageName">—</strong></div>
          </div>
        </div>
        <p class="mb-2 small">
          Aquesta acció és <strong>definitiva</strong> i esborrarà:
        </p>
        <ul class="small mb-3">
          <li>Tots els dies vinculats a aquest escenari</li>
          <li>Totes les actuacions dels dies</li>
          <li>Documents associats (riders, contra-riders, finals...)</li>
          <li>Resultats d’IA (prechecks, informes, registres...)</li>
        </ul>
        <hr>
        <label for="confirmStageWord" class="form-label small text-secondary">
          Escriu <code>ELIMINA</code> per confirmar:
        </label>
        <input type="text" class="form-control" id="confirmStageWord" placeholder="ELIMINA" autocomplete="off">
      </div>

      <div class="modal-footer border-0">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel·la</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="submitDeleteStage()">
          <i class="bi bi-trash me-1"></i> Elimina definitivament
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Formulari ocult -->
<form id="frmDeleteStage" method="post" action="<?= h(BASE_PATH) ?>stage_delete.php" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
  <input type="hidden" name="id" id="delStageId" value="">
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('modalDeleteStage');
  if (!modalEl) return;

  modalEl.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    const id  = btn?.getAttribute('data-stage-id') || '';
    const nom = btn?.getAttribute('data-stage-name') || '—';
    document.getElementById('delStageId').value = id;
    document.getElementById('delStageName').textContent = nom;
    document.getElementById('confirmStageWord').value = '';
  });
});

async function submitDeleteStage() {
  const ok = document.getElementById('confirmStageWord').value.trim().toUpperCase() === 'ELIMINA';
  if (!ok) { alert('Escriu ELIMINA per confirmar.'); return; }

  const form = document.getElementById('frmDeleteStage');
  const res  = await fetch(form.action, { method: 'POST', body: new FormData(form) });
  try {
    const json = await res.json();
    if (json.ok) {
      sessionStorage.setItem('ks_flash', JSON.stringify({
        cls: 'success',
        text: 'Escenari eliminat correctament.'
      }));
      window.location.href = 'event.php?id=<?= (int)$stage['event_id'] ?>';
    } else {
      alert('Error: ' + (json.message || json.error || 'delete_failed'));
    }
  } catch {
    alert('Error inesperat eliminant l’escenari.');
  }
}
</script>


<?php
/* ── Footer ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/footer.php';
?>
