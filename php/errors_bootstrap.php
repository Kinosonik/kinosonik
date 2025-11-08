<?php
declare(strict_types=1);

// === CONFIG BÀSICA DE LOGGING ===
$KS_LOG_DIR  = '/var/config/logs/riders';
$KS_ERROR_LOG = $KS_LOG_DIR . '/php-error.log';

// Directori de logs: crea amb permisos restrictius
if (!is_dir($KS_LOG_DIR)) { @mkdir($KS_LOG_DIR, 0700, true); }
// Si comparteixes el pare amb altres, pots fer:
@chmod($KS_LOG_DIR, 0700);

// Precondicions bàsiques (sense fer soroll a l’usuari final)
// if (!is_dir($KS_LOG_DIR)) { @mkdir($KS_LOG_DIR, 0775, true); }
// if (!is_writable($KS_LOG_DIR)) {
  // Últim recurs: no trenquem la pàgina; registrem a log per defecte del FPM si cal
// }

// Configura el logging PHP
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $KS_ERROR_LOG);
date_default_timezone_set('Europe/Madrid');

// Handlers mínims i silenciosos (no trenquen capçalera)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
  if (!(error_reporting() & $errno)) return false; // respecta @
  error_log(sprintf('[PHP %d] %s:%d | %s', $errno, $errfile, $errline, $errstr));
  return true;
});
set_exception_handler(function(Throwable $e) {
  error_log(sprintf('[EXC %s] %s:%d | %s', get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()));
  // No fem echo (evitem "headers already sent"); la UI ja gestionarà missatges
});
register_shutdown_function(function() {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    error_log(sprintf('[FATAL %d] %s:%d | %s', $e['type'], $e['file'], $e['line'], $e['message']));
  }
});
// --- Opcional: rearmar handlers per evitar duplicats (després de carregar config.php)
if (!function_exists('ks_rearm_handlers')) {
  function ks_rearm_handlers(): void {
    // Torna a registrar els handlers del bootstrap (retornen TRUE)
    $KS_IP   = $_SERVER['REMOTE_ADDR']      ?? '-';
    $KS_URI  = ($_SERVER['REQUEST_METHOD']  ?? '-') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
    $KS_SID  = (PHP_SESSION_ACTIVE === session_status() && session_id() !== '') ? session_id() : '-';
    $KS_REQ_ID = bin2hex(random_bytes(4));
    $KS_CTX  = sprintf('[RID %s] [IP %s] [URI %s] [SID %s]', $KS_REQ_ID, $KS_IP, $KS_URI, $KS_SID);

    set_error_handler(function (int $errno, string $errstr, ?string $errfile = null, ?int $errline = null) use ($KS_CTX) {
      if (!(error_reporting() & $errno)) return false; // respecta @
      @error_log(sprintf('%s [PHP %d] %s:%d | %s', $KS_CTX, $errno, (string)$errfile, (int)$errline, $errstr));
      return true; // evita manejador per defecte => no duplicats
    });

    set_exception_handler(function (Throwable $e) use ($KS_CTX) {
      @error_log(sprintf('%s [EXC %s] %s:%d | %s', $KS_CTX, get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()));
    });

    register_shutdown_function(function () use ($KS_CTX) {
      $e = error_get_last();
      if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @error_log(sprintf('%s [FATAL %d] %s:%d | %s', $KS_CTX, $e['type'], $e['file'], $e['line'], $e['message']));
      }
    });
  }
}