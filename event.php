<?php
// event.php — Resum d'un event i els seus escenaris
// Accés: productor o admin. Requereix: Events, Event_Stages, Stage_Days, Stage_Day_Acts

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
function fmt_dt(?string $ts): string { if(!$ts) return ''; $t=strtotime($ts); return $t?date('d/m/Y H:i',$t):''; }
function fmt_d(?string $d): string { if(!$d) return ''; $t=strtotime($d);  return $t?date('d/m/Y',$t):''; }
function fmt_d_range(?string $di, ?string $df): string {
  if ($di && $df) {
    $s1 = substr((string)$di, 0, 10);
    $s2 = substr((string)$df, 0, 10);
    if ($s1 === $s2) return fmt_d($di);                // mateix dia → mostra només un cop
    return fmt_d($di) . ' → ' . fmt_d($df);            // rang normal
  }
  if ($di) return fmt_d($di) . ' → ∞';                 // obert
  if ($df) return '— → ' . fmt_d($df);                 // només fi (raro, però segur)
  return '—';
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');
$eventId = (int)($_GET['id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('bad_request'); }

// ── Carrega event amb control de propietari ───────────────────────────
$sqlEvent = 'SELECT id, owner_user_id, nom, is_open_ended, data_inici, data_fi, estat, ts_updated FROM Events WHERE id=:id';
$st = $pdo->prepare($sqlEvent); $st->execute([':id'=>$eventId]);
$event = $st->fetch(PDO::FETCH_ASSOC);
if (!$event) { http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$event['owner_user_id'] !== $uid) { http_response_code(403); exit('forbidden'); }

// ── Escenaris de l'event amb mètriques ───────────────────────────────
$sqlStages = <<<SQL
SELECT
  s.id,
  s.nom           AS nom,
  s.data_inici    AS data_inici,
  s.data_fi       AS data_fi,
  s.notes         AS notes,
  COUNT(DISTINCT d.id) AS dies,
  COUNT(a.id)          AS bandes,
  SUM(CASE WHEN a.final_doc_id IS NOT NULL THEN 1 ELSE 0 END) AS ok_bandes
FROM Event_Stages s
LEFT JOIN Stage_Days d     ON d.stage_id = s.id
LEFT JOIN Stage_Day_Acts a ON a.stage_day_id = d.id
WHERE s.event_id = :eid
GROUP BY s.id, s.nom, s.data_inici, s.data_fi, s.notes
ORDER BY s.nom
SQL;
$ps = $pdo->prepare($sqlStages); $ps->execute([':eid'=>$eventId]);
$stages = $ps->fetchAll(PDO::FETCH_ASSOC);

/* ── Head + Nav ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';

?>

<div class="container w-75">
    <!-- Avisos emergents -->
    <div id="flash-area" class="mt-2"></div>
    <!-- Títol Secció -->
    <div class="d-flex justify-content-between align-items-center mb-2 border-bottom border-1 border-secondary ">
        <h4 class="text-start">
        <i class="bi bi-gear-wide-connected me-2"></i>&nbsp;&nbsp;
        <?= h($event['nom']) ?>
        </h4>
        <div class="btn-group d-flex" role="group" aria-label="">
            <button 
                type="button" 
                class="btn btn-primary btn-sm"    
                onclick="window.location.href='<?= h(BASE_PATH) ?>share_pack.php?scope=event&id=<?= (int)$event['id'] ?>';"
                data-bs-toggle="tooltip" data-bs-title="Compartir"
                aria-label="Compartir">
                <i class="bi bi-share"></i>
            </button>
            <button 
                type="button" 
                class="btn btn-primary btn-sm"    
                onclick="window.location.href='<?= BASE_PATH ?>event_edit.php?id=<?= (int)$event['id'] ?>&ret=<?= rawurlencode($_SERVER['REQUEST_URI'] ?? (BASE_PATH.'event.php?id='.(int)$event['id'])) ?>';"
                data-bs-toggle="tooltip" data-bs-title="Editar event"
                aria-label="Editar event">
                <i class="bi bi-pencil-square"></i> Editar event
            </button>
        </div>
    </div>

    <!-- Breadcumb Producció  -->
    <div class="w-100 mb-2 mt-2 small bc-kinosonik">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li><a href="<?= h(BASE_PATH) ?>espai.php?seccio=produccio">Els teus esdeveniments</a></li>
                <li class="active"><?= h($event['nom']) ?></li>
            </ol>
        </nav>
    </div>

    <!-- Llistat informatiu sota títol -->
    <div class="d-flex mb-1 small text-secondary">
        <div class="w-100">
            <div class="row row-cols-auto justify-content-start text-start">
                <div class="col">ID: <span class="text-light"><?= (int)$event['id'] ?></span></div>
                <div class="col">
                    Data inici → fi: 
                    <span class="text-light">
                        <?php if ((int)$event['is_open_ended'] === 1): ?>
                            <?= fmt_d($event['data_inici']) ?> → ∞
                        <?php else: ?>
                            <?= fmt_d($event['data_inici']) ?> → <?= fmt_d($event['data_fi']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="col">
                    Estat: 
                    <span class=" text-<?= $event['estat']==='actiu'?'success':($event['estat']==='tancat'?'secondary':'warning') ?>"><?= h($event['estat']) ?></span>
                </div>
                <div class="col">Últ. actualització: <span class="text-light"><?= fmt_dt($event['ts_updated']) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Taula normal w75 -->
    <div class="d-flex align-items-center mb-2 mt-4">
        <h2 class="h5 mb-0">Escenaris</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-dark">
                <tr>
                    <th class="text-center text-light">ID</th>
                    <th class="text-start text-light">Nom</th>
                    <th class="text-center text-light">Data inici → fi</th>
                    <th class="text-center text-light">Dies</th>
                    <th class="text-center text-light">Bandes</th>
                    <th class="text-center text-light">Ok</th>
                    <th class="text-center text-light">Notes</th>
                    <th class="text-start text-light"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stages)): ?>
                    <tr><td colspan="8" class="text-center text-body-secondary py-2">— <?= h(__('no_results') ?: 'Sense resultats') ?> —</td></tr>
                <?php else: foreach ($stages as $s): ?>
                <tr>
                    <td class="text-center"><?= (int)$s['id'] ?></td>
                    <td class="text-truncate"><a href='<?= h(BASE_PATH) ?>produccio_escenari.php?id=<?= (int)$s['id'] ?>'><?= h($s['nom']) ?></a></td>
                    <td class="text-center">
                        <?= fmt_d_range($s['data_inici'] ?? null, $s['data_fi'] ?? null) ?>
                    </td>
                    <td class="text-center"><?= (int)$s['dies'] ?></td>
                    <td class="text-center"><?= (int)$s['bandes'] ?></td>
                    <td class="text-center"><?= (int)$s['ok_bandes'] ?></td>
                    <td class="text-center">
                      <?php $__notes = trim((string)($s['notes'] ?? '')); if ($__notes !== ''): ?>
                        <button
                          type="button"
                          class="btn btn-outline btn-sm"
                          data-bs-toggle="modal"
                          data-bs-target="#modalStageNotes"
                          data-stage-name="<?= h($s['nom']) ?>"
                          data-stage-notes="<?= htmlspecialchars($__notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                          title="Veure notes">
                          <i class="bi bi-journal-text"></i>
                        </button>
                      <?php else: ?>
                        <span class="text-body-secondary">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <!-- Grup de botons -->
                         <div class="btn-group me-2 flex-nowrap" role="group">
                            <button 
                                type="button" 
                                class="btn btn-primary btn-sm"
                                onclick="window.location.href='<?= h(BASE_PATH) ?>produccio_escenari.php?id=<?= (int)$s['id'] ?>';"
                                data-bs-toggle="tooltip" data-bs-title="Obrir escenari"
                                aria-label="Obrir escenari">
                                <i class="bi bi-box-arrow-in-right"></i>
                            </button>
                            <!-- Editar escenari -->
                             <button 
                              type="button"
                              class="btn btn-primary btn-sm"
                              onclick="window.location.href='<?= h(BASE_PATH) ?>stage_edit.php?id=<?= (int)$s['id'] ?>&return_to=event';"
                              data-bs-toggle="tooltip" data-bs-title="Editar escenari"
                              aria-label="Editar escenari">
                              <i class="bi bi-pencil-square"></i>
                            </button>
                            <!-- Eliminar escenari -->
                            <button
                                type="button"
                                class="btn btn-danger btn-sm text-nowrap"
                                data-bs-toggle="modal"
                                data-bs-target="#modalDeleteStage"
                                data-stage-id="<?= (int)$s['id'] ?>"
                                data-stage-name="<?= htmlspecialchars((string)$s['nom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                title="Esborra escenari">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; 
                endif?>
                <tr>
                    <td colspan="8" class="text-end text-body-secondary py-2 border-0">
                        <a class="btn btn-sm btn-primary" href="<?= h(BASE_PATH) ?>stage_new.php?event_id=<?= (int)$event['id'] ?>">
                            <i class="bi bi-plus-circle"></i> Afegeix escenari
                        </a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</div>

<!-- Modal: Notes d'escenari (reutilitzat) -->
 <div class="modal fade" id="modalStageNotes" tabindex="-1" aria-labelledby="modalStageNotesLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-light border border-primary-subtle">
      <div class="modal-header border-bottom border-primary-subtle centered">
        <h5 class="modal-title text-center" id="modalStageNotesLabel">
          <i class="bi bi-plus-circle me-2 text-primary"></i>Notes: <span id="stageNotesTitle">—</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
      </div>

      <div class="modal-body small">
        <div id="stageNotesText" class="small" style="white-space: pre-wrap;"></div>
      </div>

      <div class="modal-footer border-0">
        
            
            <button class="btn btn-sm btn-secondary" type="button" data-bs-dismiss="modal">
                <i class="bi bi-x-circle me-1"></i> <?= h(__('common.tanca') ?: 'Tancar') ?>
            </button>
        
      </div>
    </div>
  </div>
</div>


<!-- Modal: Eliminar escenari -->
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
          <li>Tots els dies vinculats</li>
          <li>Totes les actuacions i rondes</li>
          <li>Enllaços a documents associats</li>
          <li>Entrades d’IA (prechecks associats)</li>
        </ul>
        <hr>
        <label for="confirmStage" class="form-label small text-secondary">Escriu <code>ELIMINA</code> per confirmar:</label>
        <input type="text" class="form-control" id="confirmStage" placeholder="ELIMINA" autocomplete="off">
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


<!-- Formulari ocult: POST stage_delete.php -->
<form id="frmDeleteStage" method="post" action="<?= h(BASE_PATH) ?>stage_delete.php" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
  <input type="hidden" name="id" id="delStageId" value="">
</form>

<!-- JS: Modal + submit elim. escenari + flash -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('modalDeleteStage');
  if (modalEl) {
    modalEl.addEventListener('show.bs.modal', (ev) => {
      const btn = ev.relatedTarget;
      const id  = btn?.getAttribute('data-stage-id') || '';
      const nom = btn?.getAttribute('data-stage-name') || '—';
      document.getElementById('delStageId').value = id;
      document.getElementById('delStageName').textContent = nom;
      const c = document.getElementById('confirmStage'); if (c) c.value = '';
    });
  }
});

async function submitDeleteStage() {
  const ok = (document.getElementById('confirmStage').value || '').trim().toUpperCase() === 'ELIMINA';
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
      location.reload();
    } else {
      alert('Error: ' + (json.message || json.error || 'delete_failed'));
    }
  } catch {
    alert('Error inesperat eliminant l’escenari.');
  }
}

// Modal notes: emplena contingut
document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('modalStageNotes');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', (ev) => {
    const btn   = ev.relatedTarget;
    const name  = btn?.getAttribute('data-stage-name')  || '—';
    const notes = btn?.getAttribute('data-stage-notes') || '';
    const title = document.getElementById('stageNotesTitle');
    const text  = document.getElementById('stageNotesText');
    if (title) title.textContent = name;
    if (text)  text.textContent  = notes; // segur contra HTML
  });
});

// Pintar flash (reutilitzat)
document.addEventListener('DOMContentLoaded', () => {
  const raw = sessionStorage.getItem('ks_flash');
  if (raw) {
    try {
      const f = JSON.parse(raw);
      const area = document.getElementById('flash-area') || document.body;
      const div  = document.createElement('div');
      div.className = `alert alert-${f.cls} alert-dismissible fade show k-card`;
      div.setAttribute('role', 'alert');
      div.innerHTML = `
        <i class="bi bi-check-circle me-1"></i>${f.text}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tanca"></button>
      `;
      area.prepend(div);
    } catch (_) {
      /* ignore */
    } finally {
      sessionStorage.removeItem('ks_flash');
    }
  }
});
</script>

<?php
/* ── Footer ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/footer.php';
?>