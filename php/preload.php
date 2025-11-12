<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Madrid');

/* ---------------------------------------------------------
 * Sessió segura global (carregada des de preload.php)
 * --------------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE) {
    try {
        // Config segura...
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }

        session_name('KSSESSID');

        if (!session_start()) {
            error_log('[Riders] Error: no s’ha pogut iniciar la sessió (path o permisos).');
        }

        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }
    } catch (Throwable $e) {
        error_log('[Riders] Exception iniciant sessió: ' . $e->getMessage());
    }
}

// Marca que l'app s'ha carregat (per als includes amb guarda 403)
if (!defined('APP_LOADED')) {
  define('APP_LOADED', true);
}

// Subsistema d'errors i configuració global bàsica
require_once __DIR__ . '/errors_bootstrap.php';

// ── Secrets (amb checks i caché)
// define('KS_SECRET_EXPORT_ENV', true); // opcional: exporta a $_ENV/getenv()
$SECRETS = require __DIR__ . '/secret.php';

// ── Config i dependències globals
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/time_helpers.php';

// ── Constants & helpers compartits (inclou can_view_rider(), segells, etc.)
require_once __DIR__ . '/constants.php';

// Rearma handlers per unificar logging i evitar duplicats
if (function_exists('ks_rearm_handlers')) {
  ks_rearm_handlers();
}

// Helper dates
if (!function_exists('d_from_local')) {
  function d_from_local(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    // Accepta: 2025-11-07 17:30[:ss], 2025-11-07T17:30[:ss], o 2025-11-07
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
      return substr($s, 0, 10); // YYYY-MM-DD
    }
    return null; // si no casa, millor fallar explícitament
  }
}
if (!function_exists('d_from_input')) {
  /**
   * Normalitza un input de data (date o datetime-local) a 'YYYY-MM-DD'.
   * Accepta: 'YYYY-MM-DD', 'YYYY-MM-DDTHH:MM[:SS]', 'YYYY-MM-DD HH:MM[:SS]'.
   */
  function d_from_input(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
      return substr($s, 0, 10);
    }
    return null;
  }
}



/* helpers/errors.php (o dins preload.php si vols) */
function ks_error_page(int $code, array $vars = []): void {
  http_response_code($code);
  $vars['ERROR_ID'] = $vars['ERROR_ID'] ?? bin2hex(random_bytes(4));
  $vars['HOME_URL'] = $vars['HOME_URL'] ?? '/';
  extract($vars, EXTR_SKIP);

  $base = __DIR__ . '/errors'; // /riders/php/errors
  $map  = [
    401 => "$base/401.php",
    403 => "$base/403.php",
    404 => "$base/404.php",
    500 => "$base/500.html", // és HTML estàtic: fem include igualment
  ];
  $tpl = $map[$code] ?? "$base/404.php";
  if (is_file($tpl)) { require $tpl; }
  else { echo "Error $code"; }
  // exit desactivat temporalment
}

// Helper data/hora europeu (24 h)
if (!function_exists('dt_eu')) {
  /**
   * @param DateTimeInterface|string|null $dt
   * @param string $fmt
   */
  function dt_eu($dt, string $fmt = 'd/m/Y H:i'): string {
    if ($dt instanceof DateTimeInterface) { return $dt->format($fmt); }
    if (is_string($dt) && $dt !== '') {
      try { return (new DateTimeImmutable($dt))->format($fmt); } catch (Throwable $e) {}
    }
    return '—';
  }
}

// UUID v4 senzill per a correlació: request_id
if (empty($_SERVER['KS_REQUEST_ID'])) {
  $_SERVER['KS_REQUEST_ID'] = sprintf(
    '%04x%04x-%04x-4%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
  );
}

// Marca d’inici (si no la dóna PHP)
if (empty($_SERVER['REQUEST_TIME_FLOAT'])) {
  $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}