<?php
// event_edit.php — Edició d’un esdeveniment
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';

ks_require_role('productor','admin');
if (!function_exists('h')) { function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
// Accepta 'YYYY-MM-DD' (o prefix d'una datetime) i retorna només el dia
function d_from_input(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  // permet també valors tipus 'YYYY-MM-DDTHH:MM...' i agafa els 10 primers
  if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
  return null;
}
// Evita open-redirect. Exigeix path relatiu dins BASE_PATH
function safe_return(?string $ret, string $fallback): string {
  $ret = trim((string)$ret);
  if ($ret==='') return $fallback;
  if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $ret)) return $fallback; // no protocols
  $ret = str_replace(["\r","\n"], '', $ret);
  if ($ret[0] !== '/') $ret = '/'.$ret;
  if (strpos($ret, BASE_PATH) !== 0) return $fallback;
  return $ret;
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');
$eid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$retParam = (string)($_GET['ret'] ?? $_POST['ret'] ?? '');
$returnTo = safe_return($retParam, BASE_PATH.'event.php?id='.$eid);
if ($eid<=0){ http_response_code(400); exit('bad_request'); }

$st = $pdo->prepare('SELECT * FROM Events WHERE id=:id'); $st->execute([':id'=>$eid]);
$ev = $st->fetch(PDO::FETCH_ASSOC);
if (!$ev){ http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$ev['owner_user_id'] !== $uid){ http_response_code(403); exit('forbidden'); }

$err='';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('csrf_invalid'); }

  $nom = trim((string)($_POST['nom'] ?? ''));
  $isOpen = (int)((isset($_POST['is_open_ended']) && $_POST['is_open_ended']=='1') ? 1 : 0);
  $estat = (string)($_POST['estat'] ?? 'esborrany');
  $di = d_from_input($_POST['data_inici'] ?? '');
  $df = d_from_input($_POST['data_fi'] ?? '');
  $fillDays = isset($_POST['fill_days_for_stages']) && $_POST['fill_days_for_stages'] == '1';

  if ($nom==='') $err='Introdueix un nom.';
  if (!$isOpen && (!$di || !$df)) $err = $err ?: 'Cal data d’inici i fi si no és obert.';
  if (!$isOpen && $di && $df && $df < $di) $err = $err ?: 'La data fi no pot ser anterior a l’inici.';

  // Bloqueja “tancar” l’event si deixa escenaris fora de la finestra i
  // mostra quins escenaris queden fora (fins a 8) perquè l'usuari els editi.
  if ($err==='') {
    $newDi = $di ?: substr((string)$ev['data_inici'],0,10);
    $newDf = $isOpen ? null : $df;
    $hasDf = $newDf !== null ? 1 : 0;
    $q = $pdo->prepare("
      SELECT s.id, s.nom, s.data_inici, s.data_fi
      FROM Event_Stages s
      WHERE s.event_id=:eid
        AND (
              s.data_inici < :di
           OR (:hasDf=1 AND (s.data_fi IS NULL OR s.data_fi > :df))
        )
      ORDER BY s.nom
      LIMIT 8
    ");
    $q->execute([':eid'=>$eid, ':di'=>$newDi, ':df'=>$newDf, ':hasDf'=>$hasDf]);
    $badRows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($badRows)) {
      $n = count($badRows);
      $samples = [];
      foreach ($badRows as $r) {
        $diS = substr((string)$r['data_inici'],0,10);
        $dfS = $r['data_fi'] ? substr((string)$r['data_fi'],0,10) : '∞';
        $samples[] = $r['nom']." ($diS → $dfS)";
      }
      $extra = $n===8 ? '…' : '';
      $err = "Hi ha escenaris fora de la nova finestra de l’event. "
           . "Ajusta les dates d’aquests escenaris o modifica la finestra de l’event. "
           . "Exemples: " . implode(', ', $samples) . $extra . ".";
    }
  }

  if ($err==='') {
    try {
      $pdo->beginTransaction();
      // 1) Update Event
      $up = $pdo->prepare('UPDATE Events
                           SET nom=:nom, is_open_ended=:open, data_inici=:di, data_fi=:df, estat=:st, ts_updated=NOW()
                           WHERE id=:id');
      $up->execute([
        ':nom'=>$nom, ':open'=>$isOpen,
        ':di'=>$di ?: substr((string)$ev['data_inici'],0,10),
        ':df'=>$isOpen ? null : $df,
        ':st'=>in_array($estat,['esborrany','actiu','tancat'],true)?$estat:'esborrany',
        ':id'=>$eid
      ]);

      // 2) Propaga Stage_Days opcionalment
      if ($fillDays) {
        $stg = $pdo->prepare('SELECT id, data_inici, data_fi FROM Event_Stages WHERE event_id=:eid');
        $stg->execute([':eid'=>$eid]);
        $stages = $stg->fetchAll(PDO::FETCH_ASSOC);

        $insDay = $pdo->prepare('INSERT INTO Stage_Days (stage_id, dia, ts_created, ts_updated)
                                 VALUES (:sid, :dia, NOW(), NOW())');
        $selHave = $pdo->prepare('SELECT dia FROM Stage_Days WHERE stage_id=:sid');

        foreach ($stages as $s) {
          $sid = (int)$s['id'];
          $sStart = $s['data_inici'] ? substr((string)$s['data_inici'],0,10) : null;
          // si l’escenari no té fi i l’event sí, acota a la fi de l’event
          $sEnd = $s['data_fi'] ? substr((string)$s['data_fi'],0,10) : ($isOpen ? null : $df);
          if (!$sStart || !$sEnd) continue; // necessitem un límit superior

          $selHave->execute([':sid'=>$sid]);
          $have = array_flip($selHave->fetchAll(PDO::FETCH_COLUMN, 0)); // set existing 'YYYY-MM-DD'

          $cur = $sStart;
          while ($cur <= $sEnd) {
            if (!isset($have[$cur])) {
              $insDay->execute([':sid'=>$sid, ':dia'=>$cur]);
            }
            $cur = date('Y-m-d', strtotime($cur.' +1 day'));
          }
        }
      }

      $pdo->commit();
      header('Location: '.$returnTo); exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      $err = 'Error en desar els canvis.';
    }
  }
}

require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <?php if ($err): ?><div class="alert alert-warning k-card"><?= h($err) ?></div><?php endif; ?>
            <div class="card border-1 shadow">  
                <!-- Títol box -->
                <div class="card-header bg-kinosonik centered">
                    <h6><i class="bi bi-plus-circle me-1"></i> Editar esdeveniment</h6>
                    <div class="btn-group ms-2">
                        <a class="btn-close btn-close-white" href="<?= h($returnTo) ?>"></a>
                    </div>
                </div>
                <!-- Body card -->
                <div class="card-body">
                    <div class="small">

                          <form method="post" action="<?= h(BASE_PATH) ?>event_edit.php" class="row g-3">
                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                            <input type="hidden" name="id" value="<?= (int)$eid ?>">
                            <input type="hidden" name="ret" value="<?= h($retParam) ?>">
                            <div class="col-md-8">
                              <label class="form-label">Nom</label>
                              <input type="text" name="nom" maxlength="180" required class="form-control" value="<?= h($ev['nom']) ?>">
                            </div>

                            <div class="col-md-4">
                              <label class="form-label">Estat</label>
                              <select name="estat" class="form-select">
                                <?php foreach (['esborrany','actiu','tancat'] as $opt): ?>
                                  <option value="<?= $opt ?>" <?= $ev['estat']===$opt?'selected':'' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                            <div class="col-12">
                              <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="chkOpen" name="is_open_ended" <?= ((int)$ev['is_open_ended']===1)?'checked':'' ?>>
                                <label class="form-check-label" for="chkOpen">Esdeveniment obert</label>
                              </div>
                            </div>
                            <div class="col-12">
                              <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="fillDays" name="fill_days_for_stages" checked>
                                <label class="form-check-label" for="fillDays">
                                  Crea automàticament els dies dels escenaris dins la seva finestra.
                                </label>
                              </div>
                              <div class="form-text small">
                                Només s’aplica si l’escenari té data de fi; si no en té però l’event sí, s’acota a la fi de l’event.
                              </div>
                            </div>
                            <div class="col-md-3">
                              <label class="form-label">Data inici</label>
                              <input type="date" name="data_inici" id="data_inici" class="form-control"
                                     value="<?= h(substr((string)$ev['data_inici'],0,10)) ?>">
                            </div>
                            <div class="col-md-3">
                              <label class="form-label">Data fi</label>
                              <input type="date" name="data_fi" id="data_fi" class="form-control"
                                     value="<?= h($ev['data_fi'] ? substr((string)$ev['data_fi'],0,10) : '') ?>"
                                     <?= ((int)$ev['is_open_ended']===1)?'disabled':'' ?>>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-sm btn-primary" type="submit">
                                    <i class="bi bi-plus-circle me-1"></i> <?= h(__('common.save') ?: 'Desa') ?>
                                </button>
                                <a class="btn btn-secondary btn-sm" href="<?= h($returnTo) ?>">
                                  <i class="bi bi-x-circle"></i> <?= h(__('common.tanca') ?: 'Tanca') ?>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Igual que a event_new.php: gestiona obert/tancat i mínim de data fi
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
  syncEndState(); syncMin();
});
</script>
<?php require_once __DIR__ . '/parts/footer.php'; ?>
