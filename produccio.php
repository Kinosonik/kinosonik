<?php
// produccio.php — Pàgina d'aterratge del productor (arrel)
// Requisits: taules Events, Event_Stages, Stage_Days, Stage_Day_Acts, v_stage_day_ok (opcional)
// Format dates: dd/mm/aaaa HH:mm

declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';

// ───────────────────────── Seguretat ─────────────────────────
// Ajusta rols segons el teu projecte. Aquí es permet 'productor' o 'admin'.
ks_require_role('productor','admin');

// ───────────────────────── Helpers locals ─────────────────────────
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
function fmt_dt(?string $ts): string {
  if (!$ts) return '';
  $t = strtotime($ts);
  return $t ? date('d/m/Y H:i', $t) : '';
}
function fmt_d(?string $d): string {
  if (!$d) return '';
  $t = strtotime($d);
  return $t ? date('d/m/Y', $t) : '';
}
function badge_state(string $s): string {
  // Map senzill d'estats de negociació → classes Bootstrap
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

$uid = (int)($_SESSION['user_id'] ?? 0);
$pdo = db();

// ───────────────────────── Consultes ─────────────────────────
// 1) Resum d'events de l'usuari
$sqlEvents = <<<SQL
SELECT
  e.id, e.nom, e.is_open_ended, e.data_inici, e.data_fi, e.estat, e.ts_updated,

  /* Comptadors segurs (no amaguen files) */
  (SELECT COUNT(*) FROM Event_Stages s WHERE s.event_id = e.id) AS num_escenaris,

  (SELECT COUNT(*) 
     FROM Stage_Days d 
     JOIN Event_Stages s ON s.id = d.stage_id
    WHERE s.event_id = e.id) AS dies_programats,

  (SELECT COUNT(*) 
     FROM Stage_Day_Acts a
     JOIN Stage_Days d   ON d.id = a.stage_day_id
     JOIN Event_Stages s ON s.id = d.stage_id
    WHERE s.event_id = e.id) AS num_bandes,

  /* Primera/última data programada (retornen NULL si no hi ha dies) */
  (SELECT MIN(d.dia)
     FROM Stage_Days d 
     JOIN Event_Stages s ON s.id = d.stage_id
    WHERE s.event_id = e.id) AS primer_dia,

  (SELECT MAX(d.dia)
     FROM Stage_Days d 
     JOIN Event_Stages s ON s.id = d.stage_id
    WHERE s.event_id = e.id) AS ultim_dia

FROM Events e
WHERE e.owner_user_id = :uid   /* o (:is_admin=1 OR e.owner_user_id=:uid) si vols que admin vegi tot */
ORDER BY e.ts_updated DESC
LIMIT 100;
SQL;
$stEvents = $pdo->prepare($sqlEvents);
$stEvents->execute([':uid' => $uid]);
$events = $stEvents->fetchAll(PDO::FETCH_ASSOC);

// 2) Properes actuacions (7 dies)
$sqlNextActs = <<<SQL
SELECT
  e.id   AS event_id, e.nom AS event_nom,
  s.id   AS stage_id, s.nom AS stage_nom,
  d.id   AS stage_day_id, d.dia,
  a.id   AS act_id, a.ordre, a.artista_nom, a.negotiation_state
FROM Events e
JOIN Event_Stages s ON s.event_id = e.id
JOIN Stage_Days d   ON d.stage_id = s.id
LEFT JOIN Stage_Day_Acts a ON a.stage_day_id = d.id
WHERE e.owner_user_id = :uid
  AND d.dia >= CURDATE()
  AND d.dia <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
ORDER BY d.dia, s.nom, a.ordre
SQL;
$stNext = $pdo->prepare($sqlNextActs);
$stNext->execute([':uid' => $uid]);
$nextActs = $stNext->fetchAll(PDO::FETCH_ASSOC);

// 3) Alertes: contra a refrescar
$sqlRefresh = <<<SQL
SELECT e.id AS event_id, e.nom AS event_nom, s.nom AS stage_nom, d.dia, a.id AS act_id, a.artista_nom
FROM Stage_Day_Acts a
JOIN Stage_Days d   ON d.id = a.stage_day_id
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e       ON e.id = s.event_id
WHERE e.owner_user_id = :uid AND a.needs_contrarider_refresh = 1
ORDER BY d.dia
SQL;
$stRefresh = $pdo->prepare($sqlRefresh);
$stRefresh->execute([':uid' => $uid]);
$alertsRefresh = $stRefresh->fetchAll(PDO::FETCH_ASSOC);

// 4) Alertes: IA precheck risc
$sqlRisk = <<<SQL
SELECT e.id AS event_id, e.nom AS event_nom, d.dia, a.id AS act_id, a.artista_nom, a.ia_precheck_score
FROM Stage_Day_Acts a
JOIN Stage_Days d   ON d.id = a.stage_day_id
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e       ON e.id = s.event_id
WHERE e.owner_user_id = :uid AND a.ia_precheck_score IS NOT NULL
ORDER BY a.ia_precheck_score ASC
SQL;
$stRisk = $pdo->prepare($sqlRisk);
$stRisk->execute([':uid' => $uid]);
$riskActs = $stRisk->fetchAll(PDO::FETCH_ASSOC);

// 5) Dies amb OK incomplet (si la vista existeix)
$okDays = [];
try {
  $sqlOkDays = <<<SQL
  SELECT e.id AS event_id, e.nom AS event_nom, s.nom AS stage_nom, d.dia, v.num_bandes, v.num_ok
  FROM v_stage_day_ok v
  JOIN Stage_Days d   ON d.id = v.stage_day_id
  JOIN Event_Stages s ON s.id = d.stage_id
  JOIN Events e       ON e.id = s.event_id
  WHERE e.owner_user_id = :uid AND v.num_bandes > 0 AND v.ok_complet = 0
  ORDER BY d.dia
  SQL;
  $stOk = $pdo->prepare($sqlOkDays);
  $stOk->execute([':uid' => $uid]);
  $okDays = $stOk->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // vista no existent: ignora
}

// ───────────────────────── Sortida HTML ─────────────────────────
?>

<div class="container my-3">
    <!-- Avisos emergents -->
    <div id="flash-area" class="mt-2"></div>

    <!-- Títol -->
    <div class="d-flex justify-content-between align-items-center mb-2 border-bottom border-1 border-secondary ">
        <h4 class="text-start">
            <i class="bi bi-gear-wide-connected me-2"></i>&nbsp;&nbsp;
            Producció tècnica
        </h4>
    </div>

     <!-- Breadcumb Producció  -->
    <div class="w-100 mb-2 mt-2 small bc-kinosonik">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="active">Els teus esdeveniments</li>
            </ol>
        </nav>
    </div>

    <!-- Taula Esdeveniments -->
    <div class="d-flex align-items-center mb-2 mt-4">
        <h2 class="h5 mb-0">Els teus esdeveniments</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-dark">
                <tr class="text-primary">
                    <th class="text-center text-light">ID</th>
                    <th class="text-light">Nom</th>
                    <th class="text-center text-light">Data inici → fi</th>
                    <th class="text-center text-light">Escenaris</th>
                    <th class="text-center text-light">Dies</th>
                    <th class="text-center text-light">Bandes</th>
                    <th class="text-center text-light">Estat</th>
                    <th class="text-end text-light">Accions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td class="text-center text-body-secondary"><?= (int)$ev['id'] ?></td>
                    <td>
                      <a href='event.php?id=<?= (int)$ev['id'] ?>'><?= h($ev['nom']) ?><?= $ev['is_open_ended'] ? ' <span class="badge text-bg-secondary ms-2">obert</span>' : '' ?></a>
                    </td>
                    <td class="text-center">
                    <?php if ($ev['is_open_ended']): ?>
                        <?= fmt_d($ev['data_inici']) ?> → ∞
                    <?php else: ?>
                        <?= fmt_d($ev['data_inici']) ?> → <?= fmt_d($ev['data_fi']) ?>
                    <?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int)$ev['num_escenaris'] ?></td>
                    <td class="text-center"><?= (int)$ev['dies_programats'] ?></td>
                    <td class="text-center"><?= (int)$ev['num_bandes'] ?></td>
                    <td class="text-center"><span class="badge text-bg-<?= $ev['estat']==='actiu'?'success':($ev['estat']==='tancat'?'secondary':'warning') ?>"><?= h($ev['estat']) ?></span></td>
                    <td class="text-nowrap text-end">
                      <!-- ACCIONS -->
                      <div class="btn-group btn-group-sm flex-nowrap" role="group">
                      <!-- Obrir esdeveniment -->
                      <button type="button"
                        class="btn btn-primary btn-sm meta-edit-btn"                        
                        onclick="window.location.href='event.php?id=<?= (int)$ev['id'] ?>';"
                        data-bs-toggle="tooltip" data-bs-title="Obrir esdeveniment"
                        aria-label="Obrir esdeveniment">
                        <i class="bi bi-box-arrow-in-right"></i>
                      </button>
                      <!-- Editar esdeveniment -->
                      <button type="button"
                        class="btn btn-primary btn-sm meta-edit-btn"
                        onclick="window.location.href='<?= BASE_PATH ?>event_edit.php?id=<?= (int)$ev['id'] ?>&ret=<?= rawurlencode($_SERVER['REQUEST_URI'] ?? (BASE_PATH.'espai.php?seccio=produccio')) ?>';"
                        data-bs-toggle="tooltip" data-bs-title="Editar esdeveniment"
                        aria-label="Editar esdeveniment">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                      <button type="button"
                        class="btn btn-outline btn-sm meta-edit-btn"                        
                        onclick="window.location.href='share_pack.php?scope=event&id=<?= (int)$ev['id'] ?>';"
                        data-bs-toggle="tooltip" data-bs-title="Compartir"
                        aria-label="Compartir">
                        <i class="bi bi-share"></i>
                      </button>
                      <!-- Borrar event -->
                      <button
                        type="button"
                        class="btn btn-danger btn-sm text-nowrap"
                        data-bs-toggle="modal"
                        data-bs-target="#modalDeleteEvent" data-bs-title="Eliminar"
                        data-event-id="<?= (int)$ev['id'] ?>"
                        data-event-name="<?= htmlspecialchars((string)$ev['nom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        title="Esborra">
                        <i class="bi bi-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="8" class="border-0 text-end">
                      <button 
                        type="button" 
                        class="botons_riders btn btn-primary btn-sm" 
                        onclick="window.location.href='event_new.php';">
                        <i class="bi bi-plus-circle me-1"></i> Nou esdeveniment
                      </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
  

<!-- Properes actuacions (7 dies) -->
    <div class="d-flex align-items-center mb-2 mt-4 pt-2">
        <h2 class="h5 mb-0">Properes actuacions</h2>
    </div>
    <?php if (empty($nextActs)): ?>
        <p class="text-body-secondary small">Sense actuacions programades en els pròxims 7 dies.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-dark">
            <tr>
              <th class="text-light">Dia</th>
              <th class="text-light">Esdeveniment</th>
              <th class="text-light">Escenari</th>
              <th class="text-light">Artista</th>
              <th class="text-light">Estat</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($nextActs as $row): ?>
            <tr>
              <td><?= fmt_d($row['dia']) ?></td>
              <td><a href="event.php?id=<?= (int)$row['event_id'] ?>"><?= h($row['event_nom']) ?></a></td>
              <td><a href="produccio_escenari.php?id=<?= (int)$row['stage_id'] ?>"><?= h($row['stage_nom']) ?></a></td>
              <td><a href="actuacio.php?id=<?= (int)$row['act_id'] ?>"><?= h($row['artista_nom']) ?></a></td>
              <td><span class="badge text-bg-<?= badge_state($row['negotiation_state'] ?? 'rider_rebut') ?>"><?= h($row['negotiation_state'] ?? 'rider_rebut') ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
</div>

<!-- ALERTES -->
<div class="container my-3">
  <div class="d-flex align-items-center mb-2 pt-2">
        <h2 class="h5 mb-0">Alertes</h2>
    </div>
    <div class="row g-3 small">
      <div class="col-md-4">
        <div class="card k-card h-100">
          <div class="card-header"><i class="bi bi-arrow-repeat me-1"></i> Contra a refrescar</div>
          <div class="card-body">
            <?php if (empty($alertsRefresh)): ?>
              <p class="text-body-secondary mb-0">Cap alerta.</p>
            <?php else: ?>
              <ul class="list-unstyled mb-0">
                <?php foreach ($alertsRefresh as $a): ?>
                  <li class="mb-2 small">
                    <strong><?= h($a['event_nom']) ?></strong> · <?= h($a['stage_nom']) ?> · <?= fmt_d($a['dia']) ?> —
                    <a href="actuacio.php?id=<?= (int)$a['act_id'] ?>"><?= h($a['artista_nom']) ?></a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card k-card h-100">
          <div class="card-header"><i class="bi bi-exclamation-triangle me-1"></i> IA precheck risc</div>
          <div class="card-body">
            <?php if (empty($riskActs)): ?>
              <p class="text-body-secondary mb-0">Cap risc detectat.</p>
            <?php else: ?>
              <ul class="list-unstyled mb-0">
                <?php foreach ($riskActs as $r): ?>
                  <li class="mb-2 small">
                    <strong><?= h($r['event_nom']) ?></strong> · <?= fmt_d($r['dia']) ?> — <?= h($r['artista_nom']) ?> (<?= (int)$r['ia_precheck_score'] ?>)
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card k-card h-100">
          <div class="card-header"><i class="bi bi-check2-square me-1"></i> Dies pendents d'OK complet</div>
          <div class="card-body">
            <?php if (empty($okDays)): ?>
              <p class="text-body-secondary mb-0">Cap pendent.</p>
            <?php else: ?>
              <ul class="list-unstyled mb-0">
                <?php foreach ($okDays as $o): ?>
                  <li class="mb-2 small">
                    <strong><?= h($o['event_nom']) ?></strong> · <?= h($o['stage_nom']) ?> · <?= fmt_d($o['dia']) ?> — <?= (int)$o['num_ok'] ?>/<?= (int)$o['num_bandes'] ?> OK
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <!-- FI ALERTES -->
</div>

<!-- HTML Modal Eliminar esdeveniment -->
<div class="modal fade" id="modalDeleteEvent" tabindex="-1" aria-labelledby="modalDeleteEventLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-light border border-danger-subtle">
      <div class="modal-header border-0">
        <h5 class="modal-title" id="modalDeleteEventLabel">
          <i class="bi bi-exclamation-circle me-2 text-danger"></i>Eliminar esdeveniment
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
      </div>

      <div class="modal-body">
        <p class="mb-2 small">
          Segur que vols eliminar aquest contingut?
        </p>
        <div class="border rounded mb-4 p-2 bg-kinosonik small">
          <div class="row">
            <div class="col-12 text-muted">Esdeveniment: <strong id="delEventName">—</strong></div>
          </div>
        </div>
        <p class="mb-2 small">
          Aquesta acció és <strong>definitiva</strong> i esborrarà:
        </p>
        <ul class="small mb-3">
          <li>Tots els escenaris vinculats</li>
          <li>Tots els dies de cada escenari</li>
          <li>Totes les actuacions i rondes</li>
          <li>Enllaços a documents (riders originals, finals, plantilles…)</li>
          <li>Entrades d’IA (prechecks associats)</li>
        </ul>
        <hr>
        <label for="confirmWord" class="form-label small text-secondary">Escriu <code>ELIMINA</code> per confirmar:</label>
        <input type="text" class="form-control" id="confirmWord" placeholder="ELIMINA" autocomplete="off">
      </div>


      <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel·la</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="submitDeleteEvent()">
          <i class="bi bi-trash me-1"></i> Elimina definitivament
        </button>
      </div>
    </div>
  </div>
</div>
<!-- Formulari ocult que s’envia via fetch POST -->
<form id="frmDeleteEvent" method="post" action="<?= h(BASE_PATH) ?>event_delete.php" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
  <input type="hidden" name="id" id="delEventId" value="">
</form>

<!-- JS MOdal Eliminar Esdeveniment -->
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('modalDeleteEvent');
    if (!modalEl) return;

    modalEl.addEventListener('show.bs.modal', (ev) => {
      const btn = ev.relatedTarget;
      const id  = btn?.getAttribute('data-event-id') || '';
      const nom = btn?.getAttribute('data-event-name') || '—';

      document.getElementById('delEventId').value = id;
      document.getElementById('delEventName').textContent = nom;
      document.getElementById('confirmWord').value = '';
    });

  });

  async function submitDeleteEvent() {
  const ok = document.getElementById('confirmWord').value.trim().toUpperCase() === 'ELIMINA';
  if (!ok) { alert('Escriu ELIMINA per confirmar.'); return; }

  const form = document.getElementById('frmDeleteEvent');
  const res  = await fetch(form.action, { method: 'POST', body: new FormData(form) });

  try {
    const json = await res.json();
    if (json.ok) {
      sessionStorage.setItem('ks_flash', JSON.stringify({
        cls: 'success',
        text: 'Esdeveniment eliminat correctament.'
      }));
      location.reload();
    } else {
      alert('Error: ' + (json.message || json.error || 'delete_failed'));
    }
  } catch {
    alert('Error inesperat eliminant l’esdeveniment.');
  }
}

</script>
<!-- JS per pintar avisos al flash -->
 <script>
  document.addEventListener('DOMContentLoaded', () => {
    // mostra flash si n'hi ha
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
      } catch (e) {
        // ignora
      } finally {
        sessionStorage.removeItem('ks_flash');
      }
    }
  });
</script>
