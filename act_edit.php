<?php
// act_edit.php — Edició d’una actuació dins d’un dia d’escenari
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

$actId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($actId <= 0) { http_response_code(400); exit('bad_request'); }

// ── Context + autorització ─────────────────────────────
$sqlCtx = <<<SQL
SELECT a.id AS act_id, a.stage_day_id, a.ordre, a.artista_nom,
       a.artista_contacte, a.artista_agencia, a.artista_email, a.artista_telefon,
       a.artista_nom_tecnic, a.artista_email_tecnic, a.artista_telefon_tecnic,
       d.dia, s.id AS stage_id, s.nom AS stage_nom,
       e.id AS event_id, e.nom AS event_nom, e.owner_user_id
FROM Stage_Day_Acts a
JOIN Stage_Days d ON d.id = a.stage_day_id
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e ON e.id = s.event_id
WHERE a.id = :id
SQL;
$st = $pdo->prepare($sqlCtx);
$st->execute([':id'=>$actId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctx) { http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$ctx['owner_user_id'] !== $uid) { http_response_code(403); exit('forbidden'); }

$err = '';
$old = [
  'artista_nom'            => $ctx['artista_nom'] ?? '',
  'artista_contacte'       => $ctx['artista_contacte'] ?? '',
  'artista_agencia'        => $ctx['artista_agencia'] ?? '',
  'artista_email'          => $ctx['artista_email'] ?? '',
  'artista_telefon'        => $ctx['artista_telefon'] ?? '',
  'artista_nom_tecnic'     => $ctx['artista_nom_tecnic'] ?? '',
  'artista_email_tecnic'   => $ctx['artista_email_tecnic'] ?? '',
  'artista_telefon_tecnic' => $ctx['artista_telefon_tecnic'] ?? '',
  'ordre'                  => (int)$ctx['ordre'],
];

// ── Tractament formulari ───────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('csrf_invalid'); }

  $artista  = trim((string)($_POST['artista_nom'] ?? ''));
  $contacte = trim((string)($_POST['artista_contacte'] ?? ''));
  $agencia  = trim((string)($_POST['artista_agencia'] ?? ''));
  $email    = trim((string)($_POST['artista_email'] ?? ''));
  $telefon  = trim((string)($_POST['artista_telefon'] ?? ''));
  $tecNom   = trim((string)($_POST['artista_nom_tecnic'] ?? ''));
  $tecEmail = trim((string)($_POST['artista_email_tecnic'] ?? ''));
  $tecTelf  = trim((string)($_POST['artista_telefon_tecnic'] ?? ''));

  if ($artista === '' || mb_strlen($artista) > 180) {
    $err = 'Introdueix un nom d’artista vàlid (1–180 caràcters).';
  } else {
    try {
      $upd = $pdo->prepare(
        'UPDATE Stage_Day_Acts SET
          artista_nom = :nom,
          artista_contacte = :contacte,
          artista_agencia = :agencia,
          artista_email = :email,
          artista_telefon = :telefon,
          artista_nom_tecnic = :tecNom,
          artista_email_tecnic = :tecEmail,
          artista_telefon_tecnic = :tecTelf
         WHERE id = :id'
      );
      $upd->execute([
        ':nom'      => $artista,
        ':contacte' => $contacte,
        ':agencia'  => $agencia,
        ':email'    => $email,
        ':telefon'  => $telefon,
        ':tecNom'   => $tecNom,
        ':tecEmail' => $tecEmail,
        ':tecTelf'  => $tecTelf,
        ':id'       => $actId,
      ]);

      echo '<script>
        sessionStorage.setItem("ks_flash", "Actuació actualitzada correctament.");
        window.location.href = "' . h(BASE_PATH) . 'stage_day_detail.php?id=' . (int)$ctx['stage_day_id'] . '";
        </script>';
    exit;

    } catch (Throwable $e) {
      $err = 'Error en desar els canvis.';
    }
  }

  $old = compact('artista','contacte','agencia','email','telefon','tecNom','tecEmail','tecTelf');
  $old['ordre'] = (int)$ctx['ordre'];
}

/* ── Head + Nav ───────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>
<div class="container">
  <div class="row justify-content-center mb-5">
    <div class="col-12 col-lg-8">
      <?php if ($err): ?>
        <div class="alert alert-warning k-card"><?= h($err) ?></div>
      <?php endif; ?>

      <div class="card border-1 shadow">
        <div class="card-header bg-kinosonik d-flex align-items-center">
          <div class="flex-grow-1 position-relative">
            <h6 class="mb-0 text-center"><i class="bi bi-pencil-square me-1"></i> Edita actuació</h6>
          </div>
          <div class="btn-group ms-2">
            <a class="btn-close btn-close-white" href="<?= h(BASE_PATH) ?>stage_day_detail.php?id=<?= (int)$ctx['stage_day_id'] ?>" title="Tanca"></a>
          </div>
        </div>

        <div class="card-body">
          <div class="small">
            <div class="w-100 mb-4 mt-2 small text-light"
              style="background: var(--ks-veil); border-left:3px solid var(--ks-accent); padding:12px 18px;">
              <strong class="text-secondary">Event</strong> <?= h($ctx['event_nom']) ?> ·
              <strong class="text-secondary">Escenari</strong> <?= h($ctx['stage_nom']) ?> · 
              <strong class="text-secondary">Data</strong> <?= fmt_d($ctx['dia']) ?>
            </div>

            <form method="post" action="<?= h(BASE_PATH) ?>act_edit.php" class="row g-3">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
              <input type="hidden" name="id" value="<?= (int)$ctx['act_id'] ?>">

              <div class="col-md-8">
                <label class="form-label">Artista</label>
                <input type="text" name="artista_nom" maxlength="180" required class="form-control" autofocus
                      value="<?= h($old['artista_nom']) ?>"">
              </div>
              <div class="col-md-2">
                <label class="form-label">Ordre</label>
                <input type="number" name="ordre" value="<?= (int)$old['ordre'] ?>"
                      class="form-control text-secondary" readonly tabindex="-1">
                <div class="form-text text-muted">No modificable.</div>
              </div>

              <hr class="mt-3 mb-2">

              <h6 class="text-secondary">Contacte agència</h6>

              <div class="col-md-6">
                <label class="form-label">Agència</label>
                <input type="text" name="artista_agencia" maxlength="150" class="form-control"
                       value="<?= h($old['artista_agencia']) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Contacte</label>
                <input type="text" name="artista_contacte" maxlength="150" class="form-control"
                       value="<?= h($old['artista_contacte']) ?>">
              </div>
              <div class="col-md-8">
                <label class="form-label">Email</label>
                <input type="email" name="artista_email" maxlength="180" class="form-control"
                       value="<?= h($old['artista_email']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Telèfon</label>
                <input type="text" name="artista_telefon" maxlength="50" class="form-control"
                       value="<?= h($old['artista_telefon']) ?>">
              </div>

              <hr class="mt-5 mb-2">

              <h6 class="text-secondary">Contacte tècnic</h6>
              <div class="col-md-6">
                <label class="form-label">Nom tècnic</label>
                <input type="text" name="artista_nom_tecnic" maxlength="150" class="form-control"
                       value="<?= h($old['artista_nom_tecnic']) ?>">
              </div>
              <div class="col-md-6"></div>
              <div class="col-md-8">
                <label class="form-label">Email tècnic</label>
                <input type="email" name="artista_email_tecnic" maxlength="180" class="form-control"
                       value="<?= h($old['artista_email_tecnic']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Telèfon tècnic</label>
                <input type="text" name="artista_telefon_tecnic" maxlength="50" class="form-control"
                       value="<?= h($old['artista_telefon_tecnic']) ?>">
              </div>

              <div class="col-12 text-end mt-3">
                <button class="btn btn-sm btn-primary" type="submit">
                  <i class="bi bi-check-circle me-1"></i> <?= h(__('common.save') ?: 'Desa canvis') ?>
                </button>
                <a class="btn btn-sm btn-secondary" href="<?= h(BASE_PATH) ?>stage_day_detail.php?id=<?= (int)$ctx['stage_day_id'] ?>">
                  <i class="bi bi-x-circle me-1"></i> Cancel·la
                </a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/parts/footer.php'; ?>
