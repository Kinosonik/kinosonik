<?php
// stage_day_new.php — Creació d'un dia per a un escenari
// Accés: productor o admin. Requereix: Stage_Days, Event_Stages, Events

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

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');

$stageId = (int)($_GET['stage_id'] ?? $_POST['stage_id'] ?? 0);
if ($stageId <= 0) { http_response_code(400); exit('bad_request'); }

// ── Context: escenari + event i validació de propietari ───────────────
$sql = <<<SQL
SELECT s.id AS stage_id, s.nom AS stage_nom, s.data_inici AS stage_inici, s.data_fi AS stage_fi,
       e.id AS event_id, e.nom AS event_nom, e.owner_user_id,
       e.is_open_ended, e.data_inici AS event_inici, e.data_fi AS event_fi
FROM Event_Stages s
JOIN Events e ON e.id = s.event_id
WHERE s.id = :sid
SQL;
$st = $pdo->prepare($sql); $st->execute([':sid'=>$stageId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctx) { http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$ctx['owner_user_id'] !== $uid) { http_response_code(403); exit('forbidden'); }

// ── Frontera de dates permesa (min/max) ───────────────────────────────
function toYmd(?string $s): ?string { if(!$s) return null; $t=strtotime($s); return $t?date('Y-m-d',$t):null; }
$evStart = toYmd($ctx['event_inici']);
$evEnd   = toYmd($ctx['event_fi']);           // pot ser null si obert
$stStart = toYmd($ctx['stage_inici']);
$stEnd   = toYmd($ctx['stage_fi']);           // pot ser null

$minDate = $evStart && $stStart ? max($evStart, $stStart) : ($evStart ?: $stStart);
$maxDate = null;
if ($evEnd && $stEnd)      $maxDate = min($evEnd, $stEnd);
elseif ($evEnd && !$stEnd) $maxDate = $evEnd;
elseif (!$evEnd && $stEnd) $maxDate = $stEnd; // si ambdós null → il·limitat

$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('csrf_invalid'); }

  $dia = trim((string)($_POST['dia'] ?? ''));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
    $err = 'Data invàlida.';
  }

  // dins finestres
  if (!$err && $minDate && $dia < $minDate) $err = 'La data és anterior a la finestra de l\'escenari o de l\'event.';
  if (!$err && $maxDate && $dia > $maxDate) $err = 'La data és posterior a la finestra de l\'escenari o de l\'event.';

  // únic per dia
  if (!$err) {
    $chk = $pdo->prepare('SELECT id FROM Stage_Days WHERE stage_id=:sid AND dia=:d LIMIT 1');
    $chk->execute([':sid'=>$stageId, ':d'=>$dia]);
    if ($chk->fetch()) $err = 'Ja existeix aquest dia a l\'escenari.';
  }

  if (!$err) {
    try {
      $ins = $pdo->prepare('INSERT INTO Stage_Days (stage_id, dia) VALUES (:sid, :d)');
      $ins->execute([':sid'=>$stageId, ':d'=>$dia]);
      $newId = (int)$pdo->lastInsertId();
      // ── Si la petició no demana HTML (modal/fetch), respon JSON ────────────────
      $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
      if (str_contains($accept, 'application/json') || str_contains($accept, '*/*')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'ok', 'id' => $newId]);
        exit;
      }

      // ── Flux normal (no AJAX) ───────────────────────────────────────────
      header('Location: ' . (BASE_PATH . 'produccio_escenari.php?id=' . (int)$ctx['stage_id']));
      exit;
    } catch (Throwable $e) {
      $err = 'Error en desar el dia.';
    }
  }
}

/* ── Head + Nav ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>

<div class="container w-75">
  <div class="d-flex justify-content-between align-items-center mb-2 border-bottom border-1 border-secondary ">
    <h4 class="text-start">
      <i class="bi bi-calendar-plus"></i>&nbsp;&nbsp;
      Nou dia · <span class="text-secondary"><?= h($ctx['event_nom']) ?></span>
      · <span class="text-secondary"><?= h($ctx['stage_nom']) ?></span>
    </h4>
    <div class="btn-group d-flex">
      <button type="button" class="btn btn-primary btn-sm"
        onclick="window.location.href='<?= h(BASE_PATH) ?>produccio_escenari.php?id=<?= (int)$ctx['stage_id'] ?>';"
        data-bs-toggle="tooltip" data-bs-title="Tornar" aria-label="Tornar">
        <i class="bi bi-arrow-left"></i>
      </button>
    </div>
  </div>

  <div class="mb-2 small text-secondary">
    <div>Finestra event: <span class="text-light"><?php if ((int)$ctx['is_open_ended']===1): ?><?= h($evStart) ?> → ∞<?php else: ?><?= h($evStart) ?> → <?= h($evEnd ?: '') ?><?php endif; ?></span></div>
    <div>Finestra escenari: <span class="text-light"><?= h($stStart) ?> → <?= h($stEnd ?: '∞') ?></span></div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-warning k-card"><?= h($err) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= h(BASE_PATH) ?>stage_day_new.php" class="row g-3">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
    <input type="hidden" name="stage_id" value="<?= (int)$ctx['stage_id'] ?>">

    <div class="col-md-4">
      <label class="form-label">Data del dia</label>
      <input type="date" name="dia" class="form-control" required
             value="<?= h($minDate ?: date('Y-m-d')) ?>"
             <?php if ($minDate): ?>min="<?= h($minDate) ?>"<?php endif; ?>
             <?php if ($maxDate): ?>max="<?= h($maxDate) ?>"<?php endif; ?>>
      <div class="form-text">Format: any-mes-dia. Respecta la finestra.</div>
    </div>

    <div class="col-12 d-flex justify-content-end">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check2"></i> Desar
      </button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/parts/footer.php'; ?>
