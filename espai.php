<?php
// espai.php — Àrea privada d'usuari
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/check_login.php';
require_once __DIR__ . '/php/messages.php';
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';

// CSRF “lazy”
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Helper d'escapat
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* --------------------------------------------------------------------------
 * Short-circuits d'export (abans de qualsevol output)
 * -------------------------------------------------------------------------- */
$seccioQS = (string)($_GET['seccio'] ?? '');
if ($seccioQS === 'admin_audit' && isset($_GET['export'])) {
  require __DIR__ . '/php/admin/admin_audit.php';
  exit;
}

/* --------------------------------------------------------------------------
 * Router i seccions
 * -------------------------------------------------------------------------- */
$routes = [
  'dades'        => 'dades.php',
  'riders'       => 'riders.php',
  'analitza'     => 'ai_progress.php',
  'usuaris'      => 'admin_usuaris.php',
  'admin_riders' => 'admin_riders.php',
  'admin_logs'   => 'php/admin/admin_logs.php',
  'admin_audit'  => 'php/admin/admin_audit.php',
  'ia_detail'    => 'php/ia_detail.php',
  'ia_kpis'      => 'php/admin/ia_kpis.php',
  'ajuda'        => 'ajuda.php',
  'produccio'    => 'produccio.php',
];

$allowedSections = array_keys($routes);

// Normalitza 'seccio'
$seccio = isset($_GET['seccio']) ? (string)$_GET['seccio'] : 'dades';
$seccio = preg_replace('/[^a-z0-9_-]/i', '', $seccio);
if (!in_array($seccio, $allowedSections, true)) {
  $seccio = 'dades';
}

// Query params per banners
$qs_success = isset($_GET['success']) ? preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['success']) : '';
$qs_error   = isset($_GET['error'])   ? preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['error'])   : '';
$qs_modal   = isset($_GET['modal'])   ? preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['modal'])   : '';

/* --------------------------------------------------------------------------
 * Permisos
 * -------------------------------------------------------------------------- */
$isAdmin = strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') === 0;

// Seccions exclusives d'admin
$adminSections = ['usuaris', 'admin_riders', 'admin_logs', 'admin_audit', 'ia_kpis'];

// Ruta física
$view = $routes[$seccio] ?? 'dades.php';

// Protegir rutes d'admin
if (!$isAdmin && in_array($seccio, $adminSections, true)) {
  $view = null; // bloqueja la vista al render
}

/* --------------------------------------------------------------------------
 * Permisos per rol "sala"
 * -------------------------------------------------------------------------- */
$role = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));
if ($role === 'sala') {
  // Seccions permeses dins l'espai privat per als usuaris de tipus "sala"
  $salaAllowed = ['dades', 'ajuda' /*, 'riders_publics' si s'implementa */];

  if (!in_array($seccio, $salaAllowed, true)) {
    // Redirigeix a l'arrel (mateix comportament que post-login)
    header('Location: ' . BASE_PATH . 'index.php');
    exit;
  }
}

// Export CSV d'admin_logs (cas especial)
if ($seccio === 'admin_logs' && (($_GET['export'] ?? '') === 'csv')) {
  if (!$isAdmin) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
  require __DIR__ . '/php/admin/admin_logs.php';
  exit;
}

/* --------------------------------------------------------------------------
 * Render
 * -------------------------------------------------------------------------- */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>

<?php
// --- FLASH per sessió (p.ex. ai_analyze.php) ---
$flash = $_SESSION['flash'] ?? null;
if ($flash) {
  unset($_SESSION['flash']); // evita repeticions
  $type  = $flash['type']  ?? 'info';      // success | error | warning | info
  $key   = $flash['key']   ?? '';
  $extra = $flash['extra'] ?? [];

  // Intenta resoldre text via messages.php
  $dict = (isset($messages[$type]) && is_array($messages[$type])) ? $messages[$type] : [];
  $text = (string)($dict[$key] ?? '');

  // Substitucions simples {score}, etc.
  if ($text && $extra) {
    foreach ($extra as $k => $v) {
      $text = str_replace('{'.$k.'}', (string)$v, $text);
    }
  }

  // Fallbacks mínims
  if ($text === '') {
    if ($key === 'ai_scored') {
      $text = 'Anàlisi feta. Puntuació: ' . (int)($extra['score'] ?? 0) . '/100';
    } elseif ($key === 'ai_locked_state') {
      $text = 'Aquest rider ja és definitiu (validat o caducat).';
    } else {
      $text = htmlspecialchars($key ?: 'OK', ENT_QUOTES, 'UTF-8');
    }
  }

  $map = ['success'=>'success','error'=>'danger','warning'=>'warning','info'=>'info'];
  $alert = $map[$type] ?? 'secondary';
  echo '<div class="container my-2" style="max-width:900px;">'
     . '  <div class="alert alert-'.$alert.' alert-dismissible fade show shadow-sm small mb-3" role="alert">'
     .        $text
     . '    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tanca"></button>'
     . '  </div>'
     . '</div>';
}

// Banner d’èxit/error quan no és 'dades'
if ($seccio !== 'dades' && ($qs_success !== '' || $qs_error !== '')):
    $type = ($qs_error !== '') ? 'error' : 'success';
    $key  = ($type === 'error') ? $qs_error : $qs_success;

    $dict = (isset($messages[$type]) && is_array($messages[$type])) ? $messages[$type] : [];
    $text = (string)($dict[$key] ?? ($dict['default'] ?? ''));

    if ($text !== ''):
      $alertClass = ($type === 'error') ? 'danger' : 'success';
?>
  <div class="container mb-3" style="max-width:600px;">
    <div class="alert alert-<?= h($alertClass) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= h($text) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= h(t('close')) ?>"></button>
    </div>
  </div>
<?php
    endif;
endif;
?>

<div class="container-fluid mx-auto" data-help-key="<?= h($seccio) ?>">
  <?php
    if ($view === null) {
      http_response_code(403);
      echo '<div class="alert alert-danger my-3">No tens permisos per accedir a aquesta secció.</div>';
    } else {
      // Carrega la vista corresponent
      // (els dos llistats d'admin poden carregar-se directament)
      if ($seccio === 'usuaris') {
        require __DIR__ . '/admin_usuaris.php';
      } elseif ($seccio === 'admin_riders') {
        require __DIR__ . '/admin_riders.php';
      } else {
        require __DIR__ . '/' . $view;
      }
    }
  ?>
</div>

<?php require_once __DIR__ . '/parts/footer.php'; ?>

<script>
// Auto-dismiss del banner (si n'hi ha)
document.addEventListener('DOMContentLoaded', () => {
  const el = document.querySelector('.alert.alert-dismissible');
  if (!el) return;
  setTimeout(() => {
    bootstrap.Alert.getOrCreateInstance(el).close();
  }, 4000);
});
</script>