<?php
// stage_edit.php — Edició d'un escenari d'un event
// Accés: productor o admin. Requereix: Event_Stages, Events, Stage_Days

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

// Helpers dates només-dia
function d_input(?string $dbTs): string {
  if (!$dbTs) return '';
  return substr((string)$dbTs, 0, 10); // YYYY-MM-DD
}
function d_from_input(?string $s): ?string {
  $s = trim((string)$s);
  if ($s==='') return null;
  // Accepta estrictament YYYY-MM-DD
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

$stageId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($stageId <= 0) { http_response_code(400); exit('bad_request'); }

// ── Context: escenari + event i autorització ──────────────────────────
$sql = <<<SQL
SELECT s.id AS stage_id, s.event_id, s.nom AS stage_nom, s.data_inici AS stage_inici, s.data_fi AS stage_fi, s.notes,
       e.owner_user_id, e.nom AS event_nom, e.is_open_ended, e.data_inici AS event_inici, e.data_fi AS event_fi
FROM Event_Stages s
JOIN Events e ON e.id = s.event_id
WHERE s.id = :sid
SQL;
$st = $pdo->prepare($sql); $st->execute([':sid'=>$stageId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctx) { http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$ctx['owner_user_id'] !== $uid) { http_response_code(403); exit('forbidden'); }

// Helpers finestra
$eventStart = $ctx['event_inici'];             // datetime
$eventEnd   = $ctx['event_fi'];                // datetime|null
$hasEventEnd = !empty($eventEnd);
$evDi = $eventStart ? substr((string)$eventStart, 0, 10) : '';
$evDf = $hasEventEnd ? substr((string)$eventEnd,   0, 10) : '';

$err = '';
$ok  = '';

// Token de retorn segur: 'event' (per defecte) o 'escenari'
$backToken = (($_GET['return_to'] ?? $_POST['return_to'] ?? '') === 'escenari') ? 'escenari' : 'event';


if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('csrf_invalid'); }

  $nom   = trim((string)($_POST['nom'] ?? ''));
  $start = d_from_input($_POST['data_inici'] ?? '');
  $end   = isset($_POST['sense_fi']) ? null : d_from_input($_POST['data_fi'] ?? '');
  $notes = trim((string)($_POST['notes'] ?? ''));

  // Validació bàsica
  if ($nom === '' || mb_strlen($nom) > 180) $err = 'Nom invàlid (1–180 caràcters).';
  if (!$err && $start === null) $err = 'Data d\'inici invàlida.';

  // Normalitza: si NO hi ha fi i l’event té fi, acotem a fi d’event
  $saveEnd = $end;
  if (!$err && $saveEnd === null && $hasEventEnd) $saveEnd = $evDf; // YYYY-MM-DD

  // Regles de finestra (comparació lexicogràfica amb YYYY-MM-DD)
  if (!$err && $evDi && $start < $evDi) $err = 'L\'inici no pot ser anterior a l\'event.';
  if (!$err && $evDf && $start > $evDf) $err = 'L\'inici no pot ser posterior a la fi de l\'event.';
  if (!$err && $saveEnd !== null) {
    if ($saveEnd < $start) $err = 'La fi ha de ser posterior o igual a l\'inici.';
    if (!$err && $evDf && $saveEnd > $evDf) $err = 'La fi no pot superar la fi de l\'event.';
  }

  // No deixar Stage_Days fora de la nova finestra
  if (!$err) {
    $minD = $start; // YYYY-MM-DD
    $cond = 'dia < :minD';
    $params = [':sid'=>$stageId, ':minD'=>$minD];
    if ($saveEnd !== null) {
      $maxD = $saveEnd;
      $cond .= ' OR dia > :maxD';
      $params[':maxD'] = $maxD;
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM Stage_Days WHERE stage_id=:sid AND ($cond)");
    $q->execute($params);
    $out = (int)$q->fetchColumn();
    if ($out > 0) $err = 'Hi ha dies d\'escenari fora de la nova finestra. Ajusta dates o modifica els dies.';
  }

  if (!$err) {
    try {
      $pdo->beginTransaction();

      // 1) Update rang + notes
      $u = $pdo->prepare('UPDATE Event_Stages
                          SET nom=:n, data_inici=:di, data_fi=:df, notes=:no
                          WHERE id=:sid');
      $u->execute([':n'=>$nom, ':di'=>$start, ':df'=>$saveEnd, ':no'=>$notes, ':sid'=>$stageId]);

      // 2) Omple Stage_Days si s'ha ampliat rang i tenim fi definida
      if ($saveEnd !== null) {
        // Quins dies ja existeixen?
        $qHave = $pdo->prepare('SELECT dia FROM Stage_Days WHERE stage_id=:sid');
        $qHave->execute([':sid'=>$stageId]);
        $have = array_flip($qHave->fetchAll(PDO::FETCH_COLUMN, 0)); // set de 'YYYY-MM-DD'

        // Itera des de start fins a saveEnd (inclosos)
        $cur = $start;
        $ins = $pdo->prepare('INSERT INTO Stage_Days (stage_id, dia, ts_created, ts_updated)
                              VALUES (:sid, :dia, NOW(), NOW())');
        while ($cur <= $saveEnd) {
          if (!isset($have[$cur])) {
            $ins->execute([':sid'=>$stageId, ':dia'=>$cur]);
          }
          $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
        }
      }

      $pdo->commit();
      $ok = 'Escenari actualitzat.';

      // Recarrega context
      $st->execute([':sid'=>$stageId]);
      $ctx = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      $err = 'Error en desar els canvis.';
    }
  }
  // Redirecció segons origen (només si tot OK, dins POST)
  if (!$err) {
    $dest = ($backToken === 'escenari')
      ? (BASE_PATH . 'produccio_escenari.php?id=' . (int)$stageId)
      : (BASE_PATH . 'event.php?id=' . (int)$ctx['event_id']);
    header('Location: ' . $dest);
    exit;
  }
} // fi POST

/* ── Head + Nav ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <?php if ($err): ?>
              <div class="alert alert-warning k-card mb-2"><?= h($err) ?></div>
            <?php elseif ($ok): ?>
              <div class="alert alert-success k-card mb-2"><?= h($ok) ?></div>
            <?php endif; ?>
            <div class="card border-1 shadow">  
                <!-- Títol box -->
                <div class="card-header bg-kinosonik centered">
                    <h6><i class="bi bi-plus-circle me-1"></i> Editar escenari</h6>
                    <div class="btn-group ms-2">
                        <a class="btn-close btn-close-white" title="Tanca" href="<?=
                          h($backToken==='escenari'
                             ? (BASE_PATH.'produccio_escenari.php?id='.(int)$ctx['stage_id'])
                             : (BASE_PATH.'event.php?id='.(int)$ctx['event_id'])
                          )
                        ?>"></a>
                    </div>
                </div>
                <!-- Body card -->
                <div class="card-body">
                  <!-- Títol info dins de caixa permanent -->
                    <div class="small">
                        <div class="w-100 mb-4 mt-2 small text-light"
                            style="background: var(--ks-veil);
                            border-left:3px solid var(--ks-accent);
                            padding:12px 18px;">
                        <strong class="text-secondary">Event </strong><?= h($ctx['event_nom']) ?>
                    </div>
                    <div class="small">
                      <form method="post" action="<?= h(BASE_PATH) ?>stage_edit.php" class="row g-3">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                        <input type="hidden" name="id" value="<?= (int)$ctx['stage_id'] ?>">
                        <input type="hidden" name="return_to" value="<?= h($backToken) ?>">

                        <div class="col-md-12">
                          <label class="form-label">Nom de l'escenari</label>
                          <input type="text" name="nom" maxlength="180" required class="form-control" value="<?= h($ctx['stage_nom']) ?>">
                        </div>

                        <div class="col-md-12">
                          <label class="form-label">Sense data final</label>
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="sense_fi" id="chkSenseFi"
                              <?= empty($ctx['stage_fi']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="chkSenseFi">
                              Si l’event té fi, s’acotarà automàticament a la data de fi de l’event.
                            </label>
                          </div>
                        </div>

                        <div class="col-md-3">
                          <label class="form-label">Data d'inici</label>
                          <input
                            type="date" name="data_inici" id="data_inici" required class="form-control"
                            value="<?= h(d_input($ctx['stage_inici'])) ?>"
                            <?= $evDi ? 'min="'.h($evDi).'"' : '' ?>
                            <?= $evDf ? 'max="'.h($evDf).'"' : '' ?>>
                        </div>

                        <div class="col-md-3">
                          <label class="form-label">Data de fi</label>
                          <input
                            type="date" name="data_fi" id="data_fi" class="form-control"
                            value="<?= h(d_input($ctx['stage_fi'])) ?>"
                            <?= $evDi ? 'min="'.h($evDi).'"' : '' ?>
                            <?= $evDf ? 'max="'.h($evDf).'"' : '' ?>>
                          <div class="form-text">Si marques “Sense data final”, s’ignora (o s’acota a la fi de l’event si n’hi ha).</div>
                        </div>

                        <div class="col-12">
                          <label class="form-label">Notes</label>
                          <textarea name="notes" rows="3" class="form-control" maxlength="1000"><?= h((string)$ctx['notes']) ?></textarea>
                        </div>

                        <div class="col-12 text-end">
                          <button type="submit" class="btn btn-sm btn-primary">
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
  const chk = document.getElementById('chkSenseFi');
  const df  = document.getElementById('data_fi');
  function sync() {
    if (!chk || !df) return;
    if (chk.checked) {
      df.value = '';
      df.setAttribute('disabled', 'disabled');
      df.removeAttribute('required');
    } else {
      df.removeAttribute('disabled');
    }
  }
  chk?.addEventListener('change', sync);
  sync();
});
</script>
<?php require_once __DIR__ . '/parts/footer.php'; ?>