<?php
// parts/navmenu.php
require_once __DIR__ . '/../php/i18n.php';
require_once __DIR__ . '/../php/middleware.php';
if (!function_exists('ks_has_role')) {
  function ks_has_role(string ...$roles): bool {
    $t = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
    foreach ($roles as $r) if ($t === strtolower($r)) return true;
    return false;
  }
}


if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

// --- Dades bàsiques de sessió i rol ---
$seccio   = isset($seccio) ? (string)$seccio : ((string)($_GET['seccio'] ?? ''));
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$loginUrl   = BASE_PATH . 'index.php?modal=login&return=' . rawurlencode($currentUri);

$isLogged = !empty($_SESSION['loggedin']);
$role     = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
$isAdmin  = ($role === 'admin');
$isSala   = ($role === 'sala');
$isProductor = ($role === 'productor');

// (Opcional) Repara rol si s'ha perdut en sessió i tenim PDO
if ($isLogged && !$role && isset($pdo) && $pdo instanceof PDO && !empty($_SESSION['user_id'])) {
  try {
    $st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
    $st->execute([$_SESSION['user_id']]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $_SESSION['tipus_usuari'] = $row['Tipus_Usuari'];
      $role   = strtolower((string)$row['Tipus_Usuari']);
      $isAdmin = ($role === 'admin');
      $isSala  = ($role === 'sala');
      $isProductor = ($role === 'productor');
    }
  } catch (Throwable $e) { /* silent */ }
}

$isIndex = (basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === 'index.php');

if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Nom per al “Hola”
$displayName = '';
if ($isLogged) {
  $name  = trim((string)($_SESSION['user_name'] ?? ''));
  $email = trim((string)($_SESSION['user_email'] ?? ''));
  $displayName = $name !== '' ? $name : ($email !== '' ? $email : 'usuari');
}

?>
<header class="py-3 mb-4" id="home">
  <!-- MENÚ TOP DE PÀGINA -->
  <nav class="navbar navbar-expand-lg d-flex fixed-top shadow-sm navbar-dark" id="navbar-menu">
    <div class="container-fluid">
      <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h(BASE_PATH) ?>index.php">
        <img src="<?= h(BASE_PATH) ?>img/kinosonik_riders.svg" alt="Kinosonik Riders" style="height:28px;width:auto;">
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuHeader" aria-controls="menuHeader" aria-expanded="false" aria-label="Menú">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse justify-content-end" id="menuHeader">
        <ul class="navbar-nav align-items-lg-center column-gap-2">
          <?php if ($isLogged): ?>
            <?php if ($isAdmin): ?>
              <!-- ADMIN: USUARIS -->
              <li class="nav-item">
                <a class="nav-link <?= $seccio === 'usuaris' ? 'active' : '' ?>"
                   <?= $seccio === 'usuaris' ? 'aria-current="page"' : '' ?>
                   href="<?= h(BASE_PATH) ?>espai.php?seccio=usuaris">
                   <i class="bi bi-arrow-right-circle me-1"></i>
                  <?= __('nav.users') ?>
                </a>
              </li>
              <!-- ADMIN: RIDERS -->
              <li class="nav-item">
                <a class="nav-link <?= $seccio === 'admin_riders' ? 'active' : '' ?>"
                   <?= $seccio === 'admin_riders' ? 'aria-current="page"' : '' ?>
                   href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_riders">
                   <i class="bi bi-arrow-right-circle me-1"></i>
                  <?= __('nav.admin_riders') ?>
                </a>
              </li>
              <!-- ADMIN: ALTRES OPCIONS -->
              <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle"
                  href="#"
                  id="navAjudaDropdown"
                  role="button"
                  data-bs-toggle="dropdown"
                  aria-expanded="false">
                  <i class="bi bi-arrow-down-circle"></i> Altres
                </a>
                <ul class="dropdown-menu dropdown-kinosonik" aria-labelledby="navAjudaDropdown">
                  <li>
                    <a class="dropdown-item" href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_logs">
                      <i class="bi bi-robot me-1"></i> Logs IA
                    </a>
                  </li>
                  <li>
                    <a class="dropdown-item" href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_audit">
                      <i class="bi bi-journal-text me-1"></i> Auditoria Riders
                    </a>
                  </li>
                  <li>
                    <a class="dropdown-item" href="<?= h(BASE_PATH) ?>espai.php?seccio=ia_kpis">
                      <i class="bi bi-bar-chart me-1"></i> IA Kpis
                    </a>
                    <?php
                      $to   = (new DateTime('today'))->format('Y-m-d');
                      $from = (new DateTime('today -30 days'))->format('Y-m-d');
                      $exportHref = BASE_PATH.'php/admin/ia_export_csv.php?'.http_build_query([
                        'from'=>$from,'to'=>$to,'status'=>'ok'
                      ], '', '&', PHP_QUERY_RFC3986);
                    ?>
                  </li>
                  <li>
                    <a class="dropdown-item" href="<?= h($exportHref) ?>">
                      <i class="bi bi-filetype-csv me-1"></i> Export CSV (30 dies)
                    </a>
                  </li>
                </ul>
              </li>
            <?php endif; ?>
            <!-- RIDERS VISITATS (Tots els loguejats) -->
            <li class="nav-item">
              <a class="nav-link <?= ($seccio === 'rider_vistos') ? 'active' : '' ?>"
                href="<?= h(BASE_PATH) ?>rider_vistos.php">
                <i class="bi bi-rocket-takeoff me-1"></i>
                <?= __('nav.recent_riders') ?: 'Riders que he vist' ?>
              </a>
            </li>
            <!-- RIDERS SUBSCRITS (Tots els loguejats) -->
            <li class="nav-item">
              <a class="nav-link <?= ($seccio === 'rider_subscripcions') ? 'active' : '' ?>"
                href="<?= h(BASE_PATH) ?>rider_subscripcions.php">
                <i class="bi bi-bell me-1"></i>
                <?= __('nav.subs_riders') ?: 'Riders subscrits' ?>
              </a>
            </li>
            <!-- RIDERS (ocult per a 'sala' i 'productor' sense rol de tècnic) -->
            <?php if (!$isSala && ks_has_role('tecnic')): ?>
            <li class="nav-item">
              <a class="nav-link <?= $seccio === 'riders' ? 'active' : '' ?>"
                 <?= $seccio === 'riders' ? 'aria-current="page"' : '' ?>
                 href="<?= h(BASE_PATH) ?>espai.php?seccio=riders">
                 <i class="bi bi-archive me-1"></i>
                 <?= __('nav.your_riders') ?>
              </a>
            </li>
            <?php endif; ?>
            <!-- NOMÉS PER ADMINS I PRODUCTORS -->
            <?php if ($isAdmin || $isProductor): ?>
            <li class="nav-item">
              <a class="nav-link <?= $seccio === 'produccio' ? 'active' : '' ?>"
                 <?= $seccio === 'riders' ? 'aria-current="page"' : '' ?>
                 href="<?= h(BASE_PATH) ?>espai.php?seccio=produccio">
                 <i class="bi bi-gear-wide-connected me-1"></i>
                 <?= __('nav.produccio') ?>
              </a>
            </li>
            <?php endif ?>
            <!-- DADES PERSONALS (tots els usuaris) -->
            <li class="nav-item">
              <a class="nav-link <?= $seccio === 'dades' ? 'active' : '' ?>"
                 <?= $seccio === 'dades' ? 'aria-current="page"' : '' ?>
                 href="<?= h(BASE_PATH) ?>espai.php?seccio=dades">
                 <i class="bi bi-person-circle me-1"></i>
                <?= __('nav.your_data') ?>
              </a>
            </li>
            <!-- AJUDA (loguejats)-->
            <li class="nav-item">
              <a class="nav-link <?= $seccio === 'ajuda' ? 'active' : '' ?>"
                 <?= $seccio === 'ajuda' ? 'aria-current="page"' : '' ?>
                 href="<?= h(BASE_PATH) ?>espai.php?seccio=ajuda">
                 <i class="bi bi-question-circle me-1"></i>
                <?= __('nav.help') ?>
              </a>
            </li>
            <!-- Hola, Nom (loguejats) -->
            <li class="nav-item d-flex align-items-center ms-lg-2 border-start ps-3" style="border-color: rgba(255,255,255,.25);">
              <span class="text-body-tertiary">
                <?= __('nav.hello') ?>, <strong class="text-body"><?= h($displayName) ?></strong>
              </span>
            </li>
            <!-- Logout segur per POST + CSRF -->
            <li class="nav-item ms-lg-2">
              <form method="POST" action="<?= h(BASE_PATH) ?>php/logout.php" class="d-inline">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <button type="submit" class="btn btn-primary btn-sm"><?= __('nav.logout') ?></button>
              </form>
            </li>
            <!-- MENÚ SENSE LOGIN -->
            <?php else: ?>
            <li class="nav-item ms-lg-2">
              <?php if ($isIndex): ?>
                <!-- Som a index → modal -->
                <a class="btn btn-primary btn-sm" href="<?= h($loginUrl) ?>"><?= __('nav.login') ?></a>
              <?php else: ?>
                <!-- No som a index → redirigeix a index amb modal obert i retorn -->
                <a class="btn btn-primary btn-sm"
                   href="<?= h(BASE_PATH) ?>index.php?modal=login&return=<?= rawurlencode($currentUrl) ?>">
                  <?= __('nav.login') ?>
                </a>
              <?php endif; ?>
            </li>
          <?php endif; ?>
          <!-- SELECTOR IDIOMA -->
          <?php
          $currLang = strtolower((string)($_SESSION['lang'] ?? 'ca'));
          $langs = ['ca' => 'CA', 'es' => 'ES', 'en' => 'EN'];
          ?>
          <li class="nav-item ms-lg-3">
            <div class="lang-inline d-flex align-items-center">
              <?php $i = 0; foreach ($langs as $code => $label): $i++; ?>
              <form method="POST" action="<?= h(BASE_PATH) ?>php/set_lang.php" class="d-inline m-0 p-0">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="lang" value="<?= h($code) ?>">
                <button type="submit"
                  class="btn btn-link p-0 lang-link <?= $code === $currLang ? 'active' : '' ?>"
                  aria-current="<?= $code === $currLang ? 'true' : 'false' ?>">
                  <?= h($label) ?>
                </button>
              </form>
              <?php endforeach; ?>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </nav>
<?php
// Barra d’estat IA sota el navbar (només ADMIN)
// Només per a ADMIN, com ja feies
if (!empty($isAdmin)) {
  require __DIR__ . '/../php/ia_status.php';
}
?>
</header>