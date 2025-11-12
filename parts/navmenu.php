<?php
// parts/navmenu.php — versió optimitzada i segura (sense canvis visuals ni funcionals)
require_once __DIR__ . '/../php/i18n.php';
require_once __DIR__ . '/../php/middleware.php';

// ────────────────────────────────────────────────
// Funció auxiliar de rol amb micro-cache
// ────────────────────────────────────────────────
if (!function_exists('ks_has_role')) {
  function ks_has_role(string ...$roles): bool {
    static $cachedRole = null;
    if ($cachedRole === null) {
      $cachedRole = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
    }
    foreach ($roles as $r) {
      if ($cachedRole === strtolower($r)) return true;
    }
    return false;
  }
}

// ────────────────────────────────────────────────
// Sessió i CSRF
// ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
  try {
    session_start();
  } catch (Throwable $e) {
    error_log('[navmenu] Error iniciant sessió: ' . $e->getMessage());
  }
}
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ────────────────────────────────────────────────
// Variables bàsiques i rol d’usuari
// ────────────────────────────────────────────────
$seccio = isset($seccio) ? (string)$seccio : ((string)($_GET['seccio'] ?? ''));
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$loginUrl = BASE_PATH . 'index.php?modal=login&return=' . rawurlencode($currentUri);

$isLogged = !empty($_SESSION['loggedin']);
$role = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
$isAdmin = ($role === 'admin');
$isSala = ($role === 'sala');
$isProductor = ($role === 'productor');

// ────────────────────────────────────────────────
// Repara rol si falta a sessió
// ────────────────────────────────────────────────
if ($isLogged && !$role && isset($pdo) && $pdo instanceof PDO && !empty($_SESSION['user_id'])) {
  try {
    $st = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
    $st->execute([$_SESSION['user_id']]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $_SESSION['tipus_usuari'] = $row['Tipus_Usuari'];
      $role = strtolower((string)$row['Tipus_Usuari']);
      $isAdmin = ($role === 'admin');
      $isSala = ($role === 'sala');
      $isProductor = ($role === 'productor');
    }
  } catch (Throwable $e) {
    error_log('[navmenu] Error recuperant rol: ' . $e->getMessage());
  }
}

$isIndex = (basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === 'index.php');

// ────────────────────────────────────────────────
// Escapat i nom d’usuari
// ────────────────────────────────────────────────
if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

$displayName = '';
if ($isLogged) {
  $name = trim((string)($_SESSION['user_name'] ?? ''));
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
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuHeader"
        aria-controls="menuHeader" aria-expanded="false" aria-label="Menú">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse justify-content-end" id="menuHeader">
        <ul class="navbar-nav align-items-lg-center column-gap-2">

          <?php if ($isLogged): ?>

            <?php if ($isAdmin): ?>
              <!-- ADMIN: menú combinat "Altres" -->
              <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle"
                  href="#"
                  id="navAdminDropdown"
                  role="button"
                  data-bs-toggle="dropdown"
                  aria-expanded="false">
                  <i class="bi bi-gear me-2"></i>Admin
                </a>
                <ul class="dropdown-menu dropdown-kinosonik" aria-labelledby="navAdminDropdown">
                  <!-- Usuaris i Riders -->
                  <li>
                    <a class="dropdown-item <?= $seccio === 'usuaris' ? 'active' : '' ?>"
                      href="<?= h(BASE_PATH) ?>espai.php?seccio=usuaris">
                      <i class="bi bi-people me-2"></i> <?= __('nav.users') ?>
                    </a>
                  </li>
                  <li>
                    <a class="dropdown-item <?= $seccio === 'admin_riders' ? 'active' : '' ?>"
                      href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_riders">
                      <i class="bi bi-archive me-2"></i> <?= __('nav.admin_riders') ?>
                    </a>
                  </li>

                  <li><hr class="dropdown-divider"></li>

                  <!-- IA i auditoria -->
                  <li>
                    <a class="dropdown-item <?= $seccio === 'admin_logs' ? 'active' : '' ?>"
                      href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_logs">
                      <i class="bi bi-robot me-2"></i> Logs IA
                    </a>
                  </li>

                  <li>
                    <a class="dropdown-item <?= $seccio === 'admin_audit' ? 'active' : '' ?>"
                      href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_audit">
                      <i class="bi bi-journal-text me-2"></i> Auditoria Riders
                    </a>
                  </li>

                  <li>
                    <a class="dropdown-item <?= $seccio === 'ia_kpis' ? 'active' : '' ?>"
                      href="<?= h(BASE_PATH) ?>espai.php?seccio=ia_kpis">
                      <i class="bi bi-bar-chart me-2"></i> IA Kpis
                    </a>
                  </li>

                  <li><hr class="dropdown-divider"></li>

                  <!-- Exportació CSV -->
                  <?php
                    $to = (new DateTime('today'))->format('Y-m-d');
                    $from = (new DateTime('today -30 days'))->format('Y-m-d');
                    $exportHref = BASE_PATH.'php/admin/ia_export_csv.php?' .
                      http_build_query(['from'=>$from,'to'=>$to,'status'=>'ok'],'','&',PHP_QUERY_RFC3986);
                  ?>
                  <li>
                    <a class="dropdown-item <?= $seccio === 'admin_export' ? 'active' : '' ?>"
                      href="<?= h($exportHref) ?>">
                      <i class="bi bi-filetype-csv me-2"></i> Export CSV (30 dies)
                    </a>
                  </li>
                </ul>
              </li>
            <?php endif; ?>

            <!-- RIDERS VISITATS (Tots els loguejats) -->
            <li class="nav-item">
              <a class="nav-link <?= $seccio === 'rider_vistos' ? 'active' : '' ?>"
                 aria-current="<?= $seccio === 'rider_vistos' ? 'page' : 'false' ?>"
                 href="<?= h(BASE_PATH) ?>rider_vistos.php">
                 <i class="bi bi-rocket-takeoff me-2"></i><?= __('nav.recent_riders') ?: 'Riders que he vist' ?>
              </a>
            </li>

            <!-- RIDERS SUBSCRITS (Tots els loguejats) -->
            <li class="nav-item">
              <a class="nav-link <?= $seccio === 'rider_subscripcions' ? 'active' : '' ?>"
                 aria-current="<?= $seccio === 'rider_subscripcions' ? 'page' : 'false' ?>"
                 href="<?= h(BASE_PATH) ?>rider_subscripcions.php">
                 <i class="bi bi-bell me-2"></i><?= __('nav.subs_riders') ?: 'Riders subscrits' ?>
              </a>
            </li>

            <!-- RIDERS (ocult per a 'sala' i 'productor' sense rol de tècnic) -->
            <?php if (!$isSala && ks_has_role('tecnic')): ?>
              <li class="nav-item">
                <a class="nav-link <?= $seccio === 'riders' ? 'active' : '' ?>"
                   aria-current="<?= $seccio === 'riders' ? 'page' : 'false' ?>"
                   href="<?= h(BASE_PATH) ?>espai.php?seccio=riders">
                   <i class="bi bi-archive me-2"></i><?= __('nav.your_riders') ?>
                </a>
              </li>
            <?php endif; ?>

            <!-- NOMÉS PER ADMINS I PRODUCTORS
            <?php if ($isAdmin || $isProductor): ?>
              <li class="nav-item">
                <a class="nav-link <?= $seccio === 'produccio' ? 'active' : '' ?>"
                   aria-current="<?= $seccio === 'produccio' ? 'page' : 'false' ?>"
                   href="<?= h(BASE_PATH) ?>espai.php?seccio=produccio">
                   <i class="bi bi-gear-wide-connected me-2"></i><?= __('nav.produccio') ?>
                </a>
              </li>
            <?php endif; ?>-->

            <!-- DADES PERSONALS -->
            <li class="nav-item">
              <a class="nav-link <?= $seccio === 'dades' ? 'active' : '' ?>"
                 aria-current="<?= $seccio === 'dades' ? 'page' : 'false' ?>"
                 href="<?= h(BASE_PATH) ?>espai.php?seccio=dades">
                 <i class="bi bi-person-circle me-2"></i><?= __('nav.your_data') ?>
              </a>
            </li>

            <!-- AJUDA -->
            <li class="nav-item">
              <a class="nav-link <?= $seccio === 'ajuda' ? 'active' : '' ?>"
                 aria-current="<?= $seccio === 'ajuda' ? 'page' : 'false' ?>"
                 href="<?= h(BASE_PATH) ?>espai.php?seccio=ajuda">
                 <i class="bi bi-question-circle me-2"></i><?= __('nav.help') ?>
              </a>
            </li>

            <!-- Hola, Nom + Logout -->
            <li class="nav-item d-flex align-items-center ms-lg-2 border-start ps-3" style="border-color:rgba(255,255,255,.25);">
              <span class="text-body-tertiary"><?= __('nav.hello') ?>, <strong class="text-body"><?= h($displayName) ?></strong></span>
            </li>
            <li class="nav-item ms-lg-2">
              <form method="POST" action="<?= h(BASE_PATH) ?>php/logout.php" class="d-inline">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <button type="submit" class="btn btn-primary btn-sm">
                  <i class="bi bi-arrow-right-square me-2"></i><?= __('nav.logout') ?>
                </button>
              </form>
            </li>

          <?php else: ?>
            <!-- MENÚ SENSE LOGIN -->
            <li class="nav-item ms-lg-2">
              <a class="btn btn-primary btn-sm" href="<?= h(BASE_PATH) ?>index.php?modal=login&return=<?= rawurlencode($currentUrl) ?>">
                <i class="bi bi-arrow-right-square-fill me-2"></i><?= __('nav.login') ?>
              </a>
            </li>
          <?php endif; ?>

          <!-- SELECTOR D’IDIOMA -->
          <?php
          $currLang = strtolower((string)($_SESSION['lang'] ?? 'ca'));
          $langs = ['ca' => 'CA', 'es' => 'ES', 'en' => 'EN'];
          ?>
          <li class="nav-item ms-lg-3">
            <div class="lang-inline d-flex align-items-center">
              <?php foreach ($langs as $code => $label): ?>
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
  if (!empty($isAdmin)) {
    require __DIR__ . '/../php/ia_status.php';
  }
  ?>
</header>
