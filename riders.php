<?php
// riders.php — Llistat real de riders per usuari (amb mode inspecció per ADMIN) + redireccions (multillenguatge)
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';
require_once __DIR__ . '/php/middleware.php';
require_once __DIR__ . "/php/db.php";
$pdo = db();
require_once __DIR__ . '/php/messages.php';
require_once __DIR__ . '/php/i18n.php'; // t(), __()
require_once __DIR__ . '/php/time_helpers.php'; // safe_dt(), dt_eu(), etc.
ks_require_role('tecnic','admin','productor');

// Base absoluta per a enllaços públics
function abs_base(): string {
  if (defined('BASE_URL') && BASE_URL) return rtrim((string)BASE_URL, '/');

  $protoHdr = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
  $isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($protoHdr === 'https');
  $scheme   = $isHttps ? 'https' : 'http';

  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
  // saneja host per seguretat
  $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', (string)$host);

  return $host ? ($scheme.'://'.$host) : '';
}

// Helper de traducció amb fallback
function tr(string $key, string $fallback = ''): string {
  $v = __($key);
  return (is_string($v) && $v !== '') ? $v : $fallback;
}

// Helpers estat segell
function segell_norm(string $s): string { return strtolower(trim($s)); }
function segell_es_valid(string $s): bool { return segell_norm($s) === 'validat'; }
function segell_es_caducat(string $s): bool { return segell_norm($s) === 'caducat'; }

// Helper d'escapat
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Helpers de dates ja proveïts per time_helpers.php:
// - safe_dt(?string): ?DateTime
// - dt_eu(?string): string "dd/mm/yyyy HH:mm"

/* ── Login ─────────────────────────────────────────────────────────────────── */
$currentUserId = $_SESSION['user_id'] ?? null;
if (!$currentUserId) {
  header('Location: ' . BASE_PATH . 'index.php?error=login_required');
  exit;
}

/* ── Tipus d’usuari ───────────────────────────────────────────────────────── */
$tipusSessio = $_SESSION['tipus_usuari'] ?? null;
if ($tipusSessio === null || $tipusSessio === '') {
  $st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
  $st->execute([$currentUserId]);
  $tipusSessio = (string)($st->fetchColumn() ?: '');
  $_SESSION['tipus_usuari'] = $tipusSessio;
}
$isAdmin = (strcasecmp($tipusSessio, 'admin') === 0);

// ── IA: Riders d’aquest usuari amb IA pendent (queued/running/processing/pending) ─────────────
// Sense CTE: triem l'última execució per rider amb NOT EXISTS (compatible MySQL/MariaDB)
$iaStmt = $pdo->prepare("
  SELECT r.ID_Rider, r.Rider_UID, r.Descripcio, r.Nom_Arxiu, r.Referencia,
       j.status AS ia_status,
       j.started_at AS ia_started_at
  FROM Riders r
  JOIN ia_jobs j ON j.rider_id = r.ID_Rider
  WHERE r.ID_Usuari = :uid
    AND LOWER(COALESCE(j.status,'')) IN ('queued','running')
    AND LOWER(COALESCE(r.Estat_Segell,'')) NOT IN ('validat','caducat')
  ORDER BY j.created_at DESC
");
$iaStmt->execute([':uid' => (int)$currentUserId]);
$iaPending = $iaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ── Mode inspecció admin (?uid=...) ──────────────────────────────────────── */
$targetUserId = (int)$currentUserId;
if ($isAdmin && isset($_GET['uid']) && ctype_digit((string)$_GET['uid'])) {
  $targetUserId = (int)$_GET['uid'];
}
$isOwn = ($targetUserId === (int)$currentUserId);

/* ── Formulari pujada: només si mires els teus propis riders ─────────────── */
$canUploadHere = $isOwn && in_array($tipusSessio, ['tecnic','productor','admin'], true);

/* Etiqueta per a inspecció */
$targetEmail = null;
if ($isAdmin && !$isOwn) {
  $se = $pdo->prepare("SELECT Email_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
  $se->execute([$targetUserId]);
  $targetEmail = (string)($se->fetchColumn() ?: '');
}

// ── Paràmetres d’ordenació i paginació ─────────────────────────
$validSorts = [
  'title'     => 'r.Descripcio',       // Descripció
  'ref'       => 'r.Referencia',       // Referència
  'published' => 'r.Data_Publicacio',  // Data publicació
];

$sortKey = $_GET['sort'] ?? '';
$sortCol = $validSorts[$sortKey] ?? 'r.Data_Pujada'; // per defecte: Data de pujada (no ordenable al thead)

$dir = strtolower($_GET['dir'] ?? 'desc');
$dir = ($dir === 'asc') ? 'ASC' : 'DESC';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
$offset  = ($page - 1) * $perPage;

// Helper per construir URLs d’ordenació mantenint filtres actuals
function sort_url(string $key, string $dirDefault = 'asc'): string {
  $q = $_GET;
  $isSame = ($q['sort'] ?? '') === $key;
  $dirNow = strtolower($q['dir'] ?? 'desc');
  $q['dir']  = $isSame ? (($dirNow === 'asc') ? 'desc' : 'asc') : $dirDefault;
  $q['sort'] = $key;
  $q['page'] = 1;
  return '?' . http_build_query($q);
}

// ── Filtres (GET) — en format string per a la UI ──
$fId       = trim((string)($_GET['id']    ?? ''));    // <-- string!
$fDesc     = trim((string)($_GET['qdesc'] ?? ''));
$fRef      = trim((string)($_GET['qref']  ?? ''));
$fSeal     = strtolower(trim((string)($_GET['seal'] ?? '')));
if (!in_array($fSeal, ['cap','pendent','validat','caducat',''], true)) { $fSeal = ''; }
$fRedirect = (isset($_GET['redirect']) && $_GET['redirect'] === 'with') ? 'with' : '';

// --- WHERE dinàmic compartit per COUNT i LIST ---
$where  = ["r.ID_Usuari = :uid"];
$params = [':uid' => (int)$targetUserId];

if ($fId !== '' && ctype_digit($fId)) {
  $where[]       = "r.ID_Rider = :rid";
  $params[':rid'] = (int)$fId;
}
if ($fDesc !== '') {
  $where[]         = "r.Descripcio LIKE :qdesc";
  $params[':qdesc'] = '%'.$fDesc.'%';
}
if ($fRef !== '') {
  $where[]        = "r.Referencia LIKE :qref";
  $params[':qref'] = '%'.$fRef.'%';
}
if ($fSeal !== '') {
  $where[]         = "r.Estat_Segell_lc = :seal";
  $params[':seal'] = $fSeal;
}
if ($fRedirect === 'with') {
  $where[] = "r.rider_actualitzat IS NOT NULL";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// ── Total de registres ─────────────────────────────────────────
$stCnt = $pdo->prepare("SELECT COUNT(*) FROM Riders r {$whereSql}");
$stCnt->execute($params);
$totalRows  = (int)$stCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

/* ── Textos reutilitzables localitzats per JS ─────────────────────────────── */
$L = [
  'redirect_placeholder' => __('riders.redirect.placeholder'),           // — selecciona un rider validat —
  'redirect_none'        => __('riders.redirect.none'),                  // — Sense redirecció —
  'open'                 => __('common.open'),                           // Obre
  'copy'                 => __('common.copy'),                           // Copiar enllaç
  'copied'               => __('common.copied'),                         // Copiat!
  'view_card'            => __('riders.actions.view_card'),              // Veure fitxa del rider
  'open_pdf'             => __('riders.actions.open_pdf'),               // Obrir PDF
  'download_pdf'         => __('riders.actions.download_pdf'),           // Descarregar PDF
  'validate_ai'          => __('riders.actions.validate_ai'),            // Validar amb IA
  'ia_detall'            => __('riders.actions.ia_detail'),              // Detall IA
  'delete_confirm'       => __('common.delete_confirm'),                 // Segur que vols eliminar…?
  'net_error'            => __('common.network_error'),                  // Error de xarxa
  'seal_update_error'    => __('riders.seal.update_error'),              // Error actualitzant segell
  'admin_inspecting'     => __('riders.admin.inspecting'),               // Estàs inspeccionant els riders de:
  'no_riders'            => __('riders.table.no_riders'),                // No hi ha riders.
  'space_usage'          => __('common.space_usage'),                    // Ús d’espai
  'percent_used'         => __('common.percent_used'),                   // % usat
  'reupload_done_title' => __('riders.reupload.done_title'),
  'reupload_done_body'  => __('riders.reupload.done_body'),
  'riders.upload.only_pdf'  => __('riders.upload.only_pdf'),
  'expire.modal.title'       => __('riders.expire.modal.title'),        // Caducar rider
  'expire.modal.lead'        => __('riders.expire.modal.lead'),         // Aquesta acció és irreversible.
  'expire.modal.body_intro'  => __('riders.expire.modal.body_intro'),   // Si caducas aquest rider:
  'expire.modal.point_irrev' => __('riders.expire.modal.point_irrev'),  // • No es podrà tornar a validar.
  'expire.modal.point_redir' => __('riders.expire.modal.point_redir'),  // • Podràs redireccionar-lo a un rider validat més nou des del selector de “Redirecció”.
  'expire.modal.rider'       => __('riders.expire.modal.rider'),        // Rider
  'expire.modal.id'          => __('riders.expire.modal.id'),           // ID
  'expire.modal.desc'        => __('riders.expire.modal.desc'),         // Descripció
  'expire.modal.ref'         => __('riders.expire.modal.ref'),          // Referència
  'expire.modal.cancel'      => __('riders.expire.modal.cancel'),       // Cancel·la
  'expire.modal.confirm'     => __('riders.expire.modal.confirm'),      // Sí, caduca’l
];

/* ── Funció icones + títol segell (localitzat) ───────────────────────────── */
function segell_icon_class(string $estat): array {
  $e = segell_norm($estat);
  if     ($e === 'validat') return ['bi-shield-fill-check',  'text-success',  (__('riders.seal.valid')   ?: 'Validat')];
  elseif ($e === 'caducat') return ['bi-shield-fill-x',      'text-danger',   (__('riders.seal.expired') ?: 'Caducat')];
  elseif ($e === 'pendent') return ['bi-shield-exclamation', 'text-warning',  (__('riders.seal.pending') ?: 'Pendent')];
  return ['bi-shield', 'text-secondary', (__('riders.seal.none') ?: 'Cap')];
}

/* ── CSRF token ───────────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>

<div class="container mb-3">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-8">
      <!-- Avís de riders en cua per IA -->
      <?php if (!empty($iaPending)): ?>
      <div class="alert alert-secondary border-1 border-warning-subtle shadow bg-warning-subtle">
        <div class="d-flex align-items-center justify-content-between">
          <div class="me-3">
            <strong class="text-warning"><?= h(__('riders.ia_pending.title') ?: 'Validacions d’IA en curs') ?></strong><br>
            <span class="small text-body-secondary">
              <?= sprintf(__('riders.ia_pending.hint') ?: 'Tens %d rider(s) a la cua o en execució. Pots continuar navegant; t’avisarem quan acabi.', count($iaPending)) ?>
            </span>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"></button>
        </div>
        <ul class="list-group list-group-flush mt-2">
          <?php foreach ($iaPending as $p):
            $pid   = (int)$p['ID_Rider'];
            $puid  = (string)$p['Rider_UID'];
            $pdesc = trim((string)($p['Descripcio'] ?: ($p['Nom_Arxiu'] ?: ('RD'.$pid))));
            $pref  = trim((string)($p['Referencia'] ?? ''));
            $st    = strtolower((string)$p['ia_status']);
            $pdt   = $p['ia_started_at'] ?? null;
            $pdtS  = $pdt && $pdt !== '0000-00-00 00:00:00' ? dt_eu($pdt) : '—';

            $badge = $st === 'running'
              ? '<span class="badge text-bg-warning">'.h(__('riders.ia_pending.running') ?: 'Executant').'</span>'
              : '<span class="badge text-bg-secondary">'.h(__('riders.ia_pending.queued') ?: 'A la cua').'</span>';
          ?>
            <li class="list-group-item small d-flex align-items-center gap-2  bg-warning-subtle">
              <span class="text-secondary"><i class="bi bi-arrow-right me-1 text-warning"></i><?= h((string)$pid) ?></span>
              <span class="me-1"><?= h($pdesc) ?></span>
              <?php if ($pref !== ''): ?>
                <span class="text-secondary">(<?= h($pref) ?>)</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <!-- PUJAR RIDER NOU -->
      <?php if ($canUploadHere): ?>
      <div class="card border-1 shadow" id="card_riders">
        <!-- Títol box -->
        <div class="card-header bg-kinosonik centered">
          <h6><?= h(__('riders.upload.title')) /* Pujar nou rider (PDF) */ ?></h6>
          <div class="btn-group ms-2">
            <a class="btn-close btn-close-white" href="#" data-close-target="#card_riders"></a>
          </div>
        </div>
        <!-- Body card -->
        <div class="card-body">
          <!-- Info dins de caixa permanent -->
          <?php if ($flash = (function_exists('flash_get') ? flash_get() : null)):
          $type = $flash['type'] ?? 'info'; // 'success' | 'error'
          $key  = (string)($flash['key'] ?? '');
          $dict = ($type === 'success')
            ? ($messages['success'] ?? [])
            : ($messages['error']   ?? []);
          $msg = (string)($dict[$key] ?? ($dict['default'] ?? ''));
          if ($msg === '') { $msg = ($type === 'success') ? __('common.ok') : __('common.error'); }
          $alertClass = ($type === 'success') ? 'alert-success' : 'alert-danger';
          ?>
          <div class="small">
            <div class="w-100 mb-3 mt-3 small text-warning auto-dismiss-alert"
              style="background:rgba(255,193,7,0.15);
              border-left:3px solid #ffc107;
              padding:25px 18px;"
              role="alert">
              <?= h($msg) ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"></button>
            </div>
          </div>
          <script>
            setTimeout(() => {
              const el = document.querySelector('.auto-dismiss-alert');
              if (el) bootstrap.Alert.getOrCreateInstance(el).close();
            }, 3000);
          </script>
          <?php endif; ?>
          <!-- Fi info -->
          <div class="small">
            <form method="post" class="row g-3" action="<?= h(BASE_PATH) ?>php/upload_rider.php" enctype="multipart/form-data" novalidate>
              <?= csrf_field() ?>
              <!-- Límits client-side (10 MB) -->
              <input type="hidden" name="MAX_FILE_SIZE" value="10485760">
              <div class="col-md-8">
                <label class="form-label small">
                  <?= h(__('riders.upload.desc_label')) ?>
                  <span class="form-text">(<?= h(__('riders.upload.desc_help')) ?>)</span>
                </label>
                <input type="text" name="descripcio" class="form-control form-control-sm" required>
              </div>
              <div class="col-md-4">
                <label class="form-label small">
                  <?= h(__('riders.upload.ref_label')) ?>
                  <span class="form-text">(<?= h(__('riders.upload.ref_help')) ?>)</span>
                </label>
                <input type="text" name="referencia" class="form-control form-control-sm">
              </div>
              <div class="col-md-8">
                <label class="form-label small">
                  <?= h(__('riders.upload.pdf_label')) ?>
                  <span class="form-text">
                    <?= h(__('riders.upload.pdf_help')) ?>
                  </span>
                </label>
                <input type="file" id="rider_pdf" name="rider_pdf" accept="application/pdf,.pdf" class="form-control form-control-sm" required>
                <div id="rider_pdf_error" class="text-danger small mt-1" style="display:none;"></div>
                </div>
                <!-- Botó de pujar rider -->
                <div class="col-md-4 d-flex align-items-end justify-content-end gap-2">
                  <button id="uploadBtn" type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-cloud-upload me-1"></i>
                    <?= h(__('riders.upload.submit')) ?>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
        <?php elseif ($isAdmin && !$isOwn): ?>
        <div class="small">
          <div class="w-100 mb-4 mt-2 small text-light"
              style="background: var(--ks-veil);
              border-left:3px solid var(--ks-accent);
              padding:12px 18px;">
              <strong class="text-secondary"><?= h($L['admin_inspecting']) ?>: </strong><?= h($targetEmail ?: ('ID '.$targetUserId)) ?> 
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<!-- FI PUJAR NOU RIDER -->

<!-- VALIDACIÓ HUMANA PENDENT -->
<?php
  $stPending = $pdo->prepare("
  SELECT ID_Rider, Rider_UID, Descripcio, Nom_Arxiu,
         Validacio_Manual_Data, Data_Pujada
    FROM Riders
   WHERE ID_Usuari = :uid
     AND Validacio_Manual_Solicitada = 1
     AND Estat_Segell_lc NOT IN ('validat','caducat')
  ORDER BY COALESCE(Validacio_Manual_Data, Data_Pujada) DESC, ID_Rider DESC
  ");
  $stPending->execute([':uid' => (int)$targetUserId]);
  $pending = $stPending->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($pending)):
    // Helpers curts per a traduccions amb fallback
    $tx = function ($k, $fb) { $v = __($k); return (is_string($v) && $v !== '') ? $v : $fb; };
    $txtBoxTitle     = $tx('riders.human_pending.title', 'Validacions humanes pendents');
    $txtClose        = $tx('common.close', 'Tanca');
    $txtRequestedFmt = $tx('riders.actions.human_requested_on', 'Sol·licitada el %s');
?>
<div class="container mb-3">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-8">
      <div class="card border-1 border-warning-subtle shadow" id="card_human_pending">
        <!-- Títol box -->
        <div class="card-header bg-warning-subtle esquerra text-warning">
          <h6><?= h($txtBoxTitle) ?>: <?= (int)count($pending) ?></h6>          
          <div class="btn-group ms-2">
            <a class="btn-close btn-close-white" href="#" data-close-target="#card_human_pending"></a>
          </div>
        </div>
        <!-- Body card -->
        <div class="card-body">
          <div class="small">
            <ul class="list-group list-group-flush">
            <?php foreach ($pending as $p):
              $pid   = (int)$p['ID_Rider'];
              $pdesc = trim((string)($p['Descripcio'] ?? ''));
              if ($pdesc === '') $pdesc = (string)($p['Nom_Arxiu'] ?? ('RD'.$pid));
              // Data: preferim Validacio_Manual_Data; si no n'hi ha, fem fallback a Data_Pujada
              $dtStr = '—';
              $raw = trim((string)($p['Validacio_Manual_Data'] ?? ''));
              if ($raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
                $raw = trim((string)($p['Data_Pujada'] ?? ''));
              }
              $dtStr = $raw !== '' ? dt_eu($raw) : '—';
            ?>
              <li class="list-group-item d-flex align-items-center gap-2">
                <span class="text-secondary">ID: <?= h((string)$pid) ?></span>
                <span class="fw-semibold"><?= h($pdesc) ?></span>
                <span class="ms-auto text-muted"><?= h(sprintf($txtRequestedFmt, $dtStr)) ?></span>
              </li>
            <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<!-- FI VALIDACIÓ HUMANA -->

<!-- FORMULARI FILTRATGE TAULA -->
<?php
  // Per construir els enllaços “neteja” mantenint alguns paràmetres útils (uid, sort, dir, per_page)
  $cleanQ = $_GET;
  unset(
    $cleanQ['id'], $cleanQ['qdesc'], $cleanQ['qref'],
    $cleanQ['seal'], $cleanQ['redirect'], $cleanQ['page']
  );

  // garanteix que ens quedem a la secció riders i fixa valors “sans”
  $cleanQ['seccio']   = 'riders';
  $cleanQ['sort']     = $sortKey ?: '';          // o posa el que vulguis per defecte
  $cleanQ['dir']      = strtolower($dir) ?: 'desc';
  $cleanQ['per_page'] = (int)$perPage ?: 20;

  $cleanUrl = rtrim(BASE_PATH,'/').'/espai.php?'.http_build_query($cleanQ);

  // Si estàs inspeccionant un usuari (admin), manté uid com a hidden
  $hiddenUid = ($isAdmin && !$isOwn) ? (int)$targetUserId : null;
?>
<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-8">
      <div class="card border-1 shadow">
        <!-- Títol box -->
        <div class="card-header bg-kinosonik esquerra" id="filtra_riders">
          <h6>
            <?= h(__('riders.filters.title') ?: 'Filtrar riders') ?>
            <span class="fw-lighter small text-secondary">
              (<?= h(sprintf(__('riders.filters.total') ?: 'tens %d riders', (int)$totalRows)) ?>)
            </span>
          </h6>
          <div class="btn-group ms-2">
            <a class="btn-close btn-close-white" href="#" data-close-target="#filtra_riders"></a>
          </div>
        </div>
        <!-- Body card -->
        <div class="card-body">
          <div class="small">
            <form method="get" action="<?= h(rtrim(BASE_PATH,'/').'/espai.php') ?>" class="row g-3">
              <input type="hidden" name="seccio" value="riders">
              <?php if ($hiddenUid !== null): ?>
                <input type="hidden" name="uid" value="<?= (int)$hiddenUid ?>">
              <?php endif; ?>
              <input type="hidden" name="sort"     value="<?= h($sortKey) ?>">
              <input type="hidden" name="dir"      value="<?= h(strtolower($dir)) ?>">
              <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
              <input type="hidden" name="page"     value="1">
              <!-- Primera fila: ID, Descripció i Referència -->
              <div class="col-12 col-md-2">
                <label class="form-label small"><?= h(__('riders.filters.id') ?: 'ID Rider') ?></label>
                <input type="number" min="1" step="1" name="id" value="<?= h($fId) ?>" class="form-control form-control-sm">
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label small"><?= h(__('riders.filters.desc') ?: 'Descripció') ?></label>
                <input type="text" name="qdesc" value="<?= h($fDesc) ?>" class="form-control form-control-sm">
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label small"><?= h(__('riders.filters.ref') ?: 'Referència') ?></label>
                <input type="text" name="qref" value="<?= h($fRef) ?>" class="form-control form-control-sm">
              </div>
              <!-- Segona fila: Estat del segell, Redirecció i Botons -->
              <div class="col-12 col-md-3">
                <label class="form-label small">
                  <?= h(__('riders.filters.seal') ?: 'Estat del segell') ?>
                </label>
                <select name="seal" class="form-select form-select-sm">
                  <option value=""><?= h(__('riders.filters.any') ?: 'Qualsevol') ?></option>
                  <option value="cap"     <?= $fSeal==='cap'     ? 'selected' : '' ?>><?= h(__('riders.seal.opt_none')    ?: 'Cap') ?></option>
                  <option value="pendent" <?= $fSeal==='pendent' ? 'selected' : '' ?>><?= h(__('riders.seal.opt_pending') ?: 'Pendent') ?></option>
                  <option value="validat" <?= $fSeal==='validat' ? 'selected' : '' ?>><?= h(__('riders.seal.opt_valid')   ?: 'Validat') ?></option>
                  <option value="caducat" <?= $fSeal==='caducat' ? 'selected' : '' ?>><?= h(__('riders.seal.opt_expired') ?: 'Caducat') ?></option>
                </select>
              </div>
              <div class="col-12 col-md-3 d-flex align-items-end">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" id="redirectWith" name="redirect" value="with"
                    <?= ($fRedirect === 'with') ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="redirectWith">
                    <?= h(__('riders.filters.redirect') ?: 'Redirecció') ?>
                  </label>
                </div>
              </div>
              <div class="col-12 col-md-6 d-flex justify-content-end align-items-end gap-2">
                <button class="btn btn-primary btn-sm" type="submit">
                  <i class="bi bi-search me-1"></i><?= h(__('riders.filters.submit') ?: 'Filtra') ?>
                </button>
                <a class="btn btn-secondary btn-sm" href="<?= h($cleanUrl) ?>">
                  <i class="bi bi-x-circle me-1"></i><?= h(__('riders.filters.clear') ?: 'Neteja') ?>
                </a>
              </div>
            </form>     
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- FI FILTRATGE TAULA -->

  <!--Llistat Riders del targetUserId (amb filtres) ───────────────────────── -->
  <?php
    $sql = "
    SELECT
      r.ID_Rider, r.Rider_UID, r.Nom_Arxiu, r.Descripcio, r.Referencia,
      r.Data_Pujada, r.Data_Publicacio, r.Valoracio, r.Estat_Segell,
      r.Data_IA,
      /* Darrer job d'IA d'aquest rider (si n'hi ha) */
      (
        SELECT ir.job_uid
          FROM ia_runs ir
         WHERE ir.rider_id = r.ID_Rider
         ORDER BY ir.started_at DESC, ir.id DESC
         LIMIT 1
      ) AS last_job_uid,
      (
        SELECT COUNT(*)
          FROM ia_jobs j
         WHERE j.rider_id = r.ID_Rider
           AND j.status IN ('queued','running')
      ) AS ia_active,
      r.Mida_Bytes, r.rider_actualitzat,
      r.Validacio_Manual_Solicitada, r.Validacio_Manual_Data,
      r.Hash_SHA256
    FROM Riders r
    {$whereSql}
    ORDER BY {$sortCol} {$dir}, r.ID_Rider DESC
    LIMIT :lim OFFSET :off
  ";
  $sth = $pdo->prepare($sql);
  // Vincula els paràmetres del WHERE
  foreach ($params as $k => $v) {
    $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $sth->bindValue($k, $v, $type);
  }
  // LIMIT / OFFSET
  $sth->bindValue(':lim', $perPage, PDO::PARAM_INT);
  $sth->bindValue(':off', $offset, PDO::PARAM_INT);
  $sth->execute();
  $riders = $sth->fetchAll(PDO::FETCH_ASSOC);
  /* ── Riders VALIDATS del mateix usuari (per opcions de redirecció) ───────── */
  $stv = $pdo->prepare("
    SELECT ID_Rider, Rider_UID, Descripcio, Nom_Arxiu
    FROM Riders
    WHERE ID_Usuari = :uid AND Estat_Segell_lc = 'validat'
    ORDER BY Data_Pujada DESC, ID_Rider DESC
  ");
  $stv->execute([':uid' => (int)$targetUserId]);
  $validated = $stv->fetchAll(PDO::FETCH_ASSOC);
  // Índex ràpid per ID_Rider -> [label, uid]
  $validIndex = [];
  foreach ($validated as $vr) {
    $label = trim((string)($vr['Descripcio'] ?? ''));
    if ($label === '') $label = (string)$vr['Nom_Arxiu'];
    $validIndex[(int)$vr['ID_Rider']] = [
      'label' => $label !== '' ? $label : ('RD'.$vr['ID_Rider']),
      'uid'   => (string)$vr['Rider_UID'],
    ];
  }

  /* ── Barra d’ús d’espai amb deduplicació per SHA-256 ──────────────────── */
$quotaEnv   = getenv('USER_QUOTA_BYTES') ?: ($_ENV['USER_QUOTA_BYTES'] ?? '');
$quotaBytes = (int)$quotaEnv ?: 500 * 1024 * 1024;

$shaToBytes = [];

// Riders → sha => mida màxima trobada
try {
  $st = $pdo->prepare("
    SELECT TRIM(COALESCE(Hash_SHA256,'')) AS sha, MAX(COALESCE(Mida_Bytes,0)) AS sz
    FROM Riders
    WHERE ID_Usuari = :uid AND COALESCE(Hash_SHA256,'') <> ''
    GROUP BY Hash_SHA256
  ");
  $st->execute([':uid' => (int)$targetUserId]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $sha = (string)$row['sha']; $sz = (int)$row['sz'];
    if ($sha === '') continue;
    $shaToBytes[$sha] = max($shaToBytes[$sha] ?? 0, $sz);
  }
} catch (Throwable $e) {
  // Si no hi ha hash a Riders, farem fallback més avall
  $st = $pdo->prepare("SELECT COALESCE(SUM(Mida_Bytes),0) FROM Riders WHERE ID_Usuari = :uid");
  $st->execute([':uid' => (int)$targetUserId]);
  $shaToBytes['__FALLBACK_RIDERS__'] = (int)$st->fetchColumn();
}

// Documents (accepta columna SHA256 o Hash_SHA256)
$docsDone = false;
foreach (['SHA256','Hash_SHA256'] as $docShaCol) {
  try {
    $sql = "
      SELECT TRIM(COALESCE($docShaCol,'')) AS sha, MAX(COALESCE(Mida_Bytes,0)) AS sz
      FROM Documents
      WHERE ID_Usuari = :uid AND COALESCE($docShaCol,'') <> ''
      GROUP BY $docShaCol
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => (int)$targetUserId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $sha = (string)$row['sha']; $sz = (int)$row['sz'];
      if ($sha === '') continue;
      $shaToBytes[$sha] = max($shaToBytes[$sha] ?? 0, $sz);
    }
    $docsDone = true;
    break; // ja tenim una columna vàlida
  } catch (Throwable $e) { /* prova la següent columna */ }
}
if (!$docsDone) {
  // Taula o columna no existeix → suma simple i prou
  try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(Mida_Bytes),0) FROM Documents WHERE ID_Usuari = :uid");
    $st->execute([':uid' => (int)$targetUserId]);
    $shaToBytes['__FALLBACK_DOCS__'] = ($shaToBytes['__FALLBACK_DOCS__'] ?? 0) + (int)$st->fetchColumn();
  } catch (Throwable $e) { /* cap Documents → 0 */ }
}

// Total deduplicat
$usedBytes = 0;
foreach ($shaToBytes as $sha => $sz) { $usedBytes += (int)$sz; }

$percent_used = $quotaBytes > 0 ? (int)round(($usedBytes / $quotaBytes) * 100) : 0;
$percent_used = max(0, min(100, $percent_used));

if     ($percent_used <= 65) { $barColor = "bg-success"; }
elseif ($percent_used <= 80) { $barColor = "bg-warning"; }
else                         { $barColor = "bg-danger"; }

function humanBytes(int $b): string {
  $u = ['B','KB','MB','GB','TB'];
  $i = 0; $v = (float)$b;
  while ($v >= 1024 && $i < count($u)-1) { $v /= 1024; $i++; }
  return number_format($v, ($i===0?0:1)) . ' ' . $u[$i];
}
  ?>
  <!-- Taula normal -->
  <div class="container-fluid my-0 pt-3">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <div class="table-responsive-md overflow-visible">
          <table class="table table-sm table-hover align-middle fw-lighter small mb-0 table-riders">
            <thead class="table-dark align-top">
              <tr>
                <!-- ID -->
                <th class="text-center fw-lighter col-id" style="color: inherit;">
                  <?= h(__('riders.table.col_id')) ?>
                </th>
                <!-- Descripció (ordenable) -->
                <th style="color: inherit;">
                  <a class="link-light text-decoration-none" href="<?= h(sort_url('title','asc')) ?>">
                    <?= h(__('riders.table.col_desc')) ?>
                    <i class="bi bi-arrow-down-up ms-1"></i>
                    <?php if ($sortKey === 'title'): ?>
                      <span class="ms-1"><?= ($dir==='ASC'?'↑':'↓') ?></span>
                    <?php endif; ?>
                  </a>
                </th>
                <!-- Referència (ordenable) -->
                <th style="color: inherit;">
                  <a class="link-light text-decoration-none" href="<?= h(sort_url('ref','asc')) ?>">
                    <?= h(__('riders.table.col_ref')) ?>
                    <i class="bi bi-arrow-down-up ms-1"></i>
                    <?php if ($sortKey === 'ref'): ?>
                      <span class="ms-1"><?= ($dir==='ASC'?'↑':'↓') ?></span>
                    <?php endif; ?>
                  </a>
                </th>
                <!-- Data pujada (fixa, sense ordenar) -->
                <th class="text-center" style="color: inherit;">
                  <i class="bi bi-cloud-upload" title="<?= h(__('riders.table.col_uploaded')) ?>"></i>
                </th>
                <!-- Data publicació (ordenable) -->
                <th class="text-center" style="color: inherit;">
                  <a class="link-light text-decoration-none" href="<?= h(sort_url('published','desc')) ?>">
                    <i class="bi bi-calendar2-week" title="<?= h(__('riders.table.col_published')) ?>"></i>
                    <i class="bi bi-arrow-down-up ms-1"></i>
                    <?php if ($sortKey === 'published'): ?>
                      <span class="ms-1"><?= ($dir==='ASC'?'↑':'↓') ?></span>
                    <?php endif; ?>
                  </a>
                </th>
                <!-- Puntuació IA (icona, sense ordenar) -->
                <th class="text-center fw-lighter col-score" style="color: inherit;">
                  <i class="bi bi-heart" title="<?= h(__('riders.table.col_score')) ?>"></i>
                </th>
                <!-- Estat segell (icona, sense ordenar) -->
                <th class="text-center fw-lighter col-seal" style="color: inherit;">
                  <i class="bi bi-shield-shaded" title="<?= h(__('riders.table.col_seal')) ?>"></i>
                </th>
                <!-- Redirecció (fixa) -->
                <th style="color: inherit;" class="redirect-cell">
                  <?= h(__('riders.table.col_redirect')) ?>
                </th>
                <!-- Accions (fixa) -->
                <th class="text-center" style="color: inherit;">&nbsp;</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$riders): ?>
              <tr><td colspan="9" class="text-center text-secondary py-4"><?= h($L['no_riders']) ?></td></tr>
            <?php else: foreach ($riders as $r):
            $id         = (int)$r['ID_Rider'];
            $uid        = (string)$r['Rider_UID'];
            $nom        = (string)($r['Nom_Arxiu'] ?? '');
            $desc       = trim((string)($r['Descripcio'] ?? ''));
            $ref        = trim((string)($r['Referencia'] ?? ''));
            $estat      = (string)($r['Estat_Segell'] ?? '');
            $estatNorm  = segell_norm($estat);
            $redirId    = $r['rider_actualitzat'] !== null ? (int)$r['rider_actualitzat'] : null;
            $canExpire = ($isOwn && $estatNorm === 'validat'); // propietari pot caducar si està validat
            $canReupload = !segell_es_valid($estatNorm) && !segell_es_caducat($estatNorm);
            [$icon, $color, $title] = segell_icon_class($estat);
            $displayName = $desc !== '' ? $desc : $nom;
            $redirUid    = $redirId && isset($validIndex[$redirId]) ? $validIndex[$redirId]['uid'] : '';

            // Dates segures
            $pujadaDt = safe_dt($r['Data_Pujada'] ?? null);
            $publiDt  = safe_dt($r['Data_Publicacio'] ?? null);
            $manualDt = safe_dt($r['Validacio_Manual_Data'] ?? null);
            $pujadaStr = $pujadaDt ? $pujadaDt->format('d/m/Y') : '—';
            $publiStr  = $publiDt  ? $publiDt->format('d/m/Y')  : '—';
            $manualStr = $manualDt ? $manualDt->format('d/m/Y H:i') : '';

            // Estat sol·licitud manual
            $lastJobUid = (string)($r['last_job_uid'] ?? '');
            $iaDetailHref = BASE_PATH . 'espai.php?seccio=ia_detail&job=' . rawurlencode($lastJobUid);
            $scoreRaw   = $r['Valoracio'];
            $score      = is_null($scoreRaw) ? null : (int)$scoreRaw;
            $manualReq  = (int)($r['Validacio_Manual_Solicitada'] ?? 0);
            $hasAI      = !empty($r['Data_IA']); // ✅ S'ha executat “Validar amb IA”
            $canRequestHuman = $hasAI && !in_array($estatNorm, ['validat','caducat'], true);
            $alreadyRequested = ($manualReq === 1);
            $iaActive   = (int)($r['ia_active'] ?? 0) > 0;

            // Pendent de validació tècnica si sol·licitat i el segell NO és validat/caducat
            $pendingTech = $alreadyRequested && !in_array($estatNorm, ['validat','caducat'], true);
            $rowClass = trim(($pendingTech ? 'row-pending-tech' : '') . ' ' . ($estatNorm==='validat' ? 'row-validated' : ''));

            // Textos amb fallback 100% string
            $txtReq                 = (string)(__('riders.actions.request_human_validation') ?: 'Demanar validació humana');
            $txtReqUnavailable      = (string)(__('riders.actions.request_human_unavailable') ?: 'No disponible');
            $txtHumanRequested      = (string)(__('riders.actions.human_requested') ?: 'Sol·licitada');
            $txtHumanRequestedOnTpl = (string)(__('riders.actions.human_requested_on') ?: 'Sol·licitada el %s');
            $txtHumanRequestedOn    = $manualStr !== '' ? sprintf($txtHumanRequestedOnTpl, $manualStr) : $txtHumanRequested;

            // ALIASES per compatibilitat amb el teu HTML actual
            $txtRequestedOn = $txtHumanRequestedOn;
            $txtUnavailable = $txtReqUnavailable;
            ?>
              <tr class="<?= h($rowClass) ?>"
                data-row-uid="<?= h($uid) ?>"
                data-manual-req="<?= $alreadyRequested ? '1' : '0' ?>">
                <th class="text-center text-secondary fw-lighter col-id"><?= h((string)$id) ?></th>
                <td>
                  <span class="meta-view" data-field="desc"><?= h($displayName) ?></span>
                  <?php if ($canReupload && ($isOwn || $isAdmin)): ?>
                    <input type="text" class="form-control form-control-sm d-none meta-input"
                      data-field="desc" value="<?= h($displayName) ?>">
                  <?php endif; ?>
                </td>
                <td>
                  <span class="meta-view" data-field="ref"><?= h($ref) ?></span>
                  <?php if ($canReupload && ($isOwn || $isAdmin)): ?>
                    <input type="text" class="form-control form-control-sm d-none meta-input"
                      data-field="ref" value="<?= h($ref) ?>">
                  <?php endif; ?>
                </td>
                <td class="text-center"><?= h($pujadaStr) ?></td>
                <td class="text-center"><span class="pub-date" data-uid="<?= h($uid) ?>"><?= h($publiStr) ?></span></td>
                <?php
                $scoreColor = '';
                if (is_int($score)) {
                  if ($score >= 81)       $scoreColor = 'text-success'; // verd
                  elseif ($score >= 61)   $scoreColor = 'text-warning'; // taronja
                  elseif ($score >= 1)    $scoreColor = 'text-danger';  // vermell
                  else                    $scoreColor = '';                         // 0 o nul
                }
                ?>
                <td class="text-center col-score score-cell">
                  <span class="<?= h($scoreColor) ?>">
                    <?= h($score !== null ? (string)$score : '—') ?>
                  </span>
                </td>
                <!-- Estat segell (icona + icona canvi segell + select a la mateixa línia per admin) -->
                <td class="text-center col-seal" data-bs-toggle="tooltip" data-bs-title="<?= h($title) ?>">
                  <div class="seal-stack">
                  <!-- Icona del segell (la veuen tothom) -->
                    <i class="seal-icon bi <?= h($icon) ?> <?= h($color) ?>"
                      data-estat="<?= h($estatNorm) ?>"
                      data-uid="<?= h($uid) ?>">
                    </i>
                  <!-- 👇 Contenidor estable per al botó "caducar" (es pot injectar/ocultar via JS) -->
                    <span class="expire-wrap"
                      data-can-expire-owner="<?= $isOwn ? '1' : '0' ?>"
                      data-uid="<?= h($uid) ?>"
                      data-id="<?= (int)$id ?>"
                      data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>">
                      <?php if ($canExpire): ?>
                      <button type="button"
                        class="btn btn-link btn-sm text-danger px-1 expire-btn"
                        data-id="<?= (int)$id ?>"
                        data-uid="<?= h($uid) ?>"
                        data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
                        data-bs-toggle="tooltip"
                        data-bs-title="<?= h(__('riders.actions.expire') ?: 'Caducar') ?>"
                        aria-label="<?= h(__('riders.actions.expire') ?: 'Caducar') ?>">
                        <i class="bi bi-shield-x"></i>
                      </button>
                      <?php endif; ?>
                    </span>

                  <!-- Selector de segell només per a admins -->
                    <?php if ($isAdmin):
                    $labelSeal = match ($estatNorm) {
                      'validat' => (string)(__('riders.seal.opt_valid')   ?: 'Validat'),
                      'pendent' => (string)(__('riders.seal.opt_pending') ?: 'Pendent'),
                      'caducat' => (string)(__('riders.seal.opt_expired') ?: 'Caducat'),
                      default   => (string)(__('riders.seal.opt_none')    ?: 'Cap'),
                    };
                    ?>
                    <div class="btn-group btn-group-sm">
                      <button
                        type="button"
                        data-bs-display="static"
                        class="btn btn-sm btn-outline-secondary dropdown-toggle seal-dd"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        data-uid="<?= h($uid) ?>"
                        data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
                        data-bs-title="<?= h($labelSeal) ?>"
                        aria-label="<?= h($labelSeal) ?>">
                        <span class="seal-dd-label" hidden><?= h($labelSeal) ?></span>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item seal-option" data-value="cap">
                          <?= h(__('riders.seal.opt_none')    ?: 'Cap') ?>
                          </a>
                        </li>
                        <li><a class="dropdown-item seal-option" data-value="pendent">
                          <?= h(__('riders.seal.opt_pending') ?: 'Pendent') ?>
                          </a>
                        </li>
                        <li><a class="dropdown-item seal-option" data-value="caducat">
                          <?= h(__('riders.seal.opt_expired') ?: 'Caducat') ?>
                          </a>
                        </li>
                      </ul>
                    </div>
                  <?php endif; ?>
                  </div>
                </td>
                <!-- Redirecció -->
                <td class="redirect-cell">
                <?php if ($isOwn || $isAdmin): ?>
                  <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm redirect-select"
                      data-uid="<?= h($uid) ?>"
                      data-status="<?= h($estatNorm) ?>"
                      data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
                      <?= segell_es_caducat($estatNorm) ? '' : 'disabled' ?>>
                      <option value=""><?= h($L['redirect_none']) ?></option>
                      <?php foreach ($validated as $vr):
                        $vid  = (int)$vr['ID_Rider'];
                        if ($vid === $id) continue;
                        $vlab = trim((string)($vr['Descripcio'] ?? ''));
                        if ($vlab === '') $vlab = (string)$vr['Nom_Arxiu'];
                        if ($vlab === '') $vlab = 'RD'.$vid;
                      ?>
                      <option value="<?= h((string)$vid) ?>" <?= ($redirId===$vid ? 'selected' : '') ?>>
                        <?= h((string)$vid) ?> — <?= h($vlab) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                    <span class="redirect-chip small">
                      <?php if ($redirUid !== ''): ?>
                      <a class="text-decoration-none text-secondary link-primary" target="_blank" rel="noopener"
                        href="<?= h(BASE_PATH.'visualitza.php?ref='.rawurlencode($redirUid)) ?>">
                        <i class="bi bi-box-arrow-up-right"></i>
                      </a>
                      <?php endif; ?>
                    </span>
                  </div>
                  <?php else:
                  if ($redirUid !== ''): ?>
                  <span class="badge text-bg-secondary"><?= h(__('riders.redirect.to')) ?>
                    <a class="link-light text-decoration-underline"
                      href="<?= h(BASE_PATH.'visualitza.php?ref='.rawurlencode($redirUid)) ?>"><?= h((string)$redirId) ?>
                    </a>
                  </span>
                  <?php else: ?>
                  <span class="text-secondary">—</span>
                  <?php endif; ?>
                <?php endif; ?>
                </td>
      
                <!-- Accions -->
                <?php
                  $canValidate   = ($estatNorm === 'cap' || $estatNorm === 'pendent');     // IA activa si cap|pendent
                  $canCopyView   = ($estatNorm === 'validat' || $estatNorm === 'caducat'); // Copiar/view si validat|caducat
                  $canSealAuto   = ($score !== null && $score > 80)
                  && !in_array($estatNorm, ['validat','caducat'], true)
                  && ($manualReq === 0);
                ?>
                <td class="text-center">
                  <div class="btn-group me-2 flex-nowrap" role="group">
                    <div class="btn-group btn-group-sm flex-nowrap" role="group">
                    <!-- Editar/Guardar -->
                    <?php if ($canReupload && ($isOwn || $isAdmin)): ?>
                      <button type="button"
                        class="btn btn-primary btn-sm meta-edit-btn"
                        data-uid="<?= h($uid) ?>"
                        data-id="<?= (int)$id ?>"
                        data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
                        data-bs-toggle="tooltip" data-bs-title="<?= h(__('common.edit') ?: 'Edita meta') ?>"
                        aria-label="<?= h(__('common.edit') ?: 'Edita meta') ?>">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                      <button type="button"
                        class="btn btn-primary btn-sm d-none meta-save-btn"
                        data-uid="<?= h($uid) ?>"
                        data-id="<?= (int)$id ?>"
                        data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
                        data-bs-toggle="tooltip" data-bs-title="<?= h(__('common.save') ?: 'Desa') ?>"
                        aria-label="<?= h(__('common.save') ?: 'Desa') ?>">
                        <i class="bi bi-check2"></i>
                      </button>
                      <button type="button"
                        class="btn btn-secondary btn-sm d-none meta-cancel-btn"
                        data-uid="<?= h($uid) ?>">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    <?php else: ?>
                      <button type="button"
                        class="btn btn-secondary btn-sm meta-edit-btn"
                        disabled
                        data-bs-toggle="tooltip" data-bs-title="<?= h(__('common.edit') ?: 'Edita meta') ?>"
                        aria-label="<?= h(__('common.edit') ?: 'Edita meta') ?>">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                    <?php endif; ?>
                    <!-- Repujar rider -->
                    <?php if ($canReupload && ($isOwn || $isAdmin)): ?>
                      <button type="button"
                        class="btn btn-primary btn-sm reupload-btn"
                        data-uid="<?= h($uid) ?>"
                        data-id="<?= (int)$id ?>"
                        data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
                        data-bs-toggle="tooltip" data-bs-title="<?= h(__('riders.actions.reupload') ?: 'Repujar versió') ?>"
                        aria-label="<?= h(__('riders.actions.reupload') ?: 'Repujar versió') ?>">
                          <i class="bi bi-arrow-repeat"></i>
                      </button>
                      <!-- file input ocult per a aquest rider -->
                      <input type="file" accept="application/pdf"
                        class="d-none reupload-input"
                        data-uid="<?= h($uid) ?>">
                    <?php else: ?>
                      <button type="button" class="btn btn-secondary btn-sm" disabled
                        data-bs-toggle="tooltip" data-bs-title="<?= h(__('riders.actions.reupload') ?: 'Repujar versió') ?>"
                        aria-label="<?= h(__('riders.actions.reupload') ?: 'Repujar versió') ?>">
                        <i class="bi bi-arrow-repeat"></i>
                      </button>
                    <?php endif; ?>  
                    <!-- Validar amb IA -->
                    <?php if ($canValidate && !$iaActive): ?>
                      <a href="<?= h(BASE_PATH) ?>espai.php?seccio=analitza&id=<?= (int)$r['ID_Rider'] ?>"
                         class="btn btn-primary btn-sm"
                         data-bs-toggle="tooltip"
                         data-bs-title="<?= h($L['validate_ai']) ?>"
                         aria-label="<?= h($L['validate_ai']) ?>">
                         <i class="bi bi-lightning-charge"></i>
                      </a>
                    <?php elseif ($iaActive): ?>
                      <button type="button"
                              class="btn btn-warning btn-sm"
                              data-ai-pending="1"
                              disabled
                              data-bs-toggle="tooltip"
                              data-bs-title="<?= h(__('riders.ia_pending.running') ?: 'IA en curs') ?>"
                              aria-label="<?= h(__('riders.ia_pending.running') ?: 'IA en curs') ?>">
                        <i class="bi bi-lightning-charge"></i>
                      </button>
                    <?php else: ?>
                      <button type="button"
                              class="btn btn-secondary btn-sm"
                              disabled
                              data-bs-toggle="tooltip"
                              data-bs-title="<?= h(__('riders.seal.valid')) ?> / <?= h(__('riders.seal.expired')) ?>"
                              aria-label="<?= h(__('riders.seal.valid')) ?> / <?= h(__('riders.seal.expired')) ?>">
                        <i class="bi bi-lightning-charge"></i>
                      </button>
                    <?php endif; ?>
                    <!-- Detall d'IA (obre el darrer job si existeix) -->
                    <?php if ($lastJobUid !== ''): ?>
                    <a href="<?= h($iaDetailHref) ?>"
                      class="btn btn-primary btn-sm"
                      data-bs-toggle="tooltip"
                      data-bs-title="<?= h($L['ia_detall']) ?>"
                      aria-label="<?= h($L['ia_detall']) ?>">
                      <i class="bi bi-robot"></i>
                    </a>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm" disabled data-bs-toggle="tooltip" data-bs-title="<?= h($L['ia_detall']) ?>" aria-label="<?= h($L['ia_detall']) ?>"><i class="bi bi-robot"></i></button>
                    <?php endif; ?>
                    <!-- Validació humana -->
                    <?php if ($canRequestHuman && !$alreadyRequested): ?>
                    <button type="button"
                      class="btn btn-primary btn-sm request-human-btn"
                      data-id="<?= (int)$id ?>"
                      data-uid="<?= h($uid) ?>"
                      data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
                      data-bs-toggle="tooltip"
                      data-bs-title="<?= h($txtReq) ?>"
                      aria-label="<?= h($txtReq) ?>">
                      <i class="bi bi-person-check"></i>
                    </button>
                    <?php elseif ($alreadyRequested): ?>
                    <button type="button"
                      class="btn btn-warning btn-sm"
                      disabled
                      data-bs-toggle="tooltip"
                      data-bs-title="<?= h($txtRequestedOn) ?>"
                      aria-label="<?= h($txtRequestedOn) ?>">
                      <i class="bi bi-hourglass-split"></i>
                    </button>
                    <?php else: ?>
                    <button type="button"
                      class="btn btn-secondary btn-sm"
                      disabled
                      data-bs-toggle="tooltip"
                      data-bs-title="<?= h($txtUnavailable) ?>"
                      aria-label="<?= h($txtUnavailable) ?>">
                      <i class="bi bi-person-check"></i>
                    </button>
                    <?php endif; ?>
                    <!-- Validació de segell automàtica -->
                    <?php if ($canSealAuto && ($isOwn || $isAdmin)): ?>
                    <button type="button"
                      class="btn btn-primary btn-sm auto-seal-btn"
                      data-uid="<?= h($uid) ?>"
                      data-id="<?= (int)$id ?>"
                      data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
                      data-bs-toggle="tooltip"
                      data-bs-title="<?= h(__('riders.actions.auto_seal') ?: 'Validar segell') ?>"
                      aria-label="<?= h(__('riders.actions.auto_seal') ?: 'Validar segell') ?>">
                      <i class="bi bi-patch-check"></i>
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm" disabled
                      data-bs-toggle="tooltip"
                      data-bs-title="<?= h(__('riders.actions.auto_seal') ?: 'Validar segell') ?>"
                      aria-label="<?= h(__('riders.actions.auto_seal') ?: 'Validar segell') ?>">
                      <i class="bi bi-patch-check"></i>
                    </button>
                    <?php endif; ?>
                    <!-- Obtenir SHA256 -->
                    <?php
                    $isFinal = in_array($estatNorm, ['validat','caducat'], true);
                    $hash256   = trim((string)($r['Hash_SHA256'] ?? ''));
                    $publicUrl = abs_base() . rtrim(BASE_PATH, '/') . '/visualitza.php?ref=' . rawurlencode($uid);
                    ?>
                    <?php if ($isFinal): ?>
                    <button type="button"
                      class="btn btn-primary btn-sm seal-info-btn"
                      data-when="<?= h($publiStr) ?>"
                      data-hash="<?= h($hash256) ?>"
                      data-url="<?= h($publicUrl) ?>"
                      data-uid="<?= h($uid) ?>"
                      data-bs-toggle="tooltip"
                      data-bs-title="<?= h(__('riders.seal.info') ?: 'Info segell') ?>"
                      aria-label="<?= h(__('riders.seal.info') ?: 'Info segell') ?>">
                      <i class="bi bi-info-circle"></i>
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm" disabled
                      data-bs-toggle="tooltip" data-bs-title="<?= h(__('riders.seal.info') ?: 'Info segell') ?>"
                      aria-label="<?= h(__('riders.seal.info') ?: 'Info segell') ?>">
                      <i class="bi bi-info-circle"></i>
                    </button>
                    <?php endif; ?>
                    <!-- Copiar enllaç públic -->
                    <?php if ($canCopyView): ?>
                    <button type="button"
                      class="btn btn-primary btn-sm copy-link-btn"
                      data-uid="<?= h($uid) ?>"
                      data-bs-toggle="tooltip" data-bs-title="<?= h($L['copy']) ?>"
                      aria-label="<?= h($L['copy']) ?>">
                      <i class="bi bi-qr-code"></i>
                    </button>
                    <?php else: ?>
                    <button type="button"
                      class="btn btn-secondary btn-sm"
                      disabled
                      data-bs-toggle="tooltip" data-bs-title="<?= h($L['copy']) ?>"
                      aria-label="<?= h($L['copy']) ?>">
                      <i class="bi bi-qr-code"></i>
                    </button>
                    <?php endif; ?>
                    <!-- Veure fitxa (pàgina pública) -->
                    <?php if ($canCopyView): ?>
                    <a href="<?= h(BASE_PATH.'visualitza.php?ref='.rawurlencode($uid)) ?>"
                      class="btn btn-primary btn-sm" target="_blank" rel="noopener"
                      data-bs-toggle="tooltip" data-bs-title="<?= h($L['view_card']) ?>"
                      aria-label="<?= h($L['view_card']) ?>">
                      <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm" disabled
                      data-bs-toggle="tooltip" data-bs-title="<?= h($L['view_card']) ?>"
                      aria-label="<?= h($L['view_card']) ?>">
                      <i class="bi bi-box-arrow-up-right"></i>
                    </button>
                    <?php endif; ?>
                    <!-- Eye: veure PDF (sempre actiu) -->
                    <a href="<?= h(BASE_PATH) ?>php/rider_file.php?ref=<?= h($uid) ?>"
                      class="btn btn-primary btn-sm" target="_blank" rel="noopener"
                      data-bs-toggle="tooltip" data-bs-title="<?= h($L['open_pdf']) ?>"
                      aria-label="<?= h($L['open_pdf']) ?>">
                      <i class="bi bi-eye"></i>
                    </a>                    
                    <!-- Visites al rider -->
                    <?php if ($canCopyView): ?>
                    <button type="button" class="btn btn-primary btn-sm"
                      onclick="window.location.href='<?= h(BASE_PATH) ?>php/owner_rider_views.php?rid=<?= (int)$r['ID_Rider'] ?>';"
                      data-bs-toggle="tooltip" data-bs-title="<?= h(t('own.riders.titol') ?? 'Visites al rider') ?>"
                      aria-label="Visits">
                      <i class="bi bi-rocket-takeoff"></i>
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm" disabled
                      data-bs-toggle="tooltip" data-bs-title="Parat"
                      aria-label="">
                      <i class="bi bi-rocket-takeoff"></i>
                    </button>
                    <?php endif ?>
                  </div>
                  <!-- Delete (igual que abans) -->
                  <?php if ($isOwn || $isAdmin): ?>
                  <div class="ps-1">
                    <form method="POST"
                      action="<?= h(BASE_PATH) ?>php/delete_rider.php"
                      class="d-inline-block js-del-form"
                      data-uid="<?= h($uid) ?>"
                      data-id="<?= (int)$id ?>"
                      data-desc="<?= h($displayName) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="rider_id" value="<?= (int)$id ?>">
                      <input type="hidden" name="rider_uid" value="<?= h($uid) ?>">
                      <button type="button"
                        class="btn btn-danger btn-sm js-del-btn"
                        data-bs-toggle="tooltip"
                        data-bs-title="<?= h(__('riders.actions.delete')) ?>"
                        aria-label="<?= h(__('riders.actions.delete')) ?>">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </form>
                  </div>
                  <?php endif; ?>
                </div>
              </td>      
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="9" class="p-0">
                <div class="px-3 py-2 d-lg-flex justify-content-between align-items-center gap-3">
                  <!-- Ús d'espai -->
                  <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                      <small class="text-muted">
                        <?= h($L['space_usage']) ?>:
                        <span class="text-body"><?= h(humanBytes($usedBytes)) ?></span>
                        / <?= h(humanBytes($quotaBytes)) ?> ·
                        <?= h((string)$percent_used) ?>% <?= h($L['percent_used']) ?>
                      </small>
                      <div class="progress flex-grow-1" style="height:16px; max-width:360px;">
                        <div class="progress-bar <?= h($barColor) ?>"
                             role="progressbar"
                             style="width: <?= h((string)$percent_used) ?>%;"></div>
                      </div>
                    </div>
                  </div>
                  <!-- Paginació + per_page -->
                  <div class="mt-2 mt-lg-0 d-flex justify-content-lg-end justify-content-start align-items-center gap-2 flex-wrap">
                    <?php if ($totalPages > 1): ?>
                      <nav aria-label="Paginació">
                        <ul class="pagination pagination-sm mb-0">
                          <?php
                            $q = $_GET;
                            $makeUrl = function($pageN) use ($q) {
                              $q['page'] = max(1,(int)$pageN);
                              return '?' . http_build_query($q);
                            };
                            $disabled = fn($cond) => $cond ? 'disabled' : '';
                            $active   = fn($cond) => $cond ? 'active'   : '';
                            $win = 2;
                            $from = max(1, $page - $win);
                            $to   = min($totalPages, $page + $win);
                          ?>
                          <li class="page-item <?= $disabled($page<=1) ?>">
                            <a class="page-link" href="<?= h($makeUrl($page-1)) ?>" tabindex="-1">&laquo;</a>
                          </li>
                          <?php if ($from > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= h($makeUrl(1)) ?>">1</a></li>
                            <?php if ($from > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                          <?php endif; ?>
                          <?php for ($p=$from; $p<=$to; $p++): ?>
                            <li class="page-item <?= $active($p===$page) ?>"><a class="page-link" href="<?= h($makeUrl($p)) ?>"><?= $p ?></a></li>
                          <?php endfor; ?>
                          <?php if ($to < $totalPages): ?>
                            <?php if ($to < $totalPages-1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= h($makeUrl($totalPages)) ?>"><?= $totalPages ?></a></li>
                          <?php endif; ?>
                          <li class="page-item <?= $disabled($page>=$totalPages) ?>">
                            <a class="page-link" href="<?= h($makeUrl($page+1)) ?>">&raquo;</a>
                          </li>
                        </ul>
                      </nav>
                    <?php endif; ?>
                    <form method="get" class="d-inline">
                      <?php foreach ($_GET as $k => $v): ?>
                        <?php if (!in_array($k, ['per_page','page'])): ?>
                          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                        <?php endif; ?>
                      <?php endforeach; ?>
                      <select name="per_page" 
                        class="form-select form-select-sm d-inline-block w-auto"  
                        onchange="this.form.submit()">
                        <?php foreach ([10,20,50,100] as $pp): ?>
                          <option value="<?= $pp ?>" <?= ($perPage == $pp ? 'selected' : '') ?>><?= $pp ?>/pag</option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
          </tfoot>
        </table> 
      </div>
    </div>
  </div>
</div>

<!-- Modal: Caducar rider -->
<div class="modal fade" id="expireModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow liquid-glass-kinosonik">
      <div class="modal-header bg-danger justify-content-center position-relative">
        <h6 class="modal-title text-center text-uppercase fw-bold">
          <?= h($L['expire.modal.lead'] ?: 'Aquesta acció és irreversible.') ?>
        </h6>
        <button type="button" class="btn-close position-absolute end-0 me-2" 
          data-bs-dismiss="modal" aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2 text-danger fw-semibold">
          <?= h($L['expire.modal.title'] ?: 'Caducar rider') ?>
        </p>
        <p class="mb-2 fw-lighter small">
          <?= h($L['expire.modal.body_intro'] ?: 'Si caducas aquest rider:') ?><br>
          <?= h($L['expire.modal.point_irrev'] ?: '• No es podrà tornar a validar.') ?><br>
          <?= h($L['expire.modal.point_redir'] ?: '• Podràs redireccionar-lo a un rider validat més nou des del selector de “Redirecció”.') ?>
        </p>
        <div class="border rounded p-2 small bg-kinosonik">
          <div class="row">
            <div class="col-4 text-muted"><?= h($L['expire.modal.id'] ?: 'ID') ?></div>
            <div class="col-8"><span id="exp-rider-id">—</span></div>
          </div>
          <div class="row">
            <div class="col-4 text-muted"><?= h($L['expire.modal.desc'] ?: 'Descripció') ?></div>
            <div class="col-8"><span id="exp-rider-desc">—</span></div>
          </div>
          <div class="row">
            <div class="col-4 text-muted"><?= h($L['expire.modal.ref'] ?: 'Referència') ?></div>
            <div class="col-8"><span id="exp-rider-ref">—</span></div>
          </div>
        </div>
        <!-- ✅ Checklist (switches Bootstrap) -->
        <div class="mt-3 small" id="exp-checklist">
          <div class="form-check form-switch mb-2 small">
            <input class="form-check-input exp-req" type="checkbox" role="switch" id="exp-ck-irreversible">
            <label class="form-check-label" for="exp-ck-irreversible">
              <?= h(__('riders.expire.ck.irreversible') ?: 'He entès que aquesta acció és irreversible i el rider no es podrà tornar a validar.') ?>
            </label>
          </div>
          <div class="form-check form-switch mb-2 small">
            <input class="form-check-input exp-req" type="checkbox" role="switch" id="exp-ck-unpublish">
            <label class="form-check-label" for="exp-ck-unpublish">
              <?= h(__('riders.expire.ck.unpublish') ?: 'He entès que aquest rider deixarà d’estar publicat/validat immediatament.') ?>
            </label>
          </div>
        </div>
      </div>

      <!-- ✅ El footer ha d’anar DINS de .modal-content -->
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
          <?= h($L['expire.modal.cancel'] ?: 'Cancel·la') ?>
        </button>
        <button type="button" class="btn btn-danger btn-sm" id="exp-confirm-btn" data-action="confirm" disabled>
          <?= h($L['expire.modal.confirm'] ?: 'Sí, caduca’l') ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Eliminar rider -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow liquid-glass-kinosonik">
      <div class="modal-header bg-danger text-white">
        <h6 class="modal-title mb-0" id="deleteModalLabel">
          <?= h(__('riders.actions.delete') ?: 'Eliminar rider') ?>
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"></button>
      </div>

      <div class="modal-body small">
        <p class="mb-2"><?= h(__('common.delete_confirm') ?: 'Segur que vols eliminar aquest element?') ?></p>

        <div class="border rounded p-2 bg-kinosonik">
          <div class="row">
            <div class="col-4 text-muted"><?= h(__('riders.filters.id') ?: 'ID Rider') ?></div>
            <div class="col-8"><span id="del-rider-id">—</span></div>
          </div>
          <div class="row">
            <div class="col-4 text-muted"><?= h(__('riders.upload.desc_label') ?: 'Descripció') ?></div>
            <div class="col-8"><span id="del-rider-desc">—</span></div>
          </div>
        </div>

        <!-- ✅ Switchs de seguretat -->
        <div class="mt-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input del-req" type="checkbox" id="del-ck-irreversible">
            <label class="form-check-label" for="del-ck-irreversible">
              <?= h(__('profile.delete_irreversible') ?: 'Entenc que aquesta acció és irreversible i esborrarà totes les dades.') ?>
            </label>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input del-req" type="checkbox" id="del-ck-purge">
            <label class="form-check-label" for="del-ck-purge">
              <?= h(__('riders.delete.note') ?: 'Aquesta acció és definitiva i també purgarà els logs i execucions d’IA associats.') ?>
            </label>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
          <?= h(__('common.cancel') ?: 'Cancel·la') ?>
        </button>
        <button type="button" class="btn btn-danger btn-sm" id="del-confirm-btn" disabled>
          <i class="bi bi-trash3 me-1"></i><?= h(__('common.delete') ?: 'Elimina') ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Info segell -->
<div class="modal fade" id="sealInfoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content shadow liquid-glass-kinosonik">
      <div class="modal-header">
        <h6 class="modal-title mb-0">
          <?= h(__('riders.seal.info.title') ?: 'Informació del segell') ?>
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"></button>
      </div>
      <div class="modal-body small">
        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <div class="text-muted"><?= h(__('riders.seal.published') ?: 'Publicat') ?></div>
            <div class="fw-semibold" id="seal-info-when">—</div>
          </div>
          <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center">
              <div class="text-muted mb-1">SHA-256</div>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="seal-copy-hash">
                <i class="bi bi-clipboard"></i> <?= h(__('common.copy') ?: 'Copia') ?>
              </button>
            </div>
            <code class="text-break d-block" id="seal-info-hash">—</code>
          </div>
          <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
              <div class="text-muted mb-1"><?= h(__('riders.seal.public_link') ?: 'Enllaç públic') ?></div>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="seal-copy-url">
                <i class="bi bi-clipboard"></i> <?= h(__('common.copy') ?: 'Copia') ?>
              </button>
            </div>
            <a href="#" target="_blank" rel="noopener" id="seal-info-url" class="text-truncate d-inline-block" style="max-width:100%;">—</a>
          </div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <small class="text-muted mb-0" id="seal-info-uid"></small>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
          <?= h(__('common.close') ?: 'Tanca') ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- TOAST -->
 <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
  <div id="ksToast" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>


<!-- Helper error de xarxa unificat -->
<script>
  // Mostra un missatge de xarxa consistent i evita molestar si estem marxant de la pàgina
  function _netFail(e) {
    if (window.__leavingPage) return;
    try { console.error(e); } catch (_) {}
    showToast(<?= json_encode($L['net_error']) ?>);
  }
</script>

<!-- JS: AJAX per segell + redirecció -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  // Bases (per si calen en el futur)
  const BASE_PATH = "<?= rtrim(BASE_PATH, '/') ?>/";
  const ABS_BASE  = "<?= defined('BASE_URL') ? rtrim(BASE_URL, '/') : '' ?>";

  // Helper: formatar data
  function formatDMY(isoOrMysql) {
    if (!isoOrMysql) return '—';
    const norm = String(isoOrMysql).replace(' ', 'T');
    const dt = new Date(norm);
    if (Number.isNaN(dt.getTime())) return '—';
    return String(dt.getDate()).padStart(2,'0') + '/' +
           String(dt.getMonth()+1).padStart(2,'0') + '/' +
           dt.getFullYear();
  }

  // Helper: aplicar icona segons estat
  const ICON_MAP = {
    cap:     ['bi-shield',              'text-secondary'],
    pendent: ['bi-shield-exclamation',  'text-warning'],
    validat: ['bi-shield-fill-check',   'text-success'],
    caducat: ['bi-shield-fill-x',       'text-danger'],
  };
  function applySealIcon(iconEl, estat) {
    const [ic, col] = ICON_MAP[estat] || ICON_MAP.cap;
    iconEl.className = 'bi ' + ic + ' ' + col + ' seal-icon';
  }

  // Textos localitzats per al botó del dropdown
  const LABEL_MAP = {
    validat: '<?= h(__('riders.seal.opt_valid')   ?: 'Validat') ?>',
    pendent: '<?= h(__('riders.seal.opt_pending') ?: 'Pendent') ?>',
    caducat: '<?= h(__('riders.seal.opt_expired') ?: 'Caducat') ?>',
    cap:     '<?= h(__('riders.seal.opt_none')    ?: 'Cap') ?>'
  };

  // 🎯 Click sobre opció del menú de segell
  document.addEventListener('click', async (ev) => {
    const opt = ev.target.closest('.seal-option');
    if (!opt) return;
    ev.preventDefault();

    const dd  = opt.closest('.btn-group');
    const btn = dd?.querySelector('button[data-uid]');
    if (!btn) return;

    const uid   = btn.getAttribute('data-uid') || '';
    const csrf  = btn.getAttribute('data-csrf') || '';
    const estatWanted = String(opt.dataset.value || 'cap').toLowerCase();

    const row      = btn.closest('tr');
    const pubSpan  = row ? row.querySelector('.pub-date[data-uid="'+uid+'"]') : null;
    const iconEl   = row ? row.querySelector('.seal-icon[data-uid="'+uid+'"]') : null;
    const redirSel = row ? row.querySelector('.redirect-select[data-uid="'+uid+'"]') : null;
    const expWrap  = row ? row.querySelector('.expire-wrap') : null;

    // Bloqueig breu del botó
    btn.disabled = true;

    try {
      const resp = await fetch(BASE_PATH + 'php/update_seal.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({ csrf, rider_uid: uid, estat: estatWanted })
      });
      const json = await resp.json().catch(() => ({}));
      if (!resp.ok || !json.ok) {
        showToast(<?= json_encode($L['seal_update_error']) ?> + (json?.error ? (': ' + json.error) : ''));
        return;
      }

      // Estat final (normalitzat) retornat pel backend
      const estatResp = String(json.data?.estat || 'cap').toLowerCase();

      // 1️⃣ Data publicació
      if (pubSpan) pubSpan.textContent = formatDMY(json.data?.data_publicacio || '');

      // 2️⃣ Icona principal
      if (iconEl) {
        applySealIcon(iconEl, estatResp);
        iconEl.setAttribute('data-estat', estatResp);
      }

      // 3️⃣ Botó “Caducar” (segona icona)
      if (expWrap) {
  // 💡 Dispose de tooltips existents dins expWrap
  expWrap.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    const t = bootstrap.Tooltip.getInstance(el);
    if (t) t.dispose();
  });

  expWrap.innerHTML = '';
  const canExpireOwner = expWrap.getAttribute('data-can-expire-owner') === '1';
  if (estatResp === 'validat' && canExpireOwner) {
    const id    = expWrap.getAttribute('data-id')   || '';
    const csrf2 = expWrap.getAttribute('data-csrf') || '';
    const uid2  = expWrap.getAttribute('data-uid')  || '';
    expWrap.innerHTML = `
      <button type="button"
              class="btn btn-link btn-sm text-danger px-1 expire-btn"
              data-id="${id}"
              data-uid="${uid2}"
              data-csrf="${csrf2}"
              data-bs-toggle="tooltip"
              data-bs-title="<?= h(__('riders.actions.expire') ?: 'Caducar') ?>"
              aria-label="<?= h(__('riders.actions.expire') ?: 'Caducar') ?>">
        <i class="bi bi-shield-x"></i>
      </button>`;
  }
  const newBtn = expWrap.querySelector('.expire-btn');
  if (newBtn && !bootstrap.Tooltip.getInstance(newBtn)) {
    new bootstrap.Tooltip(newBtn);
  }
}

      // 4️⃣ Redirecció: refresca opcions i habilitació segons estat
      if (redirSel) {
        if (typeof window.rebuildRedirectSelect === 'function') {
          window.rebuildRedirectSelect(
            redirSel,
            Array.isArray(json.data?.redirect_options) ? json.data.redirect_options : [],
            json.data?.redirect_selected || 0
          );
        }
        if (typeof window.applyRedirectAvailability === 'function') {
          window.applyRedirectAvailability(row, estatResp);
        }
      }

      // 5️⃣ Actualitza etiqueta/tooltip del botó del dropdown
      const newLabel = LABEL_MAP[estatResp] || LABEL_MAP.cap;
      const cap = btn.querySelector('.seal-dd-label');
      if (cap) cap.textContent = newLabel;
      btn.setAttribute('aria-label', newLabel);
      btn.setAttribute('data-bs-title', newLabel);
      const tip = bootstrap.Tooltip.getInstance(btn);
      if (tip && tip.setContent) {
        tip.setContent({ '.tooltip-inner': newLabel });
      } else if (tip) {
        btn.setAttribute('data-bs-original-title', newLabel);
      }

      // 6️⃣ Tanca el menú desplegable si està obert
      const ddMenu = btn.nextElementSibling;
      if (ddMenu?.classList.contains('show')) {
        const ddInstance = bootstrap.Dropdown.getOrCreateInstance(btn);
        ddInstance.hide();
      }

      // 7️⃣ (Opcional) marca fila com a pendent de validació tècnica si escau
      if (row) {
        const manualReq = row.getAttribute('data-manual-req') === '1';
        const isPendingTech = manualReq && !(estatResp === 'validat' || estatResp === 'caducat');
        row.classList.toggle('row-pending-tech', isPendingTech);
      }

    } catch (e) {
      // Evita molestar si és una navegació abortada
      if (e && (e.name === 'AbortError' || String(e).includes('abort'))) return;
      _netFail(e);
    } finally {
      btn.disabled = false;
    }
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
  });
});
</script>

<!-- JS: habilitar/deshabilitar redirecció segons estat del segell -->
<script>
(function(){
  function applyRedirectAvailability(row, estat) {
    const sel = row.querySelector('.redirect-select');
    if (!sel) return;
    const enable = (String(estat).toLowerCase() === 'caducat');
    sel.disabled = !enable;
    sel.setAttribute('data-status', String(estat).toLowerCase());
    if (!enable) sel.value = '';
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('tr[data-row-uid]').forEach(row => {
      const icon = row.querySelector('.seal-icon');
      const estat = (icon?.getAttribute('data-estat') || 'cap').toLowerCase();
      applyRedirectAvailability(row, estat);
    });
  });

  // expose per reutilitzar des d’altres blocs si cal
  window.applyRedirectAvailability = applyRedirectAvailability;
})();
</script>

<!-- JS: Copiar al portapapers l'enllaç públic -->
<script>
(function () {
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.copy-link-btn');
    if (!btn) return;

    const uid = btn.getAttribute('data-uid');
    if (!uid) return;

    // Construeix l'URL absolut: prioritzem BASE_URL si existeix, si no origin
    const basePath   = "<?= rtrim(BASE_PATH, '/') ?>";
    const absBase    = "<?= defined('BASE_URL') ? rtrim(BASE_URL, '/') : '' ?>";
    const originLike = absBase || window.location.origin;
    const absolute = originLike + basePath + "/visualitza.php?ref=" + encodeURIComponent(uid);

    try {
      await navigator.clipboard.writeText(absolute);

      let tip = bootstrap.Tooltip.getInstance(btn);
      if (!tip) { tip = new bootstrap.Tooltip(btn, { trigger: 'manual' }); }
      const originalTitle = btn.getAttribute('data-bs-original-title') || btn.getAttribute('title') || <?= json_encode($L['copy']) ?>;
      btn.setAttribute('title', <?= json_encode($L['copied']) ?>);
      btn.setAttribute('data-bs-original-title', <?= json_encode($L['copied']) ?>);
      tip.show();
      setTimeout(() => {
        btn.setAttribute('data-bs-original-title', originalTitle);
        tip.hide();
      }, 1200);
    } catch (e) {
      console.error(e);
      showToast(<?= json_encode(__('common.copy_failed') ?: 'No s’ha pogut copiar') ?> + "\n" + absolute);
    }
  });
})();
</script>

<!-- Upload rider -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form[action$="php/upload_rider.php"]');
  const btn  = document.getElementById('uploadBtn');
  if (!form || !btn) return;

  form.addEventListener('submit', () => {
    // 👇 Marca que sortim de la pàgina: evitarem alerts de fetch cancel·lats
    window.__leavingPage = true;
    // feedback immediat
    btn.disabled = true;
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-secondary');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' +
    <?= json_encode(__('loading.processing') ?: 'Processant…') ?>;

    // desactivar la resta NOMÉS després que el navegador ja hagi recollit els camps
    setTimeout(() => {
      form.querySelectorAll('input, select, textarea, button').forEach(el => {
        if (el === btn) return;           // ja està
        if (el.type === 'hidden') return; // no toquem ocults (inclou csrf)
        // no desactivis res abans de l’enviament efectiu
        el.disabled = true;
      });
    }, 50);
  });
});
</script>
<script>
(function () {
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.request-human-btn');
    if (!btn) return;

    const id   = btn.getAttribute('data-id');
    const uid  = btn.getAttribute('data-uid');
    const csrf = btn.getAttribute('data-csrf');
    if (!id || !csrf) return;

    btn.disabled = true;
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>';

    try {
      const resp = await fetch('<?= h(BASE_PATH) ?>php/request_human_validation.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({ csrf, rider_id: id })
      });

      const json = await resp.json().catch(()=>({}));
      if (!resp.ok || !json.ok) {
        showToast(<?= json_encode($L['net_error']) ?> + (json.error ? (': ' + json.error) : ''));
        btn.disabled = false;
        btn.innerHTML = oldHtml;
        return;
      }

      // Canvia el botó a estat “sol·licitat” en groc
      btn.classList.remove('btn-outline-primary', 'btn-secondary', 'btn-primary');
      btn.classList.add('btn-warning');

      const tipText = json.message || 'Sol·licitada';
      btn.setAttribute('data-bs-title', tipText);
      btn.setAttribute('title', tipText); // fallback

      btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
      btn.disabled = true; // evitar dobles clics
      
      // Després de posar el botó en groc i desactivar-lo:
const row = btn.closest('tr');
if (row) {
  row.classList.add('row-pending-tech');
  row.setAttribute('data-manual-req', '1'); // perquè el canvi de segell després ho sàpiga
}

      // Tooltip: (re)inicialitza i actualitza text
      let tip = bootstrap.Tooltip.getInstance(btn);
      if (!tip) tip = new bootstrap.Tooltip(btn);
      if (tip.setContent) {
        tip.setContent({ '.tooltip-inner': tipText });
      } else {
        // Compatibilitat amb versions anteriors
        btn.setAttribute('data-bs-original-title', tipText);
      }

    } catch (e) {
      if (e && (e.name === 'AbortError' || String(e).includes('abort'))) return;
      _netFail(e);
      btn.disabled = false;
      btn.innerHTML = oldHtml;
    }
  });
})();
</script>
<!-- Tanca quadre riders pendents -->
<script>
document.addEventListener('click', (ev) => {
  const closeBtn = ev.target.closest('[data-close-target]');
  if (!closeBtn) return;
  const sel = closeBtn.getAttribute('data-close-target');
  const box = sel ? document.querySelector(sel) : null;
  if (!box) return;
  // Tanquem tota la targeta (card)
  const card = box.closest('.card') || box;
  const container = card.closest('.container') || card;
  container.remove();
});
</script>
<!-- Inicialitza la franja en carregar pàgina -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('tr[data-row-uid]').forEach(row => {
    const icon  = row.querySelector('.seal-icon');
    const estat = (icon?.getAttribute('data-estat') || 'cap').toLowerCase();
    const manualReq = row.getAttribute('data-manual-req') === '1';
    const isPendingTech = manualReq && !(estat === 'validat' || estat === 'caducat');
    row.classList.toggle('row-pending-tech', isPendingTech);
  });
});
</script>
<!-- JS/AJAX de camp Redirecció Riders -->
<script>
(function () {
  document.addEventListener('change', async (ev) => {
    const sel = ev.target;
    if (!sel.matches('.redirect-select')) return;
    if (window.__leavingPage) return;

    const uid   = sel.getAttribute('data-uid');         // Rider_UID del rider caducat (origen)
    const csrf  = sel.getAttribute('data-csrf');
    const toId  = sel.value || '';                      // ID_Rider validat (destí) o buit per treure redirecció

    // UI bloqueig curt
    sel.disabled = true;

    try {
      const resp = await fetch('<?= h(BASE_PATH) ?>php/update_redirect.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({ csrf, rider_uid: uid, redirect_to: toId })
      });

      const json = await resp.json().catch(()=>({}));
      if (!resp.ok || !json.ok) {
        showToast(<?= json_encode($L['net_error']) ?> + (json.error ? (': ' + json.error) : ''));
        return;
      }


      // Actualitza el xip/enllaç de vista pública
        const row = sel.closest('tr');
        const chip = row?.querySelector('.redirect-chip');
        if (chip) {
          if (json.data?.redirect_uid) {
            const basePath   = "<?= rtrim(BASE_PATH, '/') ?>";
            const absBase    = "<?= defined('BASE_URL') ? rtrim(BASE_URL, '/') : '' ?>";
            const originLike = absBase || window.location.origin;
            const url = originLike + basePath + "/visualitza.php?ref=" + encodeURIComponent(json.data.redirect_uid);

            // Mostra sempre només la icona d'obrir
            chip.innerHTML = `
              <a class="text-decoration-none text-light" target="_blank" rel="noopener" href="${url}" title="Obrir vista pública">
                <i class="bi bi-box-arrow-up-right"></i>
              </a>`;
          } else {
            chip.textContent = '';
          }
        }


      // Si el backend retorna opcions recomputades, reomple (opcional però útil)
      if (Array.isArray(json.data?.redirect_options)) {
        if (typeof window.rebuildRedirectSelect === 'function') {
          window.rebuildRedirectSelect(sel, json.data.redirect_options, json.data.redirect_selected || 0);
        }
      }

    } catch (e) {
      if (e && (e.name === 'AbortError' || String(e).includes('abort'))) return;
      _netFail(e);
    } finally {
      // Torna a habilitar només si l’estat permet redirecció
      const estat = (sel.getAttribute('data-status') || '').toLowerCase();
      sel.disabled = (estat !== 'caducat');
    }
  });
})();
</script>

<script>
(function () {
  // Reconstrueix el <select> de redirecció amb opcions fresques
  function rebuildRedirectSelect(sel, options, selectedId) {
    if (!sel) return;
    // Mantén atributs útils
    const uid  = sel.getAttribute('data-uid') || '';
    const csrf = sel.getAttribute('data-csrf') || '';
    const status = (sel.getAttribute('data-status') || '').toLowerCase();

    // Construeix noves <option>
    const frag = document.createDocumentFragment();
    const optNone = document.createElement('option');
    optNone.value = '';
    optNone.textContent = <?= json_encode(__('riders.redirect.none') ?: '— Sense redirecció —') ?>;
    frag.appendChild(optNone);

    (Array.isArray(options) ? options : []).forEach(o => {
      const op = document.createElement('option');
      op.value = String(o.id);
      op.textContent = String(o.desc || o.id);
      if (selectedId && Number(selectedId) === Number(o.id)) op.selected = true;
      frag.appendChild(op);
    });

    // Substitueix contingut
    sel.innerHTML = '';
    sel.appendChild(frag);

    // Torna a aplicar l’estat habilitat segons segell
    sel.disabled = (status !== 'caducat');
    // Re-restaura atributs (per si algun navegador fa coses rares)
    sel.setAttribute('data-uid', uid);
    sel.setAttribute('data-csrf', csrf);
    sel.setAttribute('data-status', status);
  }

  // Exposa globalment perquè altres blocs la puguin cridar
  window.rebuildRedirectSelect = rebuildRedirectSelect;
})();
</script>

<!-- Repujar rider AJAX -->
<script>
(function () {
  // Obrir el selector de fitxer
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.reupload-btn');
    if (!btn) return;
    const uid = btn.getAttribute('data-uid');
    const input = btn.closest('tr')?.querySelector('.reupload-input[data-uid="'+uid+'"]');
    if (input) input.click();
  });

  // Enviar el PDF nou
  document.addEventListener('change', async (ev) => {
    const inp = ev.target;
    if (!inp.matches('.reupload-input')) return;
    const file = inp.files?.[0];
    if (!file) return;
    const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
    if (!isPdf) {
      showToast(<?= json_encode(__('riders.upload.only_pdf') ?: 'Cal un PDF.') ?>);
      inp.value = '';
    return;
    } 

    const row  = inp.closest('tr');
    const uid  = inp.getAttribute('data-uid');
    const btn  = row?.querySelector('.reupload-btn[data-uid="'+uid+'"]');
    const csrf = btn?.getAttribute('data-csrf') || '';
    const id   = btn?.getAttribute('data-id') || '';

    // feedback inici pujada
    let originalHTML = '';
    if (btn) {
      originalHTML = btn.innerHTML;
      btn.disabled = true;
      btn.classList.remove('btn-outline-primary');
      btn.classList.add('btn-secondary');
      btn.innerHTML =
      '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' +
        <?= json_encode(__('loading.processing') ?: 'Processant…') ?>;
    }

    try {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('rider_uid', uid);
      fd.append('rider_id', id);
      fd.append('rider_pdf', file);

      const resp = await fetch('<?= h(BASE_PATH) ?>php/reupload_rider.php', { method: 'POST', body: fd });
      const json = await resp.json().catch(()=>({}));
      if (!resp.ok || !json.ok) {
        showToast((json?.error) || <?= json_encode($L['net_error']) ?>);
        return;
      }

      // UI: reset estat a 'cap', valoració 0, data pujada, etc.
      const icon = row?.querySelector('.seal-icon[data-uid="'+uid+'"]');
      if (icon) {
        icon.setAttribute('data-estat','cap');
        icon.className = 'bi bi-shield text-secondary seal-icon';
      }
      const pubSpan = row?.querySelector('.pub-date[data-uid="'+uid+'"]');
      if (pubSpan) pubSpan.textContent = '—';
      const scoreCell = row?.querySelector('.col-score');
      if (scoreCell) scoreCell.textContent = '—';

      if (window.applyRedirectAvailability && row) {
        window.applyRedirectAvailability(row, 'cap');
      }

      inp.value = '';

      // restaurar botó i mostrar modal d'èxit
      if (btn) {
        btn.disabled = false;
        btn.classList.remove('btn-secondary');
        btn.classList.add('btn-primary');
        btn.innerHTML = originalHTML || '<i class="bi bi-arrow-repeat"></i>';
      }

      // Reutilitza el banner d'espai.php
      window.location.href = '<?= h(BASE_PATH) ?>espai.php?seccio=riders&success=reupload_ok';

    } catch (e) {
      if (window.__leavingPage) return;
      if (e && (e.name === 'AbortError' || String(e).includes('abort'))) return;
      _netFail(e);
    } finally {
      if (btn) btn.disabled = false;
    }
  });
})();
</script>
<!-- Edició meta AJAX (corregit) -->
<script>
(function () {
  function setEditMode(row, on) {
    row.querySelectorAll('.meta-view').forEach(el => el.classList.toggle('d-none', !!on));
    row.querySelectorAll('.meta-input').forEach(el => el.classList.toggle('d-none', !on));
    row.querySelectorAll('.meta-save-btn, .meta-cancel-btn').forEach(el => el.classList.toggle('d-none', !on));
    row.querySelectorAll('.meta-edit-btn').forEach(el => el.classList.toggle('d-none', !!on));
  }

  document.addEventListener('click', (ev) => {
    const edit = ev.target.closest('.meta-edit-btn');
    if (!edit) return;
    const row = edit.closest('tr');
    setEditMode(row, true);
  });

  document.addEventListener('click', (ev) => {
    const cancel = ev.target.closest('.meta-cancel-btn');
    if (!cancel) return;
    const row = cancel.closest('tr');
    // restaurar inputs als valors visibles
    row.querySelectorAll('.meta-input').forEach(inp => {
      const f = inp.getAttribute('data-field');
      const view = row.querySelector('.meta-view[data-field="'+f+'"]');
      if (view) inp.value = view.textContent.trim();
    });
    setEditMode(row, false);
  });

  document.addEventListener('click', async (ev) => {
    const save = ev.target.closest('.meta-save-btn');
    if (!save) return;

    const row  = save.closest('tr');
    const uid  = save.getAttribute('data-uid');
    const id   = save.getAttribute('data-id');
    const csrf = save.getAttribute('data-csrf');

    const descInp = row.querySelector('.meta-input[data-field="desc"]');
    const refInp  = row.querySelector('.meta-input[data-field="ref"]');
    const desc = (descInp?.value || '').trim();
    const ref  = (refInp?.value  || '').trim();

    save.disabled = true;

    try {
      const resp = await fetch('<?= h(BASE_PATH) ?>php/update_meta.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({ csrf, rider_id: id, rider_uid: uid, descripcio: desc, referencia: ref })
      });
      const json = await resp.json().catch(()=>({}));
      if (!resp.ok || !json.ok) {
        showToast(json?.error || <?= json_encode(__('common.error') ?: 'Error') ?>);
        return;
      }

      // Reflectir canvis
      const descView = row.querySelector('.meta-view[data-field="desc"]');
      const refView  = row.querySelector('.meta-view[data-field="ref"]');
      if (descView) descView.textContent = desc;
      if (refView)  refView.textContent  = ref;

      setEditMode(row, false);

    } catch (e) {
      if (window.__leavingPage) return;
      if (e && (e.name === 'AbortError' || String(e).includes('abort'))) return;
      _netFail(e);
    } finally {
      save.disabled = false;
    }
  });
})();
</script>
<!-- JS/AJAX Validació segell per part de l'usuari -->
<script>
(function () {
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.auto-seal-btn');
    if (!btn) return;

    const uid  = btn.getAttribute('data-uid');
    const id   = btn.getAttribute('data-id');
    const csrf = btn.getAttribute('data-csrf');
    if (!uid || !csrf) return;
    
    
    // console.log('auto_publish_seal → POST', { id, uid });

    // feedback
    btn.disabled = true;
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' +
                    (<?= json_encode(__('loading.processing') ?: 'Processant…') ?>);

    try {
      const resp = await fetch('<?= h(BASE_PATH) ?>php/auto_publish_seal.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({ csrf, rider_uid: uid, rider_id: id })
      });
      const json = await resp.json().catch(() => ({}));

      if (!resp.ok || !json.ok) {
        // Banner via espai.php
        window.location = '<?= h(BASE_PATH) ?>espai.php?seccio=riders&error=auto_seal_failed';
        return;
      }

      // OK → anem a riders amb missatge d’èxit (refresca estats/botons)
      window.location = '<?= h(BASE_PATH) ?>espai.php?seccio=riders&success=auto_seal_done';
    } catch (e) {
      console.error(e);
      window.location = '<?= h(BASE_PATH) ?>espai.php?seccio=riders&error=auto_seal_failed';
    } finally {
      btn.disabled = false;
      btn.innerHTML = oldHtml;
    }
  });
})();
</script>
<script>
(function () {
  let modal, confirmBtn, idSpan, descSpan, refSpan;
  let current = { uid:'', id:'', csrf:'', row:null, desc:'', ref:'' };

  function updateConfirmEnabled() {
  if (!confirmBtn) return;
  const reqOK = Array.from(document.querySelectorAll('#expireModal .exp-req'))
    .every(el => el.checked);
  const hasData = !!(current.uid && current.csrf);
  confirmBtn.disabled = !(reqOK && hasData);
}

  document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('expireModal');
  modal = new bootstrap.Modal(modalEl, { backdrop: 'static' });

  confirmBtn  = document.getElementById('exp-confirm-btn');
  idSpan      = document.getElementById('exp-rider-id');
  descSpan    = document.getElementById('exp-rider-desc');
  refSpan     = document.getElementById('exp-rider-ref');

  // Obrir: reinicia switches i estat botó
  modalEl.addEventListener('show.bs.modal', () => {
    document.querySelectorAll('#expireModal .exp-req').forEach(el => el.checked = false);
    updateConfirmEnabled();
  });

  // Tancar: reset del botó
  modalEl.addEventListener('hidden.bs.modal', () => {
    confirmBtn.disabled = false;
    confirmBtn.innerHTML = <?= json_encode($L['expire.modal.confirm'] ?: 'Sí, caduca’l') ?>;
  });

  // Canvis dels switches
  modalEl.addEventListener('change', (ev) => {
    if (ev.target.matches('#expireModal .exp-req')) {
      updateConfirmEnabled();
    }
  });
});

  // Obrir modal amb dades del rider
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.expire-btn');
    if (!btn) return;

    // Amaga tooltip si n’hi ha
    const tip = bootstrap.Tooltip.getInstance(btn);
    if (tip) tip.hide();

    const row  = btn.closest('tr');
    const uid  = btn.getAttribute('data-uid');
    const id   = row?.querySelector('th')?.textContent?.trim() || btn.getAttribute('data-id') || '';
    const csrf = btn.getAttribute('data-csrf') || '';

    const desc = row?.querySelector('.meta-view[data-field="desc"]')?.textContent?.trim() || '—';
    const ref  = row?.querySelector('.meta-view[data-field="ref"]')?.textContent?.trim()  || '—';

    current = { uid, id, csrf, row, desc, ref };

    if (idSpan)   idSpan.textContent   = id || '—';
    if (descSpan) descSpan.textContent = desc || '—';
    if (refSpan)  refSpan.textContent  = ref || '—';

    updateConfirmEnabled();
    modal.show();
  });

  // Confirmar: caducar
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('#exp-confirm-btn');
  if (!btn) return;
  if (!current.uid || !current.csrf) return;

  btn.disabled = true;
  const oldHtml = btn.innerHTML;
  btn.innerHTML =
    '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' +
    <?= json_encode(__('loading.processing') ?: 'Processant…') ?>;

  try {
    const resp1 = await fetch('<?= h(BASE_PATH) ?>php/update_seal.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8' },
      body: new URLSearchParams({ csrf: current.csrf, rider_uid: current.uid, estat: 'caducat' })
    });
    const json1 = await resp1.json().catch(() => ({}));
    if (!resp1.ok || !json1.ok) {
      showToast(<?= json_encode(__('common.error') ?: 'Error') ?> + (json1.error ? (': ' + json1.error) : ''));
      btn.disabled = false;
      btn.innerHTML = oldHtml;
      return;
    }

    // Tot OK → tanquem modal i recarreguem amb flash
    modal.hide();
    window.location = '<?= h(BASE_PATH) ?>espai.php?seccio=riders&success=seal_expired';
  } catch (e) {
    _netFail(e);
    btn.disabled = false;
    btn.innerHTML = oldHtml;
  }
});
})();
</script>
<!-- Modal obrir fitxa segell -->
 <script>
(function(){
  let modal, elWhen, elHash, elUrl, elUid;
  document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('sealInfoModal');
    if (!modalEl) return;
    modal  = new bootstrap.Modal(modalEl, { backdrop: 'static' });
    elWhen = document.getElementById('seal-info-when');
    elHash = document.getElementById('seal-info-hash');
    elUrl  = document.getElementById('seal-info-url');
    elUid  = document.getElementById('seal-info-uid');

    document.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('.seal-info-btn');
      if (!btn) return;

      const when = btn.getAttribute('data-when') || '—';
      const hash = btn.getAttribute('data-hash') || '';
      const url  = btn.getAttribute('data-url')  || '#';
      const uid  = btn.getAttribute('data-uid')  || '';

      if (elWhen) elWhen.textContent = when;
      if (elHash) elHash.textContent = hash || '—';
      if (elUrl)  { elUrl.textContent = url; elUrl.href = url; }
      if (elUid)  elUid.textContent = uid ? ('UID: ' + uid) : '';

      modal.show();
    });

    // Copiar Hash
    const copyHashBtn = document.getElementById('seal-copy-hash');
    if (copyHashBtn) {
      copyHashBtn.addEventListener('click', async () => {
        try {
          const v = (elHash?.textContent || '').trim();
          if (!v) return;
          await navigator.clipboard.writeText(v);
          copyHashBtn.classList.remove('btn-outline-secondary');
          copyHashBtn.classList.add('btn-success');
          setTimeout(()=>{ copyHashBtn.classList.add('btn-outline-secondary'); copyHashBtn.classList.remove('btn-success'); }, 800);
        } catch (e) { showToast('No s\'ha pogut copiar.'); }
      });
    }

    // Copiar URL
    const copyUrlBtn = document.getElementById('seal-copy-url');
    if (copyUrlBtn) {
      copyUrlBtn.addEventListener('click', async () => {
        try {
          const v = (elUrl?.href || '').trim();
          if (!v || v === '#') return;
          await navigator.clipboard.writeText(v);
          copyUrlBtn.classList.remove('btn-outline-secondary');
          copyUrlBtn.classList.add('btn-success');
          setTimeout(()=>{ copyUrlBtn.classList.add('btn-outline-secondary'); copyUrlBtn.classList.remove('btn-success'); }, 800);
        } catch (e) { showToast('No s\'ha pogut copiar.'); }
      });
    }
  });
})();
</script>
<script>
(function(){
  let delModal, delForm = null, idEl, descEl, confirmBtn;

  document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('deleteModal');
    if (!modalEl) return;
    delModal   = new bootstrap.Modal(modalEl, { backdrop: 'static' });
    idEl       = document.getElementById('del-rider-id');
    descEl     = document.getElementById('del-rider-desc');
    confirmBtn = document.getElementById('del-confirm-btn');

    // Obrir modal des del botó de cada fila
    document.addEventListener('click', (ev) => {
      const btn = ev.target.closest('.js-del-btn');
      if (!btn) return;

      const form = btn.closest('.js-del-form');
      if (!form) return;

      delForm = form; // guardem la referència al form que s’enviarà

      // Omple info
      const id   = form.getAttribute('data-id')   || '—';
      const desc = form.getAttribute('data-desc') || '—';
      if (idEl)   idEl.textContent   = id;
      if (descEl) descEl.textContent = desc;

      // Prepara botó
      confirmBtn.disabled = false;
      confirmBtn.innerHTML = '<i class="bi bi-trash3 me-1"></i>' + (<?= json_encode(__('common.delete') ?: 'Elimina') ?>);

      delModal.show();
    });

    // Confirmar → enviar el POST del form original
    confirmBtn.addEventListener('click', () => {
      if (!delForm) return;
      confirmBtn.disabled = true;
      confirmBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' +
        (<?= json_encode(__('loading.processing') ?: 'Processant…') ?>);
      // Tanca el modal per UX (el servidor redirigeix amb flash)
      delModal.hide();
      // Submit normal (manté CSRF i el backend fa redirect a espai.php)
      delForm.submit();
    });

    // Neteja quan es tanca
    modalEl.addEventListener('hidden.bs.modal', () => { delForm = null; });
  });
})();
</script>
<script>
(() => {
  const form   = document.getElementById('uploadRiderForm');
  if (!form) return;
  const fileEl = document.getElementById('rider_pdf');
  const btn    = document.getElementById('uploadBtn');
  const errEl  = document.getElementById('rider_pdf_error');
  const MAX    = 10 * 1024 * 1024; // 10 MB

  const showErr = (msg) => {
    if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
  };
  const clearErr = () => {
    if (errEl) { errEl.textContent = ''; errEl.style.display = 'none'; }
  };

  form.addEventListener('submit', (e) => {
    clearErr();
    const f = fileEl?.files?.[0];
    if (!f) return; // 'required' ja farà la seva feina

    if (f.size > MAX) {
      e.preventDefault();
      showErr('<?= h(__('riders.upload.pdf_help') ?: '(Max. 10 MB)') ?>');
      return;
    }
    // Tipus bàsic
    if (f.type && f.type !== 'application/pdf') {
      e.preventDefault();
      showErr('<?= h(__('riders.upload.pdf_label') ?: '(PDF!)') ?>');
      return;
    }
    // Bloqueig simple del botó mentre s’envia
    if (btn) btn.disabled = true;
  });
})();
</script>
<!-- Refresh automàtic quan acaben jobs d'IA actius -->
<script>
(function(){
  document.addEventListener('DOMContentLoaded', () => {
    // Si hi ha algun botó marcat com a IA pendent, activem polling
    const hasPending = !!document.querySelector('[data-ai-pending="1"]');
    if (!hasPending) return;

    let tries = 0;
    const tick = async () => {
      tries++;
      try {
        const resp = await fetch('<?= h(BASE_PATH) ?>php/ia_status_any_active.php', { cache:'no-store' });
        const json = await resp.json().catch(()=>({}));
        if (json && json.ok && json.any_active === false) {
          window.location.reload();
          return;
        }
      } catch (e) { /* silenciat */ }
      if (tries < 60) setTimeout(tick, 8000); // cada 8s, ~8 min
    };
    setTimeout(tick, 8000);
  });
})();
</script>