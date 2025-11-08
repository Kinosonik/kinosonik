<?php
// visualitza.php — Vista pública/privada d’un rider (multillenguatge)
// Estat_Segell ENUM: cap / pendent / validat / caducat
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/messages.php';
require_once __DIR__ . '/php/middleware.php';

// 🚦 Garanteix que tenim un PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
  if (function_exists('db')) {
    $pdo = db();
  } elseif (function_exists('get_pdo')) {
    $pdo = get_pdo();
  } elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdo = $GLOBALS['pdo'];
  } else {
    http_response_code(500);
    echo 'Database not available';
    exit;
  }
}

// Fallback segur per redireccions si no tenim el helper carregat
if (!function_exists('redirect_to')) {
  function redirect_to(string $path, array $qs = [], int $code = 302): never {
    $base = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : '/'), '/') . '/';
    $url  = $base . ltrim($path, '/');
    if ($qs) { $url .= (str_contains($url,'?')?'&':'?') . http_build_query($qs); }
    header('Location: ' . $url, true, $code);
    exit;
  }
}

// === Carrega diccionari d’idioma únic ======================
$lang = $_SESSION['lang'] ?? 'ca';
$lang = preg_replace('/[^a-z]/i', '', $lang) ?: 'ca';
$langFile = __DIR__ . "/lang/{$lang}.php";
if (!is_file($langFile)) { $langFile = __DIR__ . "/lang/ca.php"; }
$t = require $langFile;
if (!is_array($t)) { $t = []; }
$T = fn(string $k, string $fallback='') => $t[$k] ?? ($fallback !== '' ? $fallback : $k);

// === Helpers segell ========================================
function segell_norm(string $s): string { return strtolower(trim($s)); }
function segell_es_valid(string $s): bool { return segell_norm($s) === 'validat'; }
function segell_es_caducat(string $s): bool { return segell_norm($s) === 'caducat'; }

// === Helper escapat ========================================
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Converteix timestamps de BD (UTC) a Europe/Madrid per mostrar
function safe_dt_utc_to_eu(?string $s): ?DateTimeImmutable {
  $s = trim((string)$s);
  if ($s === '' || $s === '0000-00-00' || $s === '0000-00-00 00:00:00') return null;
  try {
    $utc = new DateTimeZone('UTC');
    $eu  = new DateTimeZone('Europe/Madrid');
    return (new DateTimeImmutable($s, $utc))->setTimezone($eu);
  } catch (Throwable $e) { return null; }
}

if (!function_exists('origin_url')) {
  function origin_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
           || (($_SERVER['SERVER_PORT'] ?? null) == 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
  }
}

/* ── Input ───────────────────────────────────────────────── */
$ref = $_GET['ref'] ?? '';
if ($ref === '' || !preg_match(
  '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
  $ref
)) {
  redirect_to('index.php', ['error' => 'rider_bad_link']);
  exit;
}

/* ── Carrega rider + propietari (+ redirecció manual) ────── */
$sth = $pdo->prepare("
  SELECT
    r.ID_Rider, r.ID_Usuari, r.Rider_UID, r.Nom_Arxiu, r.Descripcio, r.Referencia,
    r.Data_Pujada, r.Data_Publicacio, r.Valoracio, r.Estat_Segell, r.Mida_Bytes,
    r.Object_Key, r.rider_actualitzat, r.Hash_SHA256,
    u.Nom_Usuari, u.Cognoms_Usuari, u.Email_Usuari, u.Telefon_Usuari,
    COALESCE(u.Publica_Telefon,1) AS Publica_Telefon,
    r2.Rider_UID AS Redir_UID, r2.ID_Rider AS Redir_ID, r2.Descripcio AS Redir_Desc,
    r2.Nom_Arxiu AS Redir_NomArxiu, r2.Estat_Segell AS Redir_Segell
  FROM Riders r
  JOIN Usuaris u ON u.ID_Usuari = r.ID_Usuari
  LEFT JOIN Riders r2
    ON r2.ID_Rider = r.rider_actualitzat AND r2.Estat_Segell = 'validat'
  WHERE r.Rider_UID = :ref
  LIMIT 1
");
$sth->execute([':ref' => $ref]);
$r = $sth->fetch(PDO::FETCH_ASSOC);
if (!$r) {
  redirect_to('index.php', ['error' => 'rider_not_found']);
  exit;
}

// ── Resolució de redirecció en cadenes (fins a 10 salts) ─────────────────────
$finalUid = ''; $redirectOk = false; $cycle = false;

if (!empty($r['rider_actualitzat']) || !empty($r['Redir_UID'])) {
  $visited = [];
  $currId  = (int)$r['rider_actualitzat']; // punt de partida (ID) si n’hi ha
  $hops    = 0;

  while ($currId && $hops < 10) {
    if (isset($visited[$currId])) { $cycle = true; break; }
    $visited[$currId] = true;
    $hops++;

    $q = $pdo->prepare("SELECT ID_Rider, Rider_UID, Estat_Segell, rider_actualitzat FROM Riders WHERE ID_Rider = :id LIMIT 1");
    $q->execute([':id' => $currId]);
    $n = $q->fetch(PDO::FETCH_ASSOC);
    if (!$n) break;

    $st = strtolower(trim((string)$n['Estat_Segell']));
    if ($st === 'validat') {
      $finalUid = (string)$n['Rider_UID'];
      $redirectOk = true;
      break;
    }

    // segueix saltant si apunta a un altre
    $currId = (int)($n['rider_actualitzat'] ?? 0);
  }
}

// Per a capçalera <link rel="canonical"> al <head>
$canonicalUrl = '';

// Canonical via header HTTP si hi ha destí validat
if ($redirectOk && $finalUid !== '' && strcasecmp($finalUid, (string)$r['Rider_UID']) !== 0) {
  $absBase = defined('BASE_URL') ? rtrim(BASE_URL, '/') : origin_url();
  $canonicalUrl = $absBase . rtrim((string)BASE_PATH, '/') . '/visualitza.php?ref=' . rawurlencode($finalUid);
  header('Link: <' . $canonicalUrl . '>; rel="canonical"', false);
}

// Estat actual
$estat = (string)($r['Estat_Segell'] ?? '');

// Qui mira
$userId   = (int)($_SESSION['user_id'] ?? 0);
$tipus    = (string)($_SESSION['tipus_usuari'] ?? '');
$isOwner  = $userId === (int)$r['ID_Usuari'];
$isAdmin  = strcasecmp($tipus, 'admin') === 0;

// Propietari o admin poden previsualitzar riders en estat cap/pendent
$canPreview = $isOwner || $isAdmin;

if (!$canPreview && !segell_es_valid($estat) && !segell_es_caducat($estat)) {
  redirect_to('index.php', ['error' => 'rider_not_found']);
  exit;
}

/* ── Sessió ──────────────────────────────────────────────── */
$isLogged = !empty($_SESSION['loggedin']);

/* ── Camps ───────────────────────────────────────────────── */
$idRider    = (int)$r['ID_Rider'];
$ownerId    = (int)$r['ID_Usuari'];
$uid        = (string)$r['Rider_UID'];
$nomFitxer  = (string)$r['Nom_Arxiu'];
$desc       = trim((string)($r['Descripcio'] ?? ''));
$refBand    = trim((string)($r['Referencia'] ?? ''));
$pujadaDt   = ($d = safe_dt_utc_to_eu($r['Data_Pujada'] ?? null))     ? $d->format('d/m/Y') : '—';
$pubDt      = ($d = safe_dt_utc_to_eu($r['Data_Publicacio'] ?? null)) ? $d->format('d/m/Y') : '—';
$score      = (int)($r['Valoracio'] ?? 0);
$estat      = (string)($r['Estat_Segell'] ?? '');
$objectKey  = (string)($r['Object_Key'] ?? '');
$redirId    = $r['rider_actualitzat'] !== null ? (int)$r['rider_actualitzat'] : null;
$redirUid   = isset($r['Redir_UID']) ? (string)$r['Redir_UID'] : '';
$sha256Raw  = (string)($r['Hash_SHA256'] ?? '');
$sha256     = (preg_match('/^[0-9a-f]{64}$/i', $sha256Raw) ? strtolower($sha256Raw) : '');
// Usem 8 hex per cache-busting curt (n’hi ha prou per canviar l’URL quan canvia el fitxer)
$versionQs  = ($sha256 !== '' ? '&v=' . substr($sha256, 0, 8) : '');
$redirDesc  = isset($r['Redir_Desc']) ? trim((string)$r['Redir_Desc']) : '';
$redirName  = $redirDesc !== '' ? $redirDesc : (isset($r['Redir_NomArxiu']) ? (string)$r['Redir_NomArxiu'] : '');
$canShowPhone = $isLogged
  && !empty($r['Telefon_Usuari'])
  && ((int)($r['Publica_Telefon'] ?? 1) === 1);

/* Estat derivat útil per a la UI */
$isValid   = segell_es_valid($estat);
$isExpired = segell_es_caducat($estat);

/* ── Cerca versió més nova heurística ────────────────────── */
$newer = null;
if ($refBand !== '') {
  $stn = $pdo->prepare("
    SELECT Rider_UID, ID_Rider, Data_Pujada
      FROM Riders
     WHERE ID_Usuari = :uid
       AND Referencia = :refb
       AND ID_Rider <> :curr
       AND Estat_Segell = 'validat'
  ORDER BY Data_Pujada DESC, ID_Rider DESC
     LIMIT 1
  ");
  $stn->execute([':uid'=>$ownerId, ':refb'=>$refBand, ':curr'=>$idRider]);
  $newer = $stn->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* CTA Login/Registre amb retorn */
$currentUrl = origin_url() . BASE_PATH . 'visualitza.php?ref=' . rawurlencode($uid);
$loginUrl   = BASE_PATH . 'index.php?modal=login&return='    . rawurlencode($currentUrl);
$signupUrl  = BASE_PATH . 'index.php?modal=register&return=' . rawurlencode($currentUrl);

// Si és caducat i NO hi ha redirecció resolta vàlida (o hi ha bucle),
// els visitants (no propietari i no admin) reben 410 Gone.
// Propietari/admin segueixen veient la pàgina informativa (200).
if (!$canPreview && segell_es_caducat($estat)) {
  if (!$redirectOk || $cycle || !$isLogged) {
    // Usuari no loguejat o sense redirecció vàlida → no redirect
    http_response_code(410);
    header('X-Robots-Tag: noindex, noarchive');
  } else {
    // Només redirigeix si està loguejat i la redirecció és vàlida
    $abs = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : '/'), '/') . '/';
    $qs  = [
      'ref'        => $finalUid,
      'from_id'    => (int)$r['ID_Rider'],
      'from_desc'  => substr((string)$r['Descripcio'], 0, 100),
    ];
    $to  = $abs . 'visualitza.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $to, true, 302);
    header('Cache-Control: no-store');
    exit;
  }
}


  /* Redirecció (prioritza cadena resolta) */
  $redirUrl = '';
  if ($redirectOk && $finalUid !== '' && strcasecmp($finalUid, $uid) !== 0) {
    $redirUrl = BASE_PATH . 'visualitza.php?ref=' . rawurlencode($finalUid);
  } elseif ($redirUid !== '' && strcasecmp($redirUid, $uid) !== 0) {
    // fallback a l'LEFT JOIN d'1 salt (per compatibilitat)
    $redirUrl = BASE_PATH . 'visualitza.php?ref=' . rawurlencode($redirUid);
  }

/* ── Icona/estat segell (UI) ─────────────────────────────── */
function segell_icon_class_localized(string $estat, callable $T): array {
  $e = segell_norm($estat);
  if ($e === 'validat') return ['bi-shield-fill-check', 'text-success', $T('seal_validated')];
  if ($e === 'caducat') return ['bi-shield-fill-x',     'text-danger',  $T('seal_expired')];
  if ($e === 'pendent') return ['bi-shield-exclamation','text-warning', $T('seal_pending')];
  return ['bi-shield', 'text-secondary', $T('seal_none')];
}
[$iconSegell, $colorSegell, $titleSegell] = segell_icon_class_localized($estat, $T);

// Hardening headers (abans de pintar HTML)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: "
  . "default-src 'self' https: data: blob:; "
  . "img-src 'self' https: data:; "
  . "style-src 'self' https: 'unsafe-inline'; "
  . "script-src 'self' https: 'unsafe-inline'; "
  . "font-src 'self' https: data:; "
  . "connect-src 'self' https:; "
  . "frame-src 'self' https:; "
  . "frame-ancestors 'self'; "
  . "base-uri 'self'; "
  . "object-src 'none'; "
  . "upgrade-insecure-requests"
);
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
if ($isValid) {
  header('Cache-Control: private, max-age=600'); // 10 min per versions validades
} else { // caducat
  header('Cache-Control: no-store');             // no cache per caducats
}

// Metadades útils
header('Content-Language: ' . $lang);
header('Vary: Accept-Language');
header('X-Rider-UID: ' . (string)$r['Rider_UID']);
header('X-Rider-Status: ' . segell_norm($estat));

/* ── URLs (PDF via rider_file.php) ────────────────────────────── */
$pdfInline = BASE_PATH . 'php/rider_file.php?ref=' . rawurlencode($uid) . '&view=1' . $versionQs;
$pdfDL     = BASE_PATH . 'php/rider_file.php?ref=' . rawurlencode($uid) . '&dl=1'   . $versionQs;

/* 🔸 Preload del PDF quan el rider és VALIDAT (millora TTFP a l'iframe) */
if ($isValid) {
  $absBase = defined('BASE_URL') ? rtrim(BASE_URL, '/') : origin_url();
  $preload = $absBase . rtrim((string)BASE_PATH, '/') . '/php/rider_file.php?ref=' . rawurlencode($uid) . '&view=1' . $versionQs;
  header('Link: <' . $preload . '>; rel="preload"; as=document', false);
}
/* ── Head + Nav ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';

/* ── Info redirect  ──────────────────────────────────────── */
$fromId   = isset($_GET['from_id']) ? (int)$_GET['from_id'] : 0;
$fromDesc = isset($_GET['from_desc']) ? trim((string)$_GET['from_desc']) : '';
$from_redirect = false;

if ($fromId > 0 && $fromDesc !== '') {
  $from_redirect = true;
}
?>

<div class="container my-3 mt-4">

<!-- Avís de redirecció -->
<?php if($from_redirect): ?>
<div class="small">
    <div class="w-100 mb-3 mt-2 text-light"
     style="background:rgba(255,193,7,0.15);
        border-left:3px solid #ffc107;
        padding:25px 18px;">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">  
        <div class="text-start text-warning">
          <i class="bi bi-exclamation-circle me-1 text-warning"></i>
          <?= sprintf(
         $T('rider_redirected_from','Has estat redirigit des del rider #%d (%s), que estava caducat.'),
         h($fromDesc),
         $fromId
       ) ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Header del rider -->
  <div class="rider-header mb-3 py-4  border border-secondary shadow-sm rounded-2" style="background-color: #3a37445e;">
    <div class="row">
      <div class="col-12 col-md-3 text-center align-content-center">
        <div class="segell" role="img" aria-label="<?= h($titleSegell) ?>" title="<?= h($titleSegell) ?>">
          <i class="bi <?= h($iconSegell) ?> <?= h($colorSegell) ?>"></i>
        </div>
        <div>
          <div class="meta-value text-muted">ID: <?= h((string)$idRider) ?></div>
          <div class="meta-small"><?= h($T('published', 'Published')) ?>: <?= h($pubDt) ?></div>
        </div>
      </div>
      <!-- Títol al centre -->
      <div class="col-12 col-md-6 text-center align-content-center">
        <div>
          <h2 class="display-5 fw-semibold lh-sm mb-1 text-gradient">
          <?= h($desc !== '' ? $desc : $nomFitxer) ?></h2>
          <?php if ($refBand !== ''): ?>
            <small class="text-muted fw-lighter">(<?= h($refBand) ?>)</small>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-12 col-md-3"></div><!--Blank-->
    </div>
  </div>

  <!-- RIDER CADUCAT MEGA-AVÍS -->
  <?php if ($isExpired): ?>
  <div class="expired-banner rounded-0 alert alert-warning border-0 shadow-sm text-center my-3" role="alert">
    <div class="d-flex flex-column gap-1 align-items-center">
      <div class="fs-2 fw-bold mt-3 mb-3">
        <?= h($T('expired_banner_title')) ?>
      </div>
      <?php if ($redirUrl !== ''): ?><!-- Si hi ha redirecció -->
      <div>
        <?= h($T('expired_redirect_validated')) ?><br />
        <?php if ($isLogged): ?>
          <a class="alert-link" href="<?= h($redirUrl) ?>"><i class="bi bi-arrow-right me-1"></i><?= h($T('open_current_version','Open current version')) ?></a>
        <?php else:
          echo sprintf(
            $T('visualitza.inici.01'),
            "<a class='text-warning fw-semibold' href='" . h($loginUrl) . "'>",
            "<a class='text-warning fw-semibold' href='" . h($signupUrl) . "'>"
          );
        endif; ?>
      </div>
      
      
      <?php else: ?>
      <div><?= h($T('expired_not_available')) ?></div>
      <?php endif; ?>
      <?php if (!$isLogged): ?>
      <div class="small text-muted"><?= h($T('expired_pdf_restricted')) ?></div>
      <?php endif; ?>
    </div>
  </div>
        
  <!-- Watermark visual (només informatiu) -->
  <div class="expired-watermark" aria-hidden="true"><?= h($T('expired_watermark','EXPIRED')) ?></div>
  <?php endif; ?>

  <!-- IF IS LOGGED -->
  <?php if ($isLogged): ?>
  <div class="small">
    <div class="w-100 mb-3 mt-2 text-light"
     style="background: var(--ks-veil);
            border-left:3px solid var(--ks-accent);
            padding:12px 18px;">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">  
        <div class="text-start"><!-- Nom propietari tècnic -->
          <span class="text-secondary">
            <?= h($T('contacte_del_tecnic')) ?>:
          </span>
          <?= h(($r['Nom_Usuari'] ?? '').' '.($r['Cognoms_Usuari'] ?? '')) ?>
          <!-- Grup de botons -->
          <div class="btn-group ms-4" role="group"
            aria-label="<?= h($T('rider_actions','Actions')) ?>">
            <?php if (!empty($r['Email_Usuari'])): ?>
            <a href="mailto:<?= h($r['Email_Usuari']) ?>"
              class="btn btn-primary btn-sm"
              title="<?= h($T('send_email','Send email')) ?>">
              <i class="bi bi-envelope"></i>
            </a>
            <?php endif; ?>
            <?php if ($canShowPhone): ?>
            <a href="tel:<?= h($r['Telefon_Usuari']) ?>"
              class="btn btn-primary btn-sm"
              title="<?= h($T('call','Call')) ?>">
              <i class="bi bi-telephone"></i>
            </a>
            <?php endif; ?>

            <?php if ($isValid || $canPreview): ?>
            <a href="<?= h($pdfDL) ?>"
              class="btn btn-primary btn-sm"
              title="<?= h($T('download_pdf')) ?>">
              <i class="bi bi-download"></i>
            </a>
            <a href="<?= h($pdfInline) ?>"
              class="btn btn-primary btn-sm"
              target="_blank" rel="noopener"
              title="<?= h($T('open_pdf')) ?>">
              <i class="bi bi-box-arrow-up-right"></i>
            </a>
            <?php else: ?>
            <button class="btn btn-secondary btn-sm" type="button" disabled
                    title="<?= h($T('not_available')) ?>">
              <i class="bi bi-download"></i>
            </button>
            <button class="btn btn-secondary btn-sm" type="button" disabled
                    title="<?= h($T('not_available')) ?>">
              <i class="bi bi-box-arrow-up-right"></i>
            </button>
            <?php endif; ?>
          </div><!-- Fi grup botons-->
        </div><!-- Fi DIV Esquerra -->
        <div class="text-end">
          <?php if (!$isOwner): ?>
          <div class="mt-2 text-center"><!-- Subscripció riders -->
            <div class="form-check form-switch d-inline-flex align-items-center">
              <input
                class="form-check-input"
                type="checkbox"
                role="switch"
                id="riderSubSwitch"
                data-rider="<?= (int)$idRider ?>">
              <label class="form-check-label text-light ms-2" for="riderSubSwitch">
                <?= h($T('visualitza.notificacions')) ?>
              </label>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div><!-- Fi banda blava -->
  </div>
  <?php else: ?><!-- ELSEIF LOGGED -->
  <div class="small">
    <div class="w-100 mb-3 mt-2 text-light"
     style="background:rgba(255,193,7,0.15);
        border-left:3px solid #ffc107;
        padding:25px 18px;">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">  
        <div class="text-start">
          <?php  echo sprintf(
            $T('visualitza.registrarse'),
            "href='" . h($loginUrl) . "'",
            "href='" . h($signupUrl) . "'"
          ); ?>
        </div>
      </div>
    </div><!-- Fi banda groga -->
  </div>
  <?php endif;?>

  <!-- CAIXES DE VALIDACIÓ -->
  <?php if (!$isExpired): ?>
  <div class="card border-1 shadow mx-auto mb-4" id="verifyCard">
    <div class="card-header bg-kinosonik esquerra">
      <h6><?= h($T('verify_card_title')) ?></h6>
      <!-- Botó de tancar caixa -->
      <div class="btn-group ms-2">
        <button type="button"
          class="btn-close"
          aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"
          onclick="this.closest('.card')?.remove();">
        </button>
      </div>
    </div>
    <!-- Contingut dins de la caixa -->
    <div class="card-body">
      <div class="row">
        <!-- Esquerra amb HASH -->
        <div class="col-md-6 mb-3">
          <h6 class="text-uppercase mb-2"><?= h($T('verify_with_hash')) ?></h6>
          <p class="small text-body-secondary mb-0"><?= h($T('verify_hash_help')) ?></p>
          <p class="small text-body-secondary mb-2">
            <?= h($T('verify_hash_canonical')) ?>: <code id="canonicalHash">—</code>
          </p>
          <div class="d-flex gap-2 align-items-center">
            <input type="text" id="verifyHashInput" class="form-control form-control-sm flex-grow-1" placeholder="">
              <button id="verifyHashBtn" class="btn btn-primary btn-sm flex-shrink-0">
                <i class="bi bi-exclamation-circle me-1"></i>
                <?= h($T('verify_btn')) ?>
            </button>
          </div>
        </div>
        <!-- Dreta amb PDF -->
        <div class="col-md-6">
          <h6 class="text-uppercase mb-2"><?= h($T('verify_with_file')) ?></h6>
          <p class="small text-body-secondary mb-2">
            <?= h($T('verify_file_help')) ?>
            <?= h($T('verify_size')) ?>: <code id="canonicalSize">—</code>
          </p>
          <div class="d-flex flex-wrap gap-2 align-items-center">
          <?php if($isLogged): ?>
            <input type="file" id="verifyFile" 
              accept="application/pdf" 
              class="form-control form-control-sm">
          <?php else:?>
              <p class="small text-light mt-1"><i class="bi bi-exclamation-circle me-1 text-warning"></i>
              <?php
              echo sprintf(
                $T('visualitza.inici.02'),
                  "<a class='text-warning fw-semibold' href='" . h($loginUrl) . "'>iniciar sessió</a>",
                  "<a class='text-warning fw-semibold' href='" . h($signupUrl) . "'>registrar-te</a>"
                );
              ?>
          <?php endif;?>
          </div>
        </div> 
        <!-- Resultats -->
        <div id="verifyResult" class="col-md-12 text-center mt-2"></div> 
      </div>
    </div><!-- Tanquem CARD Validació -->
    <?php endif;?>
  </div>
  <!-- PDF incrustat (iframe) només si PUBLIC — accessible per a tothom -->
  <?php if ($isValid): ?>
  <div class="ratio ratio-16x9">
    <iframe src="<?= h(BASE_PATH . 'php/rider_file.php?ref=' . rawurlencode($uid) . '&view=1' . $versionQs) ?>" title="Rider PDF" loading="lazy" referrerpolicy="same-origin"></iframe>
  </div>
  <?php endif; ?>
</div><!-- FI CONTAINER PRINCIPAL -->
<?php require_once __DIR__ . '/parts/footer.php'; ?>
<?php if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));?>
<script>
      (function() {
        const el = document.getElementById('riderSubSwitch');
        if (!el) return;

        const riderId   = el.dataset.rider;
        const statusUrl = <?= json_encode(BASE_PATH . 'php/rider_sub_status.php') ?>;
        const toggleUrl = <?= json_encode(BASE_PATH . 'php/rider_sub_toggle.php') ?>;
        const csrf      = <?= json_encode($_SESSION['csrf'] ?? '') ?>;

        // Carrega estat inicial (subscrita / no)
        (async function initSubStatus() {
          try {
            const res = await fetch(statusUrl + '?rider_id=' + encodeURIComponent(riderId), {
              cache: 'no-store'
            });
            if (!res.ok) return;
            const j = await res.json();
            if (typeof j.subscribed !== 'undefined') {
              el.checked = !!j.subscribed;
            }
          } catch (e) {
            // Ignora errors silenciosament; l’usuari igualment pot fer toggle
          }
        })();

        el.addEventListener('change', async function() {
          try {
            const params = new URLSearchParams();
            params.append('rider_id', riderId);
            params.append('csrf', csrf);

            const res = await fetch(toggleUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: params.toString()
            });

            const j = await res.json();
            if (!j.ok) {
              // Si hi ha error, recupera l’estat anterior
              el.checked = !el.checked;
              return;
            }
            el.checked = !!j.subscribed;
          } catch (e) {
            // En cas d’error de xarxa, revertim el checkbox
            el.checked = !el.checked;
          }
        });
      })();
    </script>
<script>
(async function(){
  const ref = <?= json_encode($_GET['ref'] ?? '') ?>;
  const metaUrl = <?= json_encode(BASE_PATH . 'php/rider_meta.php?ref=') ?> + encodeURIComponent(ref);

  const elFile   = document.getElementById('verifyFile');
  const elRes    = document.getElementById('verifyResult');
  const elCanon  = document.getElementById('canonicalHash');
  const elSize   = document.getElementById('canonicalSize');
  const inHash   = document.getElementById('verifyHashInput');
  const btnHash  = document.getElementById('verifyHashBtn');

  const TXT = {
  nometa:   <?= json_encode($T('verify_msg_nometa',   'No s’ha pogut obtenir la metadada.')) ?>,
  nohash:   <?= json_encode($T('verify_msg_nohash',   'No hi ha hash publicat per comparar.')) ?>,
  invalid:  <?= json_encode($T('verify_msg_invalid',  'Hash invàlid.')) ?>,
  match:    <?= json_encode($T('verify_msg_match',    'Coincideix ✔')) ?>,
  mismatch: <?= json_encode($T('verify_msg_mismatch', 'No coincideix ✖')) ?>,
  error:    <?= json_encode($T('verify_msg_error',    'Error calculant el hash.')) ?>,
};

  const fmtBytes = b => {
    const u = ['B','KB','MB','GB']; let i=0, v=b;
    while (v>=1024 && i<u.length-1) { v/=1024; i++; }
    return `${v.toFixed(i?1:0)} ${u[i]}`;
  };

  const toHex = buf => Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join('');

  const setResult = (ok, msg) => {
    elRes.className = 'small text-center ' + (ok ? 'text-success' : 'text-danger');
    elRes.textContent = msg;
  };

  // 1) Demana meta (hash + mida)
  let canonical = { sha256: null, bytes: null, seal: null };
  try {
    const r = await fetch(metaUrl, { cache: 'no-store' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'meta_error');
    canonical = j;
    elCanon.textContent = j.sha256 || '—';
    elSize.textContent  = (typeof j.bytes==='number') ? fmtBytes(j.bytes) : '—';
  } catch (e) {
    setResult(false, TXT.nometa);
  }

  // 2) Càlcul de hash a client
  async function hashFile(file) {
    const buf = await file.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', buf);
    return toHex(digest);
  }

  function compareHash(userHash) {
    const a = String(userHash || '').trim().toLowerCase();
    const b = String(canonical.sha256 || '').trim().toLowerCase();
    if (!b) { setResult(false, TXT.nohash); return; }
    if (!/^[0-9a-f]{64}$/.test(a)) { setResult(false, TXT.invalid); return; }
    if (a === b) setResult(true, TXT.match);
    else         setResult(false, TXT.mismatch);
  }

  elFile?.addEventListener('change', async () => {
    elRes.textContent = '';
    try {
      const f = elFile.files?.[0]; if (!f) return;
      const h = await hashFile(f);
      compareHash(h);
    } catch (e) {
      setResult(false, TXT.error);
    }
  });

  btnHash?.addEventListener('click', () => compareHash(inHash?.value || ''));
})();
</script>